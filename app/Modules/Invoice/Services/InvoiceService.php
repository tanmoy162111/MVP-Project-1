<?php

namespace App\Modules\Invoice\Services;

use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\InvoiceSequence;
use App\Modules\Order\Models\Order;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    /**
     * Auto-generate an invoice when an order is confirmed.
     * Called by OrderService or an event listener on STATUS_CONFIRMED.
     * Idempotent — returns existing invoice if one already exists for this order.
     */
    public function generateFromOrder(Order $order): Invoice
    {
        // Idempotency guard
        $existing = Invoice::where('order_id', $order->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($order) {
            $order->load(['items.vendor', 'customer']);

            // Build line items snapshot from order items
            $lineItems = $order->items->map(fn($item) => [
                'product_id'   => $item->product_id,
                'variant_id'   => $item->variant_id,
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'sku'          => $item->sku,
                'quantity'     => $item->quantity,
                'unit_price'   => $item->unit_price,
                'total_price'  => $item->total_price,
                'vendor_name'  => $item->vendor->store_name ?? '',
            ])->toArray();

            // Resolve payment terms from customer contract or default
            $paymentTerms = $this->resolvePaymentTerms($order->customer, $order->payment_method);
            $dueAt        = $this->calculateDueDate($paymentTerms);

            $invoice = Invoice::create([
                'invoice_number'  => $this->nextInvoiceNumber($order->items->first()?->vendor_id ?? 0),
                'order_id'        => $order->id,
                'customer_id'     => $order->customer_id,
                'vendor_id'       => $order->items->first()?->vendor_id,
                'status'          => Invoice::STATUS_ISSUED,
                'currency'        => 'BDT',
                'subtotal'        => $order->subtotal,
                'discount_amount' => $order->discount_amount,
                'tax_amount'      => $order->tax_amount,
                'freight_cost'    => $order->freight_cost,
                'total_amount'    => $order->total_amount,
                'amount_paid'     => 0,
                'balance_due'     => $order->total_amount,
                'line_items'      => $lineItems,
                'billing_address' => $order->shipping_address,
                'payment_terms'   => $paymentTerms,
                'issued_at'       => now(),
                'due_at'          => $dueAt,
                'is_locked'       => false,
            ]);

            return $invoice;
        });
    }

    /**
     * Apply a payment to an invoice.
     * Handles partial payments — recalculates balance_due and updates status.
     */
    public function applyPayment(Invoice $invoice, float $amount, string $transactionId, string $method, array $gatewayRaw = []): Invoice
    {
        if ($invoice->isLocked() && $invoice->isPaid()) {
            throw new \RuntimeException("Invoice {$invoice->invoice_number} is already paid and locked.");
        }

        return DB::transaction(function () use ($invoice, $amount, $transactionId, $method, $gatewayRaw) {
            // Create payment record
            \App\Modules\Invoice\Models\Payment::create([
                'invoice_id'       => $invoice->id,
                'order_id'         => $invoice->order_id,
                'customer_id'      => $invoice->customer_id,
                'method'           => $method,
                'status'           => \App\Modules\Invoice\Models\Payment::STATUS_COMPLETED,
                'currency'         => 'BDT',
                'amount'           => $amount,
                'transaction_id'   => $transactionId,
                'gateway_reference'=> 'INV-' . $invoice->invoice_number,
                'gateway_response' => $gatewayRaw,
                'processed_at'     => now(),
            ]);

            // Update invoice amounts
            $newAmountPaid = (float) $invoice->amount_paid + $amount;
            $newBalance    = max(0, (float) $invoice->total_amount - $newAmountPaid);

            $newStatus = $newBalance <= 0
                ? Invoice::STATUS_PAID
                : Invoice::STATUS_PARTIAL;

            $invoice->update([
                'amount_paid' => $newAmountPaid,
                'balance_due' => $newBalance,
                'status'      => $newStatus,
                'paid_at'     => $newBalance <= 0 ? now() : null,
                'is_locked'   => $newBalance <= 0,
            ]);

            return $invoice->fresh();
        });
    }

    /**
     * Mark all issued/partial invoices whose due_at has passed as overdue.
     * Called by a scheduled job (console command).
     *
     * @return int Number of invoices marked overdue
     */
    public function markOverdueInvoices(): int
    {
        $count = Invoice::unpaid()
            ->where('due_at', '<', now())
            ->whereNotIn('status', [Invoice::STATUS_OVERDUE])
            ->update(['status' => Invoice::STATUS_OVERDUE]);

        Log::info("InvoiceService: marked {$count} invoices as overdue.");

        return $count;
    }

    /**
     * Void an invoice (admin only). Cannot void a paid invoice.
     */
    public function void(Invoice $invoice, User $admin, string $reason): Invoice
    {
        if ($invoice->isPaid()) {
            throw new \RuntimeException('Cannot void a paid invoice. Issue a credit note instead.');
        }

        $invoice->update([
            'status'    => Invoice::STATUS_VOID,
            'notes'     => ($invoice->notes ? $invoice->notes . "\n" : '') . "Voided by {$admin->name}: {$reason}",
            'is_locked' => true,
        ]);

        return $invoice->fresh();
    }

    /**
     * Generate PDF for an invoice and store path.
     *
     * Uses DomPDF (already in composer.json from Phase 1).
     * Returns the stored file path.
     */
    public function generatePdf(Invoice $invoice): string
    {
        $invoice->load(['customer', 'order.items']);

        $html = view('invoices.pdf', ['invoice' => $invoice])->render();

        $pdf  = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait');

        $filename = "invoices/{$invoice->invoice_number}.pdf";
        $path     = storage_path("app/public/{$filename}");

        // Ensure directory exists
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $pdf->save($path);

        $invoice->update(['pdf_path' => $filename]);

        return $filename;
    }

    // ── PRIVATE HELPERS ───────────────────────────────────────────────────────

    private function nextInvoiceNumber(int $vendorId): string
    {
        $year = now()->year;

        $sequence = InvoiceSequence::lockForUpdate()->firstOrCreate(
            ['vendor_id' => $vendorId, 'year' => $year],
            ['prefix' => 'INV', 'last_sequence' => 0]
        );

        return $sequence->nextNumber();
    }

    private function resolvePaymentTerms(User $customer, ?string $paymentMethod): string
    {
        // COD and bKash are due immediately
        if (in_array($paymentMethod, ['cod', 'bkash', 'nagad', 'sslcommerz'], true)) {
            return 'net_0';
        }

        // Check active contract for payment terms
        $contract = \App\Modules\Pricing\Models\CustomerContract::activeNow()
            ->where('customer_id', $customer->id)
            ->whereNotNull('payment_terms')
            ->first();

        return $contract?->payment_terms ?? 'net_30'; // PLACEHOLDER default
    }

    private function calculateDueDate(string $terms): \Carbon\Carbon
    {
        $days = match ($terms) {
            'net_0'  => 0,
            'net_7'  => 7,
            'net_15' => 15,
            'net_30' => 30,
            'net_60' => 60,
            'cod'    => 0,
            default  => 30, // PLACEHOLDER default
        };

        return now()->addDays($days);
    }
}
