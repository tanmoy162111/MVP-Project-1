<?php

namespace App\Modules\Pricing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pricing\Services\PricingEngine;
use App\Modules\Pricing\Services\OpisFeedService;
use App\Modules\Pricing\Models\PricingRule;
use App\Modules\Pricing\Models\CustomerContract;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingController extends Controller
{
    public function __construct(
        private PricingEngine   $engine,
        private OpisFeedService $opisFeed,
    ) {}

    // ── PUBLIC / STOREFRONT ───────────────────────────────────────────────────

    /**
     * GET /api/v1/pricing/calculate
     * Returns full price breakdown for a product.
     * If authenticated, applies customer-specific contracts and tier rules.
     *
     * Query params: product_id, variant_id?, quantity?
     */
    public function calculate(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'variant_id' => 'nullable|integer|exists:product_variants,id',
            'quantity'   => 'nullable|integer|min:1|max:9999',
        ]);

        $product  = Product::active()->findOrFail($request->product_id);
        $variant  = $request->variant_id
            ? ProductVariant::where('product_id', $product->id)->findOrFail($request->variant_id)
            : null;
        $customer = $request->user(); // null if guest
        $quantity = (int) ($request->quantity ?? 1);

        $breakdown = $this->engine->calculate(
            $product, $variant, $customer, $quantity,
            audit: (bool) $customer, // only audit authenticated requests
            channel: 'storefront'
        );

        return $this->success([
            'product_id'  => $product->id,
            'variant_id'  => $variant?->id,
            'quantity'    => $quantity,
            'breakdown'   => $breakdown->toArray(),
            'notice'      => $breakdown->fromMockFeed
                ? 'Price is estimated — live OPIS feed credentials not yet configured.'
                : null,
        ]);
    }

    /**
     * POST /api/v1/pricing/calculate-batch
     * Returns price breakdowns for multiple products in one call.
     * Used by frontend cart to price all items at once.
     */
    public function calculateBatch(Request $request): JsonResponse
    {
        $request->validate([
            'product_ids'   => 'required|array|max:50',
            'product_ids.*' => 'integer|exists:products,id',
            'quantity'      => 'nullable|integer|min:1',
        ]);

        $products  = Product::active()->whereIn('id', $request->product_ids)->get();
        $customer  = $request->user();
        $results   = [];

        foreach ($products as $product) {
            $breakdown        = $this->engine->calculate($product, null, $customer, $request->quantity ?? 1);
            $results[$product->id] = $breakdown->toArray();
        }

        return $this->success([
            'prices' => $results,
            'notice' => $this->opisFeed->isConfigured()
                ? null
                : 'Prices are estimated — live OPIS feed not yet connected.',
        ]);
    }

    // ── OPIS STATUS ───────────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/pricing/opis-status
     * Admin: shows OPIS connection status and last fetch times.
     */
    public function opisStatus(): JsonResponse
    {
        $configured  = $this->opisFeed->isConfigured();
        $lastFetched = \App\Modules\Pricing\Models\OpisPrice::max('fetched_at');
        $totalCached = \App\Modules\Pricing\Models\OpisPrice::count();

        return $this->success([
            'configured'    => $configured,
            'status'        => $configured ? 'live' : 'mock',
            'notice'        => $configured
                ? 'OPIS feed is connected and returning live prices.'
                : 'OPIS credentials not set. Set OPIS_API_URL and OPIS_API_KEY in .env to enable live pricing.',
            'last_fetched'  => $lastFetched,
            'cached_prices' => $totalCached,
            // PLACEHOLDER note shown in admin panel
            'credentials_needed' => ! $configured ? [
                'OPIS_API_URL' => '← Set in .env (PLACEHOLDER)',
                'OPIS_API_KEY' => '← Set in .env (PLACEHOLDER)',
            ] : null,
        ]);
    }

    /**
     * POST /api/v1/admin/pricing/opis-refresh
     * Manually trigger OPIS feed refresh for a vendor.
     */
    public function opisRefresh(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer|exists:vendors,id']);

        $count = $this->opisFeed->refreshVendorPrices($request->vendor_id);

        return $this->success(['refreshed' => $count], "OPIS feed refreshed {$count} prices.");
    }

    // ── PRICING RULES (admin) ─────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/pricing/rules
     */
    public function indexRules(Request $request): JsonResponse
    {
        $rules = PricingRule::when($request->scope, fn($q, $v) => $q->where('scope', $v))
            ->when($request->type,  fn($q, $v) => $q->where('type', $v))
            ->when($request->active, fn($q) => $q->active())
            ->orderByDesc('priority')
            ->paginate($request->integer('per_page', 20));

        return $this->paginated($rules);
    }

    /**
     * POST /api/v1/admin/pricing/rules
     */
    public function storeRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:150',
            'type'          => 'required|in:margin,flat,discount,volume',
            'scope'         => 'required|in:global,category,vendor,product,customer_tier',
            'scope_id'      => 'nullable|integer',
            'value'         => 'required|numeric|min:0',
            'min_qty'       => 'nullable|integer|min:1',
            'max_qty'       => 'nullable|integer|min:1|gte:min_qty',
            'customer_tier' => 'nullable|in:bronze,silver,gold,platinum',
            'priority'      => 'nullable|integer|min:0',
            'is_active'     => 'nullable|boolean',
            'starts_at'     => 'nullable|date',
            'ends_at'       => 'nullable|date|after:starts_at',
        ]);

        $rule = PricingRule::create($data);

        return $this->created($rule, 'Pricing rule created.');
    }

    /**
     * PUT /api/v1/admin/pricing/rules/{id}
     */
    public function updateRule(Request $request, int $id): JsonResponse
    {
        $rule = PricingRule::findOrFail($id);

        $data = $request->validate([
            'name'      => 'sometimes|string|max:150',
            'value'     => 'sometimes|numeric|min:0',
            'priority'  => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'starts_at' => 'nullable|date',
            'ends_at'   => 'nullable|date',
            'min_qty'   => 'nullable|integer|min:1',
            'max_qty'   => 'nullable|integer|min:1',
        ]);

        $rule->update($data);

        return $this->success($rule->fresh(), 'Pricing rule updated.');
    }

    /**
     * DELETE /api/v1/admin/pricing/rules/{id}
     */
    public function destroyRule(int $id): JsonResponse
    {
        PricingRule::findOrFail($id)->delete();
        return $this->noContent();
    }

    // ── CUSTOMER CONTRACTS (admin + pricing_manager) ──────────────────────────

    /**
     * GET /api/v1/admin/pricing/contracts
     */
    public function indexContracts(Request $request): JsonResponse
    {
        $contracts = CustomerContract::with(['customer:id,name,email,company_name','vendor:id,store_name'])
            ->when($request->customer_id, fn($q, $v) => $q->where('customer_id', $v))
            ->when($request->vendor_id,   fn($q, $v) => $q->where('vendor_id', $v))
            ->when($request->status,      fn($q, $v) => $q->where('status', $v))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginated($contracts);
    }

    /**
     * POST /api/v1/admin/pricing/contracts
     */
    public function storeContract(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id'   => 'required|exists:users,id',
            'vendor_id'     => 'nullable|exists:vendors,id',
            'scope'         => 'required|in:product,category,global',
            'scope_id'      => 'nullable|integer',
            'price_type'    => 'required|in:fixed,margin_over_opis,discount_off_list',
            'price_value'   => 'required|numeric|min:0',
            'currency'      => 'nullable|string|size:3',
            'min_order_qty' => 'nullable|integer|min:1',
            'max_order_qty' => 'nullable|integer|min:1',
            'credit_limit'  => 'nullable|numeric|min:0',
            'payment_terms' => 'nullable|in:net_7,net_15,net_30,net_60,cod',
            'status'        => 'nullable|in:active,expired,suspended',
            'starts_at'     => 'required|date',
            'ends_at'       => 'required|date|after:starts_at',
            'notes'         => 'nullable|string|max:1000',
        ]);

        $data['contract_number'] = $this->generateContractNumber();
        $data['status']          = $data['status'] ?? 'active';
        $data['currency']        = $data['currency'] ?? 'BDT';

        $contract = CustomerContract::create($data);

        return $this->created($contract->load(['customer:id,name,email','vendor:id,store_name']),
            "Contract #{$contract->contract_number} created.");
    }

    /**
     * PUT /api/v1/admin/pricing/contracts/{id}
     */
    public function updateContract(Request $request, int $id): JsonResponse
    {
        $contract = CustomerContract::findOrFail($id);

        $data = $request->validate([
            'price_type'    => 'sometimes|in:fixed,margin_over_opis,discount_off_list',
            'price_value'   => 'sometimes|numeric|min:0',
            'credit_limit'  => 'nullable|numeric|min:0',
            'payment_terms' => 'nullable|in:net_7,net_15,net_30,net_60,cod',
            'status'        => 'sometimes|in:active,expired,suspended',
            'ends_at'       => 'nullable|date',
            'notes'         => 'nullable|string|max:1000',
        ]);

        $contract->update($data);

        return $this->success($contract->fresh()->load(['customer:id,name,email','vendor:id,store_name']),
            'Contract updated.');
    }

    /**
     * GET /api/v1/admin/pricing/contracts/{id}
     */
    public function showContract(int $id): JsonResponse
    {
        $contract = CustomerContract::with(['customer:id,name,email,company_name,tier','vendor:id,store_name'])
            ->findOrFail($id);

        return $this->success($contract);
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────────

    private function generateContractNumber(): string
    {
        $year = now()->format('Y');
        $seq  = str_pad(CustomerContract::whereYear('created_at', $year)->count() + 1, 4, '0', STR_PAD_LEFT);
        return "CTR-{$year}-{$seq}";
    }
}
