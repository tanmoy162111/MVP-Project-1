<?php

namespace App\Modules\Pricing\Services;

use App\Modules\Pricing\Models\OpisPrice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * OpisFeedService
 *
 * Responsible for fetching prices from the OPIS API and caching them locally.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  PLACEHOLDER CREDENTIALS                                                │
 * │                                                                         │
 * │  The following .env keys are required for live OPIS data:               │
 * │    OPIS_API_URL    = https://api.opisnet.com/v2          ← PLACEHOLDER  │
 * │    OPIS_API_KEY    = your-opis-api-key-here              ← PLACEHOLDER  │
 * │    OPIS_TIMEOUT    = 10  (seconds)                                      │
 * │    OPIS_CACHE_TTL  = 1800 (seconds, 30 min default)                     │
 * │                                                                         │
 * │  Until these are set, all methods return mock data and                  │
 * │  PriceBreakdown::$fromMockFeed will be TRUE.                            │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
class OpisFeedService
{
    private string $baseUrl;
    private string $apiKey;
    private int    $cacheTtl;
    private int    $timeout;
    private bool   $isConfigured;

    public function __construct()
    {
        $this->baseUrl      = config('pricing.opis_api_url', '');
        $this->apiKey       = config('pricing.opis_api_key', '');
        $this->cacheTtl     = (int) config('pricing.opis_cache_ttl', 1800);
        $this->timeout      = (int) config('pricing.opis_timeout', 10);
        $this->isConfigured = ! empty($this->baseUrl) && ! empty($this->apiKey)
                              && $this->baseUrl !== 'PLACEHOLDER';
    }

    /**
     * Fetch price for a single SKU.
     * Returns the cached DB price if fresh, fetches from API if stale.
     * Falls back to mock data if OPIS is not configured.
     *
     * @return array{price: float, currency: string, from_mock: bool, fetched_at: string}
     */
    public function getPriceForSku(string $sku, int $vendorId): array
    {
        if (! $this->isConfigured) {
            return $this->mockPrice($sku);
        }

        $cacheKey = "opis_price:{$vendorId}:{$sku}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($sku, $vendorId) {
            // Check DB cache first — still valid?
            $cached = OpisPrice::where('product_sku', $sku)
                ->where('vendor_id', $vendorId)
                ->where('valid_until', '>', now())
                ->latest('fetched_at')
                ->first();

            if ($cached) {
                return [
                    'price'      => (float) $cached->opis_price,
                    'currency'   => $cached->currency,
                    'from_mock'  => false,
                    'fetched_at' => $cached->fetched_at->toISOString(),
                ];
            }

            return $this->fetchFromApi($sku, $vendorId);
        });
    }

    /**
     * Bulk-refresh prices for a vendor (called by scheduled job).
     * Fetches all SKUs in one API call and upserts the opis_prices table.
     */
    public function refreshVendorPrices(int $vendorId): int
    {
        if (! $this->isConfigured) {
            Log::info("OpisFeedService: credentials not configured — skipping live refresh for vendor {$vendorId}.");
            return 0;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Accept'        => 'application/json',
            ])
            ->timeout($this->timeout)
            ->get("{$this->baseUrl}/prices", ['vendor_id' => $vendorId, 'per_page' => 500]);

            if (! $response->successful()) {
                Log::error("OPIS feed error for vendor {$vendorId}: HTTP {$response->status()}");
                return 0;
            }

            $items   = $response->json('data', []);
            $count   = 0;
            $validUntil = now()->addSeconds($this->cacheTtl);

            foreach ($items as $item) {
                OpisPrice::updateOrCreate(
                    ['product_sku' => $item['sku'], 'vendor_id' => $vendorId],
                    [
                        'opis_price'  => $item['price'],
                        'currency'    => $item['currency'] ?? 'BDT',
                        'valid_from'  => now(),
                        'valid_until' => $validUntil,
                        'raw_data'    => $item,
                        'fetched_at'  => now(),
                    ]
                );

                Cache::put("opis_price:{$vendorId}:{$item['sku']}", [
                    'price'      => (float) $item['price'],
                    'currency'   => $item['currency'] ?? 'BDT',
                    'from_mock'  => false,
                    'fetched_at' => now()->toISOString(),
                ], $this->cacheTtl);

                $count++;
            }

            Log::info("OPIS feed refreshed {$count} prices for vendor {$vendorId}.");
            return $count;

        } catch (\Throwable $e) {
            Log::error("OPIS feed exception for vendor {$vendorId}: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Check whether OPIS credentials are configured.
     */
    public function isConfigured(): bool
    {
        return $this->isConfigured;
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────────

    private function fetchFromApi(string $sku, int $vendorId): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Accept'        => 'application/json',
            ])
            ->timeout($this->timeout)
            ->get("{$this->baseUrl}/price", ['sku' => $sku, 'vendor_id' => $vendorId]);

            if (! $response->successful()) {
                Log::warning("OPIS single-SKU fetch failed for {$sku}: HTTP {$response->status()}");
                return $this->mockPrice($sku);
            }

            $data = $response->json('data');

            OpisPrice::updateOrCreate(
                ['product_sku' => $sku, 'vendor_id' => $vendorId],
                [
                    'opis_price'  => $data['price'],
                    'currency'    => $data['currency'] ?? 'BDT',
                    'valid_from'  => now(),
                    'valid_until' => now()->addSeconds($this->cacheTtl),
                    'raw_data'    => $data,
                    'fetched_at'  => now(),
                ]
            );

            return [
                'price'      => (float) $data['price'],
                'currency'   => $data['currency'] ?? 'BDT',
                'from_mock'  => false,
                'fetched_at' => now()->toISOString(),
            ];

        } catch (\Throwable $e) {
            Log::warning("OPIS API unavailable for SKU {$sku}: {$e->getMessage()}. Using mock.");
            return $this->mockPrice($sku);
        }
    }

    /**
     * Generate a deterministic mock price based on the SKU hash.
     * This makes mock prices stable across requests for the same SKU
     * so tests and demos behave consistently.
     */
    private function mockPrice(string $sku): array
    {
        // Deterministic: same SKU always gets same mock price
        $hash     = abs(crc32($sku));
        $mockBase = 1000 + ($hash % 99000); // BDT 1,000 – BDT 1,00,000

        return [
            'price'      => (float) $mockBase,
            'currency'   => 'BDT',
            'from_mock'  => true,
            'fetched_at' => now()->toISOString(),
        ];
    }
}
