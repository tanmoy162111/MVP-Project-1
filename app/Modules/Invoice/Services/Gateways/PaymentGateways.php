<?php

namespace App\Modules\Invoice\Services\Gateways;

/**
 * PaymentGatewayInterface
 *
 * All gateway adapters implement this contract.
 * Swap adapters in PaymentService without touching business logic.
 */
interface PaymentGatewayInterface
{
    /**
     * Initiate a payment. Returns a redirect URL or token for the frontend.
     *
     * @return array{success: bool, redirect_url: string|null, token: string|null, reference: string, message: string}
     */
    public function initiate(float $amount, string $currency, string $reference, array $meta = []): array;

    /**
     * Verify a payment after the gateway callback.
     *
     * @return array{success: bool, transaction_id: string|null, amount: float, message: string, raw: array}
     */
    public function verify(string $transactionId, array $callbackData = []): array;

    /**
     * Process a refund.
     *
     * @return array{success: bool, refund_id: string|null, message: string}
     */
    public function refund(string $transactionId, float $amount): array;

    public function getName(): string;
}


// ═══════════════════════════════════════════════════════════════════════════
// MOCK GATEWAY — used when no real gateway credentials are configured
// ═══════════════════════════════════════════════════════════════════════════

class MockPaymentGateway implements PaymentGatewayInterface
{
    public function initiate(float $amount, string $currency, string $reference, array $meta = []): array
    {
        return [
            'success'      => true,
            'redirect_url' => null,
            'token'        => 'MOCK-TXN-' . strtoupper(substr(md5($reference . microtime()), 0, 12)),
            'reference'    => $reference,
            'message'      => 'Mock payment initiated. No real money moved. Replace with live gateway credentials.',
        ];
    }

    public function verify(string $transactionId, array $callbackData = []): array
    {
        // Mock always succeeds — useful for testing full checkout flow
        return [
            'success'        => true,
            'transaction_id' => $transactionId,
            'amount'         => $callbackData['amount'] ?? 0.0,
            'message'        => 'Mock payment verified.',
            'raw'            => ['mock' => true, 'transaction_id' => $transactionId],
        ];
    }

    public function refund(string $transactionId, float $amount): array
    {
        return [
            'success'   => true,
            'refund_id' => 'MOCK-REFUND-' . strtoupper(substr(md5($transactionId), 0, 8)),
            'message'   => 'Mock refund processed.',
        ];
    }

    public function getName(): string { return 'mock'; }
}


// ═══════════════════════════════════════════════════════════════════════════
// bKASH GATEWAY
//
// PLACEHOLDER CREDENTIALS — Set in .env:
//   BKASH_APP_KEY      = ← PLACEHOLDER
//   BKASH_APP_SECRET   = ← PLACEHOLDER
//   BKASH_USERNAME     = ← PLACEHOLDER
//   BKASH_PASSWORD     = ← PLACEHOLDER
//   BKASH_BASE_URL     = https://tokenized.sandbox.bka.sh/v1.2.0-beta  (sandbox)
//                      = https://tokenized.pay.bka.sh/v1.2.0-beta      (production)
// ═══════════════════════════════════════════════════════════════════════════

class BkashGateway implements PaymentGatewayInterface
{
    private string $appKey;
    private string $appSecret;
    private string $username;
    private string $password;
    private string $baseUrl;
    private bool   $isConfigured;

    public function __construct()
    {
        $this->appKey       = config('payment.bkash.app_key',    'PLACEHOLDER');
        $this->appSecret    = config('payment.bkash.app_secret', 'PLACEHOLDER');
        $this->username     = config('payment.bkash.username',   'PLACEHOLDER');
        $this->password     = config('payment.bkash.password',   'PLACEHOLDER');
        $this->baseUrl      = config('payment.bkash.base_url',   'https://tokenized.sandbox.bka.sh/v1.2.0-beta');
        $this->isConfigured = $this->appKey !== 'PLACEHOLDER' && ! empty($this->appKey);
    }

    public function initiate(float $amount, string $currency, string $reference, array $meta = []): array
    {
        if (! $this->isConfigured) {
            return $this->notConfiguredResponse('bKash');
        }

        try {
            $token    = $this->getToken();
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'authorization' => $token,
                'x-app-key'     => $this->appKey,
            ])->post("{$this->baseUrl}/tokenized/checkout/create", [
                'mode'                => '0011',
                'payerReference'      => $meta['customer_id'] ?? $reference,
                'callbackURL'         => config('app.url') . '/api/v1/payments/bkash/callback',
                'amount'              => number_format($amount, 2, '.', ''),
                'currency'            => 'BDT',
                'intent'              => 'sale',
                'merchantInvoiceNumber' => $reference,
            ]);

