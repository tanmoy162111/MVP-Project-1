<?php

namespace App\Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pricing rules — global defaults or scoped to category/vendor/customer tier.
 */
class PricingRule extends Model
{
    const TYPE_MARGIN    = 'margin';
    const TYPE_FREIGHT   = 'freight';
    const TYPE_TAX       = 'tax';
    const TYPE_VOLUME    = 'volume_discount';
    const TYPE_TIER      = 'customer_tier';
    const TYPE_CONTRACT  = 'contract';

    const SCOPE_ALL      = 'all';
    const SCOPE_CATEGORY = 'category';
    const SCOPE_VENDOR   = 'vendor';
    const SCOPE_PRODUCT  = 'product';
    const SCOPE_TIER     = 'customer_tier';

    protected $fillable = [
        'name', 'type', 'applies_to', 'applies_to_id',
        'rule_config', 'is_active', 'priority',
        'valid_from', 'valid_to', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rule_config' => 'array',
            'is_active'   => 'boolean',
            'valid_from'  => 'datetime',
            'valid_to'    => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->where(fn($q) =>
                         $q->whereNull('valid_from')->orWhere('valid_from', '<=', now())
                     )
                     ->where(fn($q) =>
                         $q->whereNull('valid_to')->orWhere('valid_to', '>=', now())
                     );
    }

    // Alias methods for compatibility
    public function getValueAttribute()
    {
        return $this->rule_config['margin_percent'] ?? $this->rule_config['discount_percent'] ?? 0;
    }

    public function getScopeAttribute()
    {
        return $this->applies_to;
    }

    public function getScopeIdAttribute()
    {
        return $this->applies_to_id;
    }
}
