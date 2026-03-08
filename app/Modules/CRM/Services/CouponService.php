<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\Coupon;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CouponService
 *
 * Validates and applies coupon codes at checkout.
 * All discount calculations return a DiscountResult array so the
 * checkout service can apply them without knowing coupon internals.
 */
class CouponService
{
    /**
     * Validate a coupon code against an order context.
     *
     * @param  array $cartItems  [['product_id'=>1,'category_id'=>2,'subtotal'=>5000], ...]
     * @return array{valid: bool, coupon: Coupon|null, discount: float, message: string}
     */
    public function validate(
        string $code,
        User   $customer,
        float  $orderSubtotal,
        array  $cartItems = []
    ): array {
        $coupon = Coupon::where('code', strtoupper(trim($code)))->first();

        if (! $coupon) {
            return $this->invalid('Coupon code not found.');
        }

        if (! $coupon->isValid()) {
            return $this->invalid('This coupon is expired or inactive.');
        }

        // Per-user usage check
        if ($coupon->usage_limit_per_user) {
            $userUsage = DB::table('coupon_usages')
                ->where('coupon_id', $coupon->id)
                ->where('customer_id', $customer->id)
                ->count();
            if ($userUsage >= $coupon->usage_limit_per_user) {
                return $this->invalid('You have already used this coupon the maximum number of times.');
            }
        }

        // Minimum order amount
        if ($coupon->min_order_amount && $orderSubtotal < $coupon->min_order_amount) {
            return $this->invalid(
                'Minimum order amount for this coupon is BDT ' . number_format($coupon->min_order_amount, 2) . '.'
            );
        }

        // Customer restriction
        if ($coupon->applicable_customer_ids && ! in_array($customer->id, $coupon->applicable_customer_ids)) {
            return $this->invalid('This coupon is not applicable to your account.');
        }

        // Product / category restriction
        if ($coupon->applicable_product_ids || $coupon->applicable_category_ids) {
            $eligible = collect($cartItems)->filter(function ($item) use ($coupon) {
                $productMatch  = ! $coupon->applicable_product_ids
                    || in_array($item['product_id'], $coupon->applicable_product_ids);
                $categoryMatch = ! $coupon->applicable_category_ids
                    || in_array($item['category_id'] ?? null, $coupon->applicable_category_ids);
                return $productMatch || $categoryMatch;
            });

            if ($eligible->isEmpty()) {
                return $this->invalid('This coupon does not apply to any items in your cart.');
            }

            // Recalculate eligible subtotal
            $orderSubtotal = $eligible->sum('subtotal');
        }

        $discount = $this->calculateDiscount($coupon, $orderSubtotal);

        return [
            'valid'    => true,
            'coupon'   => $coupon,
            'discount' => $discount,
            'message'  => "Coupon applied! You save BDT " . number_format($discount, 2) . ".",
        ];
    }

    /**
     * Redeem a coupon — increment usage counters inside a transaction.
     * Call this only after order is successfully placed.
     */
    public function redeem(Coupon $coupon, User $customer, int $orderId): void
    {
        DB::transaction(function () use ($coupon, $customer, $orderId) {
            $coupon->increment('used_count');

            DB::table('coupon_usages')->insert([
                'coupon_id'   => $coupon->id,
                'customer_id' => $customer->id,
                'order_id'    => $orderId,
                'used_at'     => now(),
            ]);
        });
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────────

    private function calculateDiscount(Coupon $coupon, float $subtotal): float
    {
        $discount = match ($coupon->type) {
            Coupon::TYPE_PERCENT  => round($subtotal * ($coupon->value / 100), 2),
            Coupon::TYPE_FLAT     => min((float) $coupon->value, $subtotal),
            Coupon::TYPE_SHIPPING => 0.0, // shipping fee waived — handled in checkout
            default               => 0.0,
        };

        // Cap percent discounts
        if ($coupon->type === Coupon::TYPE_PERCENT && $coupon->max_discount_amount) {
            $discount = min($discount, (float) $coupon->max_discount_amount);
        }

        return $discount;
    }

    private function invalid(string $message): array
    {
        return ['valid' => false, 'coupon' => null, 'discount' => 0.0, 'message' => $message];
    }
}