            if ($response->successful() && $response->json('statusCode') === '0000') {
                return [
                    'success'      => true,
                    'redirect_url' => $response->json('bkashURL'),
                    'token'        => $response->json('paymentID'),
                    'reference'    => $reference,
                    'message'      => 'bKash payment initiated.',
                ];
            }

            return [
                'success' => false, 'redirect_url' => null, 'token' => null,
                'reference' => $reference,
                'message' => 'bKash initiation failed: ' . $response->json('statusMessage', 'Unknown error'),
            ];

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("bKash initiate error: {$e->getMessage()}");
            return ['success' => false, 'redirect_url' => null, 'token' => null, 'reference' => $reference, 'message' => 'bKash unavailable.'];
        }
    }

    public function verify(string $transactionId, array $callbackData = []): array
    {
        if (! $this->isConfigured) {
            return ['success' => false, 'transaction_id' => null, 'amount' => 0, 'message' => 'bKash not configured.', 'raw' => []];
        }

        try {
            $token    = $this->getToken();
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'authorization' => $token,
                'x-app-key'     => $this->appKey,
            ])->post("{$this->baseUrl}/tokenized/checkout/execute", [
                'paymentID' => $transactionId,
            ]);

            $data = $response->json();
            if ($response->successful() && ($data['statusCode'] ?? '') === '0000') {
                return [
                    'success'        => true,
                    'transaction_id' => $data['trxID'] ?? $transactionId,
                    'amount'         => (float) ($data['amount'] ?? 0),
                    'message'        => 'bKash payment verified.',
                    'raw'            => $data,
                ];
            }

            return ['success' => false, 'transaction_id' => null, 'amount' => 0, 'message' => $data['statusMessage'] ?? 'bKash verification failed.', 'raw' => $data];

        } catch (\Throwable $e) {
            return ['success' => false, 'transaction_id' => null, 'amount' => 0, 'message' => $e->getMessage(), 'raw' => []];
        }
    }

    public function refund(string $transactionId, float $amount): array
    {
        if (! $this->isConfigured) {
            return ['success' => false, 'refund_id' => null, 'message' => 'bKash not configured.'];
        }

        try {
            $token    = $this->getToken();
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'authorization' => $token,
                'x-app-key'     => $this->appKey,
            ])->post("{$this->baseUrl}/tokenized/checkout/payment/refund", [
                'trxID'    => $transactionId,
                'amount'   => number_format($amount, 2, '.', ''),
                'currency' => 'BDT',
                'reason'   => 'Customer refund',
            ]);

            $data = $response->json();
            if ($response->successful() && ($data['statusCode'] ?? '') === '0000') {
                return ['success' => true, 'refund_id' => $data['refundTrxID'] ?? null, 'message' => 'bKash refund processed.'];
            }
            return ['success' => false, 'refund_id' => null, 'message' => $data['statusMessage'] ?? 'Refund failed.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'refund_id' => null, 'message' => $e->getMessage()];
        }
    }

    public function getName(): string { return 'bkash'; }

    private function getToken(): string
    {
        return \Illuminate\Support\Facades\Cache::remember('bkash_token', 3500, function () {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'username'  => $this->username,
                'password'  => $this->password,
            ])->post("{$this->baseUrl}/tokenized/checkout/token/grant", [
                'app_key'    => $this->appKey,
                'app_secret' => $this->appSecret,
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('bKash token grant failed.');
            }

            return $response->json('id_token');
        });
    }

    private function notConfiguredResponse(string $name): array
    {
        return [
            'success'      => false,
            'redirect_url' => null,
            'token'        => null,
            'reference'    => '',
            'message'      => "{$name} credentials not configured. Set BKASH_APP_KEY, BKASH_APP_SECRET, BKASH_USERNAME, BKASH_PASSWORD in .env.",
        ];
    }
}


// ═══════════════════════════════════════════════════════════════════════════
// SSLCommerz GATEWAY
//
// PLACEHOLDER CREDENTIALS — Set in .env:
//   SSLCOMMERZ_STORE_ID      = ← PLACEHOLDER
//   SSLCOMMERZ_STORE_PASSWD  = ← PLACEHOLDER
//   SSLCOMMERZ_IS_LIVE       = false  (set true for production)
// ═══════════════════════════════════════════════════════════════════════════

class SslCommerzGateway implements PaymentGatewayInterface
{
    private string $storeId;
    private string $storePasswd;
    private string $baseUrl;
    private bool   $isConfigured;

