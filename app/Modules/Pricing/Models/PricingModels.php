<?php

namespace App\Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cached OPIS feed prices.
 * Refreshed by OpisFeedService on a schedule (every 30 min by default).
 * The raw OPIS payload is stored in raw_data for debugging.
 */
class OpisPrice extends Model
{
    protected $table = 'opis_prices';

    protected $fillable = [
        'product_sku', 'vendor_id', 'opis_price',
        'currency', 'valid_from', 'valid_until',
        'raw_data', 'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'opis_price'  => 'decimal:4',
            'raw_data'    => 'array',
            'valid_from'  => 'datetime',
            'valid_until' => 'datetime',
            'fetched_at'  => 'datetime',
        ];
    }

    public function vendor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Vendor\Models\Vendor::class);
    }

    public function isExpired(): bool
    {
        return $this->valid_until && now()->isAfter($this->valid_until);
    }
}


/**
 * Pricing rules — global defaults or scoped to category/vendor/customer tier.
 * Rules stack and are applied in priority order (higher priority wins for
 * overlapping rules at the same scope level).
 */
class PricingRule extends Model
{
    const TYPE_MARGIN    = 'margin';       // % added on top of OPIS price
    const TYPE_FLAT      = 'flat';         // Fixed BDT amount added
    const TYPE_DISCOUNT  = 'discount';     // % discount off base price
    const TYPE_VOLUME    = 'volume';       // Tiered quantity breaks

    const SCOPE_GLOBAL   = 'global';
    const SCOPE_CATEGORY = 'category';
    const SCOPE_VENDOR   = 'vendor';
    const SCOPE_PRODUCT  = 'product';
    const SCOPE_TIER     = 'customer_tier';

    protected $fillable = [
        'name', 'type', 'scope', 'scope_id',
        'value',           // % or flat BDT depending on type
        'min_qty',         // for volume rules
        'max_qty',
        'customer_tier',   // bronze/silver/gold/platinum
        'priority',        // higher = applied first
        'is_active',
        'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'value'     => 'decimal:4',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at'   => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->where(fn($q) =>
                         $q->whereNull('starts_at')->orWhere('starts_at', '<=', now())
                     )
                     ->where(fn($q) =>
                         $q->whereNull('ends_at')->orWhere('ends_at', '>=', now())
                     );
    }
}


/**
 * B2B customer contracts — negotiated prices per product/category.
 * These override standard pricing rules entirely for the named customer.
 */
class CustomerContract extends Model
{
    const STATUS_ACTIVE    = 'active';
    const STATUS_EXPIRED   = 'expired';
    const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'customer_id', 'vendor_id',
        'contract_number',
        'scope',            // product | category | global
        'scope_id',
        'price_type',       // fixed | margin_over_opis | discount_off_list
        'price_value',
        'currency',
        'min_order_qty',
        'max_order_qty',
        'credit_limit',
        'payment_terms',    // net_7 | net_15 | net_30 | net_60 | cod
        'status',
        'starts_at', 'ends_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'price_value'  => 'decimal:4',
            'credit_limit' => 'decimal:2',
            'starts_at'    => 'datetime',
            'ends_at'      => 'datetime',
        ];
    }

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\User\Models\User::class, 'customer_id');
    }

    public function vendor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Vendor\Models\Vendor::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && now()->between($this->starts_at, $this->ends_at);
    }

    public function scopeActiveNow($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
                     ->where('starts_at', '<=', now())
                     ->where('ends_at', '>=', now());
    }
}


/**
 * Immutable audit trail of every price calculation.
 * Rows are INSERT-only — never updated.
 */
class PricingAuditLog extends Model
{
    public $timestamps = false;
    const UPDATED_AT = null;

    protected $fillable = [
        'product_id', 'variant_id', 'customer_id',
        'opis_price', 'base_price', 'final_price', 'currency',
        'rules_applied',    // JSON: array of rule IDs + names that affected the price
        'contract_id',
        'quantity',
        'calculated_at',
        'channel',          // storefront | checkout | api | admin
    ];

    protected function casts(): array
    {
        return [
            'opis_price'    => 'decimal:4',
            'base_price'    => 'decimal:2',
            'final_price'   => 'decimal:2',
            'rules_applied' => 'array',
            'calculated_at' => 'datetime',
        ];
    }
}
