<?php

namespace App\Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * B2B customer contracts — negotiated prices per product/category.
 */
class CustomerContract extends Model
{
    const STATUS_DRAFT      = 'draft';
    const STATUS_ACTIVE     = 'active';
    const STATUS_EXPIRED    = 'expired';
    const STATUS_TERMINATED = 'terminated';

    protected $fillable = [
        'customer_id', 'vendor_id', 'contract_number',
        'price_overrides', 'credit_limit', 'payment_terms',
        'document_path', 'status', 'start_date', 'end_date',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'price_overrides' => 'array',
            'credit_limit'    => 'decimal:2',
            'start_date'      => 'date',
            'end_date'        => 'date',
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
            && now()->between($this->start_date, $this->end_date ?? now()->addYear());
    }

    public function scopeActiveNow($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
                     ->where('start_date', '<=', now())
                     ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()));
    }
}
