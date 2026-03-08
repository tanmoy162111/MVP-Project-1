<?php

namespace App\Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable audit trail of every price calculation.
 */
class PricingAuditLog extends Model
{
    public $timestamps = false;
    const UPDATED_AT = null;

    protected $fillable = [
        'product_id', 'variant_id', 'customer_id',
        'opis_price', 'base_price', 'final_price', 'currency',
        'rules_applied', 'contract_id', 'quantity',
        'calculated_at', 'channel',
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
