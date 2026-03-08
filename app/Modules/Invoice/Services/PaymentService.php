<?php

namespace App\Modules\Invoice\Services;

use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\Invoice\Services\Gateways\PaymentGatewayInterface;
use App\Modules\Invoice\Services\Gateways\MockPaymentGateway;
use App\Modules\Invoice\Services\Gateways\BkashGateway;
use App\Modules\Invoice\Services\Gateways\SslCommerzGateway;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PaymentService
 *
 * Resolves the correct gateway adapter and orchestrates the
 * initiate → callback verify → apply-to-invoice flow.
 *
 * PLACEHOLDER gateways (live when credentials are set in .env):
 *   bkash      → BKASH_APP_KEY, BKASH_APP_SECRET, BKASH_USERNAME, BKASH_PASSWORD
 *   sslcommerz → SSLCOMMERZ_STORE_ID, SSLCOMMERZ_STORE_PASSWD
 *   nagad      → Phase 6 (credentials not yet requested)
 *
 * Until credentials are provided, all non-COD/credit gateways
 * fall back to MockPaymentGateway automatically.
 */
class PaymentService
{
    public function __construct(private InvoiceService $invoiceService) {}

    /**
     * Initiate a payment for an invoice.
     * Returns redirect URL for gateway checkout pages (bKash, SSLCommerz),
     * or a token for inline confirmation (COD, credit).
     */
    public function initiate(Invoice $invoice, string $method, User $customer): array
    {
        $gateway   = $this->resolveGateway($method);
        $reference = $this->generateReference($invoice);

        $meta = [
            'customer_id'    => $customer->id,
            'customer_name'  => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => $customer->phone ?? '',
            'address'        => $invoice->billing_address['line1'] ?? '',
            'city'           => $invoice->billing_address['city'] ?? 'Dhaka',
            'invoice_id'     => $invoice->id,
        ];

        $result = $gateway->initiate(
            (float) $invoice->balance_due,
            'BDT',
            $reference,
            $meta
        );

        // Record pending payment
        if ($result['success']) {
            Payment::create([
                'invoice_id'        => $invoice->id,
                'order_id'          => $invoice->order_id,
                'customer_id'       => $customer->id,
                'method'            => $gateway->getName(),
                'status'            => Payment::STATUS_PENDING,
                'currency'          => 'BDT',
                'amount'            => $invoice->balance_due,
                'transaction_id'    => $result['token'] ?? $reference,
                'gateway_reference' => $reference,
                'gateway_response'  => ['initiated_at' => now()->toISOString()],
            ]);
        }

        return array_merge($result, [
            'gateway'   => $gateway->getName(),
            'is_mock'   => $gateway instanceof MockPaymentGateway,
            'reference' => $reference,
        ]);
    }

    /**
     * Handle gateway callback / webhook.
     * Verifies the payment with the gateway, then applies it to the invoice.
     */
    public function handleCallback(string $method, string $transactionId, array $callbackData): array
    {
        $gateway = $this->resolveGateway($method);
        $result  = $gateway->verify($transactionId, $callbackData);

        if (! $result['success']) {
            Log::warning("Payment verification failed [{$method}] txn={$transactionId}: {$result['message']}");

            // Mark pending payment as failed
            Payment::where('transaction_id', $transactionId)
                ->where('status', Payment::STATUS_PENDING)
                ->update(['status' => Payment::STATUS_FAILED, 'gateway_response' => $result['raw'] ?? []]);

            return ['success' => false, 'message' => $result['message']];
        }

        // Find the invoice via gateway_reference stored during initiation
        $pending = Payment::where('transaction_id', $transactionId)
            ->where('status', Payment::STATUS_PENDING)
            ->with('invoice')
            ->first();

        if (! $pending || ! $pending->invoice) {
            Log::error("Payment callback: no pending payment found for txn={$transactionId}");
            return ['success' => false, 'message' => 'Payment record not found.'];
        }

        $invoice = $this->invoiceService->applyPayment(
            $pending->invoice,
            $result['amount'] > 0 ? $result['amount'] : (float) $pending->amount,
            $result['transaction_id'] ?? $transactionId,
            $gateway->getName(),
            $result['raw'] ?? []
        );

        return [
            'success'        => true,
            'invoice_status' => $invoice->status,
            'balance_due'    => $invoice->balance_due,
            'message'        => 'Payment recorded successfully.',
        ];
    }

    /**
     * COD confirmation — mark invoice paid when goods are delivered.
     * Called by order manager when order transitions to DELIVERED.
     */
    public function confirmCod(Invoice $invoice, User $actor): Invoice
    {
        return $this->invoiceService->applyPayment(
            $invoice,
            (float) $invoice->balance_due,
            'COD-' . strtoupper(substr(md5($invoice->id . now()), 0, 10)),
            Payment::METHOD_COD,
            ['confirmed_by' => $actor->id, 'method' => 'cod']
        );
    }

    /**
     * Process a refund against a completed payment.
     */
    public function refund(Payment $payment, float $amount, User $admin): array
    {
        if (! $payment->isCompleted()) {
            return ['success' => false, 'message' => 'Only completed payments can be refunded.'];
        }

        $gateway = $this->resolveGateway($payment->method);
        $result  = $gateway->refund($payment->transaction_id, $amount);

        if ($result['success']) {
            $payment->update([
                'status'           => Payment::STATUS_REFUNDED,
                'gateway_response' => array_merge(
                    (array) $payment->gateway_response,
                    ['refund' => $result, 'refunded_by' => $admin->id, 'refunded_at' => now()]
                ),
            ]);

            Log::info("Refund processed for payment #{$payment->id}: " . json_encode($result));
        }

        return $result;
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────────

    /**
     * Resolve the correct gateway.
     * If gateway credentials aren't configured, returns MockPaymentGateway.
     */
    private function resolveGateway(string $method): PaymentGatewayInterface
    {
        return match ($method) {
            'bkash'      => new BkashGateway(),
            'sslcommerz' => new SslCommerzGateway(),
            default      => new MockPaymentGateway(), // cod, credit_account, bank_transfer, mock
        };
    }

    private function generateReference(Invoice $invoice): string
    {
        return 'PAY-' . $invoice->invoice_number . '-' . now()->format('His');
    }
}