    public function __construct()
    {
        $this->storeId      = config('payment.sslcommerz.store_id',     'PLACEHOLDER');
        $this->storePasswd  = config('payment.sslcommerz.store_passwd',  'PLACEHOLDER');
        $isLive             = config('payment.sslcommerz.is_live',        false);
        $this->baseUrl      = $isLive
            ? 'https://securepay.sslcommerz.com'
            : 'https://sandbox.sslcommerz.com';
        $this->isConfigured = $this->storeId !== 'PLACEHOLDER' && ! empty($this->storeId);
    }

    public function initiate(float $amount, string $currency, string $reference, array $meta = []): array
    {
        if (! $this->isConfigured) {
            return [
                'success' => false, 'redirect_url' => null, 'token' => null, 'reference' => $reference,
                'message' => 'SSLCommerz credentials not configured. Set SSLCOMMERZ_STORE_ID and SSLCOMMERZ_STORE_PASSWD in .env.',
            ];
        }

        try {
            $response = \Illuminate\Support\Facades\Http::asForm()
                ->post("{$this->baseUrl}/gwprocess/v4/api.php", [
                    'store_id'     => $this->storeId,
                    'store_passwd' => $this->storePasswd,
                    'total_amount' => $amount,
                    'currency'     => 'BDT',
                    'tran_id'      => $reference,
                    'success_url'  => config('app.url') . '/api/v1/payments/sslcommerz/success',
                    'fail_url'     => config('app.url') . '/api/v1/payments/sslcommerz/fail',
                    'cancel_url'   => config('app.url') . '/api/v1/payments/sslcommerz/cancel',
                    'cus_name'     => $meta['customer_name'] ?? 'Customer',
                    'cus_email'    => $meta['customer_email'] ?? 'customer@example.com',
                    'cus_phone'    => $meta['customer_phone'] ?? '01700000000',
                    'cus_add1'     => $meta['address'] ?? 'Dhaka',
                    'cus_city'     => $meta['city'] ?? 'Dhaka',
                    'cus_country'  => 'Bangladesh',
                    'shipping_method' => 'NO',
                    'product_name'    => 'Electronics Order',
                    'product_category'=> 'Electronics',
                    'product_profile' => 'general',
                ]);

            if ($response->successful() && $response->json('status') === 'SUCCESS') {
                return [
                    'success'      => true,
                    'redirect_url' => $response->json('GatewayPageURL'),
                    'token'        => $response->json('sessionkey'),
                    'reference'    => $reference,
                    'message'      => 'SSLCommerz session created.',
                ];
            }

            return ['success' => false, 'redirect_url' => null, 'token' => null, 'reference' => $reference,
                    'message' => $response->json('failedreason', 'SSLCommerz initiation failed.')];

        } catch (\Throwable $e) {
            return ['success' => false, 'redirect_url' => null, 'token' => null, 'reference' => $reference, 'message' => $e->getMessage()];
        }
    }

    public function verify(string $transactionId, array $callbackData = []): array
    {
        if (! $this->isConfigured) {
            return ['success' => false, 'transaction_id' => null, 'amount' => 0, 'message' => 'SSLCommerz not configured.', 'raw' => []];
        }

        try {
            $response = \Illuminate\Support\Facades\Http::get("{$this->baseUrl}/validator/api/validationserverAPI.php", [
                'val_id'       => $callbackData['val_id'] ?? $transactionId,
                'store_id'     => $this->storeId,
                'store_passwd' => $this->storePasswd,
                'format'       => 'json',
            ]);

            $data = $response->json();
            if (($data['status'] ?? '') === 'VALID' || ($data['status'] ?? '') === 'VALIDATED') {
                return [
                    'success'        => true,
                    'transaction_id' => $data['bank_tran_id'] ?? $transactionId,
                    'amount'         => (float) ($data['amount'] ?? 0),
                    'message'        => 'SSLCommerz payment validated.',
                    'raw'            => $data,
                ];
            }

            return ['success' => false, 'transaction_id' => null, 'amount' => 0, 'message' => 'SSLCommerz validation failed.', 'raw' => $data];

        } catch (\Throwable $e) {
            return ['success' => false, 'transaction_id' => null, 'amount' => 0, 'message' => $e->getMessage(), 'raw' => []];
        }
    }

    public function refund(string $transactionId, float $amount): array
    {
        // SSLCommerz refund via dashboard or direct API call — documented for Phase 6
        return ['success' => false, 'refund_id' => null, 'message' => 'SSLCommerz refunds must be initiated from the merchant dashboard.'];
    }

    public function getName(): string { return 'sslcommerz'; }
}
