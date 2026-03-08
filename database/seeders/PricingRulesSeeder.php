<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Pricing\Models\PricingRule;

/**
 * PricingRulesSeeder
 *
 * Seeds default pricing rules using placeholder values.
 * Replace the margin/VAT values once the client confirms them.
 *
 * PLACEHOLDERS in this seeder:
 *   - Default margin 15%     → Set PRICING_DEFAULT_MARGIN_PCT in .env
 *   - Gold tier 12% margin   → Confirm tier discounts with client
 *   - Platinum tier 10%      → Confirm tier discounts with client
 *   - Volume 5% at qty 10+   → Confirm volume breaks with client
 *   - Volume 8% at qty 50+   → Confirm volume breaks with client
 */
class PricingRulesSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Global default margin — applies to everything with no other rule
        PricingRule::updateOrCreate(['name' => 'Global Default Margin'], [
            'type'      => 'margin',
            'scope'     => 'global',
            'scope_id'  => null,
            'value'     => config('pricing.default_margin_pct', 15.00), // PLACEHOLDER: 15%
            'priority'  => 1,
            'is_active' => true,
        ]);

        // 2. Silver tier — slight loyalty discount
        PricingRule::updateOrCreate(['name' => 'Silver Tier Margin'], [
            'type'          => 'margin',
            'scope'         => 'customer_tier',
            'customer_tier' => 'silver',
            'value'         => 13.00, // PLACEHOLDER: confirm with client
            'priority'      => 10,
            'is_active'     => true,
        ]);

        // 3. Gold tier
        PricingRule::updateOrCreate(['name' => 'Gold Tier Margin'], [
            'type'          => 'margin',
            'scope'         => 'customer_tier',
            'customer_tier' => 'gold',
            'value'         => 12.00, // PLACEHOLDER: confirm with client
            'priority'      => 10,
            'is_active'     => true,
        ]);

        // 4. Platinum tier — best pricing for highest-value B2B customers
        PricingRule::updateOrCreate(['name' => 'Platinum Tier Margin'], [
            'type'          => 'margin',
            'scope'         => 'customer_tier',
            'customer_tier' => 'platinum',
            'value'         => 10.00, // PLACEHOLDER: confirm with client
            'priority'      => 10,
            'is_active'     => true,
        ]);

        // 5. Volume tier — 10+ units
        PricingRule::updateOrCreate(['name' => 'Volume Discount 10+ Units'], [
            'type'      => 'volume',
            'scope'     => 'global',
            'value'     => 5.00,   // PLACEHOLDER: 5% off for 10+ qty
            'min_qty'   => 10,
            'max_qty'   => 49,
            'priority'  => 20,
            'is_active' => true,
        ]);

        // 6. Volume tier — 50+ units
        PricingRule::updateOrCreate(['name' => 'Volume Discount 50+ Units'], [
            'type'      => 'volume',
            'scope'     => 'global',
            'value'     => 8.00,   // PLACEHOLDER: 8% off for 50+ qty
            'min_qty'   => 50,
            'max_qty'   => null,
            'priority'  => 20,
            'is_active' => true,
        ]);

        $this->command->info('Pricing rules seeded (with placeholder values — review before production).');
    }
}
