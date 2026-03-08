<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Coupon
 * Discount codes usable at checkout.
 */
class Coupon extends Model
{
    use SoftDeletes;

    const TYPE_PERCENT  = 'percent';   // % off subtotal
    const TYPE_FLAT     = 'flat';      // Fixed BDT off
    const TYPE_SHIPPING = 'shipping';  // Free shipping

    protected $fillable = [
        'code', 'type', 'value',
        'min_order_amount',
        'max_discount_amount',   // Cap on percent discounts
        'usage_limit',           // Total uses allowed
        'usage_limit_per_user',  // Per-user cap
        'used_count',
        'applicable_product_ids',   // JSON — null = all products
        'applicable_category_ids',  // JSON — null = all categories
        'applicable_customer_ids',  // JSON — null = all customers
        'is_active',
        'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'value'                    => 'decimal:2',
            'min_order_amount'         => 'decimal:2',
            'max_discount_amount'      => 'decimal:2',
            'is_active'                => 'boolean',
            'applicable_product_ids'   => 'array',
            'applicable_category_ids'  => 'array',
            'applicable_customer_ids'  => 'array',
            'starts_at'                => 'datetime',
            'ends_at'                  => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(fn($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function isExhausted(): bool
    {
        return $this->usage_limit && $this->used_count >= $this->usage_limit;
    }

    public function isValid(): bool
    {
        return $this->is_active
            && ! $this->isExhausted()
            && (! $this->starts_at || now()->gte($this->starts_at))
            && (! $this->ends_at   || now()->lte($this->ends_at));
    }
}
