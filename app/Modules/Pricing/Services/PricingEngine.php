<?php

namespace App\Modules\Pricing\Services;

use App\Modules\Pricing\Models\PricingRule;
use App\Modules\Pricing\Models\CustomerContract;
use App\Modules\Pricing\Models\PricingAuditLog;
use App\Modules\Pricing\DTOs\PriceBreakdown;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductVariant;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * PricingEngine
 *
 * Calculates the final customer-facing price for a product, optionally
 * scoped to a specific customer and quantity.
 *
 * Resolution order (highest priority wins at each stage):
 *   1. Customer B2B contract  (if active contract exists for this product/category)
 *   2. Product-scope rules    (rules targeting this specific product)
 *   3. Category-scope rules   (rules targeting the product's category)
 *   4. Vendor-scope rules     (rules targeting the product's vendor)
 *   5. Customer tier rules    (rules for bronze/silver/gold/platinum)
 *   6. Global default rules   (catch-all)
 *
 * Within each scope, rules are applied in descending priority order.
 * Volume tiers are resolved against the requested quantity.
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │ PLACEHOLDER VALUES (replace once credentials are provided)           │
 * │   PRICING_DEFAULT_MARGIN_PCT  = 15.00  ← PLACEHOLDER (15%)          │
 * │   PRICING_DEFAULT_VAT_PCT     = 5.00   ← PLACEHOLDER (5%)           │
 * │   PRICING_MIN_MARGIN_PCT      = 5.00   ← PLACEHOLDER                │
 * │   PRICING_MAX_MARGIN_PCT      = 40.00  ← PLACEHOLDER                │
 * └──────────────────────────────────────────────────────────────────────┘
 */
class PricingEngine
{
    private float $defaultMarginPct;
    private float $defaultVatPct;

    public function __construct(private OpisFeedService $opisFeed)
    {
        $this->defaultMarginPct = (float) config('pricing.default_margin_pct', 15.00); // PLACEHOLDER
        $this->defaultVatPct    = (float) config('pricing.default_vat_pct',     5.00); // PLACEHOLDER
    }

    /**
     * Calculate price for a product.
     *
     * @param Product          $product   The product to price
     * @param ProductVariant|null $variant  Specific variant (adds price_adjustment)
     * @param User|null        $customer  Null = guest / anonymous / storefront
     * @param int              $quantity  For volume tier resolution
     * @param bool             $audit     Whether to write a PricingAuditLog row
     */
    public function calculate(
        Product       $product,
        ?ProductVariant $variant  = null,
        ?User         $customer  = null,
        int           $quantity  = 1,
        bool          $audit     = false,
        string        $channel   = 'storefront'
    ): PriceBreakdown {

        // ── STEP 1: Get OPIS price ────────────────────────────────────────────
        $opisData  = $this->opisFeed->getPriceForSku($product->sku, $product->vendor_id);
        $opisPrice = $opisData['price'];
        $fromMock  = $opisData['from_mock'];

        // ── STEP 2: Determine base price ─────────────────────────────────────
        // Start from OPIS if available, otherwise product.base_price
        $basePrice = $opisPrice > 0 ? $opisPrice : (float) $product->base_price;

        // Add variant adjustment
        if ($variant) {
            $basePrice += (float) $variant->price_adjustment;
        }

        // ── STEP 3: Check for active B2B contract ────────────────────────────
        if ($customer) {
            $contract = $this->resolveContract($product, $customer);
            if ($contract) {
                return $this->applyContract($product, $variant, $customer, $contract, $opisPrice, $basePrice, $quantity, $fromMock, $audit, $channel);
            }
        }

        // ── STEP 4: Resolve applicable rules ─────────────────────────────────
        $rules        = $this->resolveRules($product, $customer, $quantity);
        $rulesApplied = [];
        $workingPrice = $basePrice;
        $marginAmount = 0.0;
        $discountAmt  = 0.0;

        foreach ($rules as $rule) {
            [$workingPrice, $applied] = $this->applyRule($rule, $workingPrice, $opisPrice, $quantity);
            if ($applied !== null) {
                if ($rule->type === PricingRule::TYPE_DISCOUNT) {
                    $discountAmt += $applied['amount'];
                } else {
                    $marginAmount += $applied['amount'];
                }
                $rulesApplied[] = $applied;
            }
        }

        // If no rules applied any margin at all, apply the default margin
        if (empty($rulesApplied)) {
            $defaultMargin = round($basePrice * ($this->defaultMarginPct / 100), 2);
            $workingPrice  = $basePrice + $defaultMargin;
            $marginAmount  = $defaultMargin;
            $rulesApplied[] = [
                'id'     => null,
                'name'   => 'Default Margin (placeholder — set PRICING_DEFAULT_MARGIN_PCT)',
                'type'   => PricingRule::TYPE_MARGIN,
                'value'  => $this->defaultMarginPct,
                'amount' => $defaultMargin,
            ];
        }

        // ── STEP 5: Apply VAT ─────────────────────────────────────────────────
        $vatRate   = $this->defaultVatPct / 100;
        $vatAmount = round($workingPrice * $vatRate, 2);
        $finalPrice = round($workingPrice + $vatAmount, 2);

        $breakdown = new PriceBreakdown(
            opisPrice:     $opisPrice,
            basePrice:     $basePrice,
            marginAmount:  $marginAmount,
            discountAmount:$discountAmt,
            vatAmount:     $vatAmount,
            finalPrice:    $finalPrice,
            currency:      'BDT',
            priceSource:   $fromMock ? 'mock' : ($opisPrice > 0 ? 'opis' : 'base_price'),
            rulesApplied:  $rulesApplied,
            contractId:    null,
            vatRate:       $vatRate,
            quantity:      $quantity,
            fromMockFeed:  $fromMock,
        );

        // ── STEP 6: Audit log ─────────────────────────────────────────────────
        if ($audit && $customer) {
            $this->writeAuditLog($product, $variant, $customer, $breakdown, null, $channel);
        }

        return $breakdown;
    }

    /**
     * Batch-calculate prices for a list of products.
     * Returns an array keyed by product ID.
     *
     * @param Product[]   $products
     * @return PriceBreakdown[]
     */
    public function calculateBatch(array $products, ?User $customer = null): array
    {
        $results = [];
        foreach ($products as $product) {
            $results[$product->id] = $this->calculate($product, null, $customer);
        }
        return $results;
    }

    // ── PRIVATE HELPERS ───────────────────────────────────────────────────────

    /**
     * Find the highest-priority active contract for this customer + product.
     */
    private function resolveContract(Product $product, User $customer): ?CustomerContract
    {
        return CustomerContract::activeNow()
            ->where('customer_id', $customer->id)
            ->where(fn($q) =>
                $q->where(fn($q2) => $q2->where('scope', 'product')->where('scope_id', $product->id))
                  ->orWhere(fn($q2) => $q2->where('scope', 'category')->where('scope_id', $product->category_id))
                  ->orWhere(fn($q2) => $q2->where('scope', 'global'))
            )
            ->orderByRaw("FIELD(scope, 'product', 'category', 'global')")
            ->first();
    }

    /**
     * Apply a B2B contract price.
     */
    private function applyContract(
        Product $product, ?ProductVariant $variant, User $customer,
        CustomerContract $contract,
        float $opisPrice, float $basePrice, int $quantity,
        bool $fromMock, bool $audit, string $channel
    ): PriceBreakdown {

        $contractPrice = match ($contract->price_type) {
            'fixed'               => (float) $contract->price_value,
            'margin_over_opis'    => $opisPrice * (1 + $contract->price_value / 100),
            'discount_off_list'   => $basePrice * (1 - $contract->price_value / 100),
            default               => $basePrice,
        };

        $vatRate    = $this->defaultVatPct / 100; // PLACEHOLDER — contracts could have custom VAT in future
        $vatAmount  = round($contractPrice * $vatRate, 2);
        $finalPrice = round($contractPrice + $vatAmount, 2);

        $discountAmt = max(0, $basePrice - $contractPrice);

        $breakdown = new PriceBreakdown(
            opisPrice:     $opisPrice,
            basePrice:     $basePrice,
            marginAmount:  0.0,
            discountAmount:$discountAmt,
            vatAmount:     $vatAmount,
            finalPrice:    $finalPrice,
            currency:      'BDT',
            priceSource:   'contract',
            rulesApplied:  [[
                'id'   => $contract->id,
                'name' => "Contract #{$contract->contract_number}",
                'type' => $contract->price_type,
                'value'=> $contract->price_value,
            ]],
            contractId:    $contract->id,
            vatRate:       $vatRate,
            quantity:      $quantity,
            fromMockFeed:  $fromMock,
        );

        if ($audit) {
            $this->writeAuditLog($product, $variant, $customer, $breakdown, $contract->id, $channel);
        }

        return $breakdown;
    }

    /**
     * Collect all applicable active rules, sorted by scope priority and then rule priority.
     *
     * @return PricingRule[]
     */
    private function resolveRules(Product $product, ?User $customer, int $quantity): array
    {
        $query = PricingRule::active()
            ->where(fn($q) =>
                // Product-specific rules
                $q->where(fn($q2) =>
                    $q2->where('scope', PricingRule::SCOPE_PRODUCT)
                       ->where('scope_id', $product->id)
                )
                // Category rules
                ->orWhere(fn($q2) =>
                    $q2->where('scope', PricingRule::SCOPE_CATEGORY)
                       ->where('scope_id', $product->category_id)
                )
                // Vendor rules
                ->orWhere(fn($q2) =>
                    $q2->where('scope', PricingRule::SCOPE_VENDOR)
                       ->where('scope_id', $product->vendor_id)
                )
                // Tier rules matching this customer's tier
                ->when($customer, fn($q2) =>
                    $q2->orWhere(fn($q3) =>
                        $q3->where('scope', PricingRule::SCOPE_TIER)
                           ->where('customer_tier', $customer->tier ?? 'bronze')
                    )
                )
                // Global rules
                ->orWhere('scope', PricingRule::SCOPE_GLOBAL)
            )
            // Volume tier filter
            ->where(fn($q) =>
                $q->whereNull('min_qty')
                  ->orWhere(fn($q2) =>
                      $q2->where('min_qty', '<=', $quantity)
                         ->where(fn($q3) => $q3->whereNull('max_qty')->orWhere('max_qty', '>=', $quantity))
                  )
            )
            ->orderByRaw("FIELD(scope,'product','category','vendor','customer_tier','global')")
            ->orderByDesc('priority');

        return $query->get()->all();
    }

    /**
     * Apply a single rule to the working price.
     * Returns [newPrice, appliedEntry|null].
     */
    private function applyRule(PricingRule $rule, float $workingPrice, float $opisPrice, int $quantity): array
    {
        switch ($rule->type) {
            case PricingRule::TYPE_MARGIN:
                $amount    = round($workingPrice * ($rule->value / 100), 2);
                $newPrice  = $workingPrice + $amount;
                return [$newPrice, ['id'=>$rule->id,'name'=>$rule->name,'type'=>$rule->type,'value'=>$rule->value,'amount'=>$amount]];

            case PricingRule::TYPE_FLAT:
                $newPrice  = $workingPrice + (float) $rule->value;
                return [$newPrice, ['id'=>$rule->id,'name'=>$rule->name,'type'=>$rule->type,'value'=>$rule->value,'amount'=>$rule->value]];

            case PricingRule::TYPE_DISCOUNT:
                $amount    = round($workingPrice * ($rule->value / 100), 2);
                $newPrice  = max(0, $workingPrice - $amount);
                return [$newPrice, ['id'=>$rule->id,'name'=>$rule->name,'type'=>$rule->type,'value'=>$rule->value,'amount'=>$amount]];

            case PricingRule::TYPE_VOLUME:
                // Volume rules carry a discount %
                $amount    = round($workingPrice * ($rule->value / 100), 2);
                $newPrice  = max(0, $workingPrice - $amount);
                return [$newPrice, ['id'=>$rule->id,'name'=>$rule->name,'type'=>'volume_discount','value'=>$rule->value,'amount'=>$amount,'qty_range'=>"{$rule->min_qty}–{$rule->max_qty}"]];
        }

        return [$workingPrice, null];
    }

    private function writeAuditLog(
        Product $product, ?ProductVariant $variant, User $customer,
        PriceBreakdown $bd, ?int $contractId, string $channel
    ): void {
        try {
            PricingAuditLog::create([
                'product_id'    => $product->id,
                'variant_id'    => $variant?->id,
                'customer_id'   => $customer->id,
                'opis_price'    => $bd->opisPrice,
                'base_price'    => $bd->basePrice,
                'final_price'   => $bd->finalPrice,
                'currency'      => $bd->currency,
                'rules_applied' => $bd->rulesApplied,
                'contract_id'   => $contractId,
                'quantity'      => $bd->quantity,
                'calculated_at' => now(),
                'channel'       => $channel,
            ]);
        } catch (\Throwable $e) {
            // Audit failure must never break the checkout
            Log::error("PricingAuditLog write failed: {$e->getMessage()}");
        }
    }
}
