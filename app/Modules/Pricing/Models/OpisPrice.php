<?php

namespace App\Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cached OPIS feed prices.
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
