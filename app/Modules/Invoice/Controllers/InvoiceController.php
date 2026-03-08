<?php

namespace App\Modules\Invoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\Invoice\Services\InvoiceService;
use App\Modules\Invoice\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InvoiceController extends Controller
{
    public function __construct(
        private InvoiceService $invoiceService,
        private PaymentService $paymentService,
    ) {}

    // ── INVOICES ──────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/invoices
     * Customer: own invoices. Admin/finance: all with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = Invoice::with(['customer:id,name,email,company_name', 'order:id,order_number'])
            ->when(! $user->hasRole(['admin', 'super_admin', 'finance_manager']),
                fn($q) => $q->where('customer_id', $user->id)
            )
            ->when($request->status,      fn($q, $v) => $q->where('status', $v))
            ->when($request->customer_id, fn($q, $v) => $q->where('customer_id', $v))
            ->when($request->overdue,     fn($q)     => $q->overdue())
            ->when($request->from,        fn($q, $v) => $q->whereDate('issued_at', '>=', $v))
            ->when($request->to,          fn($q, $v) => $q->whereDate('issued_at', '<=', $v))
            ->latest('issued_at');

        return $this->paginated($query->paginate($request->integer('per_page', 20)));
    }

    /**
     * GET /api/v1/invoices/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user    = $request->user();
        $invoice = Invoice::with(['customer:id,name,email,phone,company_name', 'payments', 'order:id,order_number,status'])
            ->findOrFail($id);

        if (! $user->hasRole(['admin', 'super_admin', 'finance_manager']) && $invoice->customer_id !== $user->id) {
            return $this->forbidden();
        }

        return $this->success($this->formatInvoice($invoice, detailed: true));
    }

    /**
     * GET /api/v1/invoices/{id}/pdf
     * Downloads the invoice as a PDF. Generates on first request, cached after.
     */
    public function downloadPdf(Request $request, int $id): Response|JsonResponse
    {
        $user    = $request->user();
        $invoice = Invoice::with(['customer', 'order.items'])->findOrFail($id);

        if (! $user->hasRole(['admin', 'super_admin', 'finance_manager']) && $invoice->customer_id !== $user->id) {
            return $this->forbidden();
        }

        try {
            if (! $invoice->pdf_path || ! file_exists(storage_path("app/public/{$invoice->pdf_path}"))) {
                $this->invoiceService->generatePdf($invoice);
                $invoice->refresh();
            }

            $path    = storage_path("app/public/{$invoice->pdf_path}");
            $filename = "Invoice-{$invoice->invoice_number}.pdf";

            return response()->download($path, $filename, ['Content-Type' => 'application/pdf']);

        } catch (\Throwable $e) {
            return $this->badRequest("PDF generation failed: {$e->getMessage()}");
        }
    }

    /**
     * POST /api/v1/admin/invoices/{id}/void
     */
    public function void(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'required|string|min:5']);
        $invoice = Invoice::findOrFail($id);

        try {
            $invoice = $this->invoiceService->void($invoice, $request->user(), $request->reason);
            return $this->success($this->formatInvoice($invoice), 'Invoice voided.');
        } catch (\RuntimeException $e) {
            return $this->badRequest($e->getMessage());
        }
    }

    /**
     * POST /api/v1/admin/invoices/mark-overdue
     * Manually trigger overdue marking (normally runs on schedule).
     */
    public function markOverdue(): JsonResponse
    {
        $count = $this->invoiceService->markOverdueInvoices();
        return $this->success(['marked_overdue' => $count], "{$count} invoices marked overdue.");
    }

    // ── PAYMENTS ──────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/payments/initiate
     * Customer initiates payment for an invoice.
     */
    public function initiatePayment(Request $request): JsonResponse
    {
        $request->validate([
            'invoice_id' => 'required|integer|exists:invoices,id',
            'method'     => 'required|in:bkash,nagad,sslcommerz,bank_transfer,cod,credit_account,mock',
        ]);

        $invoice = Invoice::findOrFail($request->invoice_id);

        if ($invoice->customer_id !== $request->user()->id) {
            return $this->forbidden();
        }

        if ($invoice->isPaid()) {
            return $this->badRequest('This invoice is already paid.');
        }

        $result = $this->paymentService->initiate($invoice, $request->method, $request->user());

        $statusCode = $result['success'] ? 200 : 422;

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data'    => [
                'gateway'      => $result['gateway'],
                'redirect_url' => $result['redirect_url'] ?? null,
                'token'        => $result['token'] ?? null,
                'reference'    => $result['reference'],
                'is_mock'      => $result['is_mock'] ?? false,
                'notice'       => ($result['is_mock'] ?? false)
                    ? 'Using mock payment gateway. Provide gateway credentials in .env to enable live payments.'
                    : null,
            ],
        ], $statusCode);
    }

    /**
     * POST /api/v1/payments/bkash/callback
     * POST /api/v1/payments/sslcommerz/success
     * Gateway POST-back after payment.
     */
    public function gatewayCallback(Request $request, string $gateway): JsonResponse
    {
        $transactionId = $request->input('paymentID')      // bKash
                      ?? $request->input('tran_id')        // SSLCommerz
                      ?? $request->input('transaction_id') // generic
                      ?? '';

        $result = $this->paymentService->handleCallback($gateway, $transactionId, $request->all());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data'    => $result,
        ], $result['success'] ? 200 : 422);
    }

    /**
     * POST /api/v1/admin/payments/{id}/refund
     */
    public function refund(Request $request, int $id): JsonResponse
    {
        $request->validate(['amount' => 'required|numeric|min:0.01']);

        $payment = Payment::with('invoice')->findOrFail($id);
        $result  = $this->paymentService->refund($payment, (float) $request->amount, $request->user());

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data'    => $result,
        ], $result['success'] ? 200 : 422);
    }

    /**
     * GET /api/v1/admin/payments
     * Admin payment listing with filters.
     */
    public function paymentIndex(Request $request): JsonResponse
    {
        $payments = Payment::with(['invoice:id,invoice_number', 'customer:id,name,email'])
            ->when($request->method, fn($q, $v) => $q->where('method', $v))
            ->when($request->status, fn($q, $v) => $q->where('status', $v))
            ->when($request->customer_id, fn($q, $v) => $q->where('customer_id', $v))
            ->when($request->from, fn($q, $v) => $q->whereDate('processed_at', '>=', $v))
            ->when($request->to,   fn($q, $v) => $q->whereDate('processed_at', '<=', $v))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginated($payments);
    }

    /**
     * GET /api/v1/admin/payments/gateway-status
     * Shows which gateways are configured vs placeholder.
     */
    public function gatewayStatus(): JsonResponse
    {
        $bkashConfigured = config('payment.bkash.app_key', 'PLACEHOLDER') !== 'PLACEHOLDER';
        $sslConfigured   = config('payment.sslcommerz.store_id', 'PLACEHOLDER') !== 'PLACEHOLDER';

        return $this->success([
            'gateways' => [
                [
                    'name'        => 'bKash',
                    'key'         => 'bkash',
                    'configured'  => $bkashConfigured,
                    'status'      => $bkashConfigured ? 'live' : 'mock',
                    'credentials_needed' => ! $bkashConfigured ? [
                        'BKASH_APP_KEY'    => '← PLACEHOLDER',
                        'BKASH_APP_SECRET' => '← PLACEHOLDER',
                        'BKASH_USERNAME'   => '← PLACEHOLDER',
                        'BKASH_PASSWORD'   => '← PLACEHOLDER',
                    ] : null,
                ],
                [
                    'name'        => 'SSLCommerz',
                    'key'         => 'sslcommerz',
                    'configured'  => $sslConfigured,
                    'status'      => $sslConfigured ? 'live' : 'mock',
                    'credentials_needed' => ! $sslConfigured ? [
                        'SSLCOMMERZ_STORE_ID'     => '← PLACEHOLDER',
                        'SSLCOMMERZ_STORE_PASSWD' => '← PLACEHOLDER',
                    ] : null,
                ],
                [
                    'name'       => 'Mock',
                    'key'        => 'mock',
                    'configured' => true,
                    'status'     => 'active',
                    'note'       => 'Always available — auto-used when real gateway not configured.',
                ],
            ],
        ]);
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────────

    private function formatInvoice(Invoice $invoice, bool $detailed = false): array
    {
        $base = [
            'id'             => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status'         => $invoice->status,
            'currency'       => $invoice->currency,
            'subtotal'       => $invoice->subtotal,
            'discount_amount'=> $invoice->discount_amount,
            'tax_amount'     => $invoice->tax_amount,
            'freight_cost'   => $invoice->freight_cost,
            'total_amount'   => $invoice->total_amount,
            'amount_paid'    => $invoice->amount_paid,
            'balance_due'    => $invoice->balance_due,
            'payment_terms'  => $invoice->payment_terms,
            'issued_at'      => $invoice->issued_at,
            'due_at'         => $invoice->due_at,
            'paid_at'        => $invoice->paid_at,
            'is_overdue'     => $invoice->isOverdue(),
            'is_locked'      => $invoice->is_locked,
        ];

        if (! $detailed) return $base;

        return array_merge($base, [
            'line_items'      => $invoice->line_items,
            'billing_address' => $invoice->billing_address,
            'notes'           => $invoice->notes,
            'pdf_path'        => $invoice->pdf_path,
            'customer'        => $invoice->relationLoaded('customer') ? [
                'id'           => $invoice->customer->id,
                'name'         => $invoice->customer->name,
                'email'        => $invoice->customer->email,
                'phone'        => $invoice->customer->phone,
                'company_name' => $invoice->customer->company_name,
            ] : null,
            'order' => $invoice->relationLoaded('order') ? [
                'id'           => $invoice->order->id,
                'order_number' => $invoice->order->order_number,
                'status'       => $invoice->order->status,
            ] : null,
            'payments' => $invoice->relationLoaded('payments')
                ? $invoice->payments->map(fn($p) => [
                    'id'             => $p->id,
                    'method'         => $p->method,
                    'status'         => $p->status,
                    'amount'         => $p->amount,
                    'transaction_id' => $p->transaction_id,
                    'processed_at'   => $p->processed_at,
                ]) : [],
        ]);
    }
}
