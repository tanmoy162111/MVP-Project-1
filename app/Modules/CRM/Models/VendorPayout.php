<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * VendorPayout
 * Records each payout disbursement to a vendor.
 * Calculated from delivered OrderItems minus platform commission.
 */
class VendorPayout extends Model
{
    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_FAILED     = 'failed';

    protected $fillable = [
        'vendor_id',
        'period_from', 'period_to',
        'gross_sales',        // Total sales before commission
        'commission_amount',  // Platform's cut
        'net_amount',         // What vendor receives
        'currency',
        'status',
        'payment_method',     // bank_transfer | bkash | etc.
        'transaction_ref',
        'notes',
        'processed_at', 'approved_by',
        'order_item_ids',     // JSON array of included OrderItem IDs
    ];

    protected function casts(): array
    {
        return [
            'gross_sales'       => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'net_amount'        => 'decimal:2',
            'period_from'       => 'date',
            'period_to'         => 'date',
            'processed_at'      => 'datetime',
            'order_item_ids'    => 'array',
        ];
    }

    public function vendor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Vendor\Models\Vendor::class);
    }

    public function approvedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\User\Models\User::class, 'approved_by');
    }

    public function isPending(): bool    { return $this->status === self::STATUS_PENDING; }
    public function isCompleted(): bool  { return $this->status === self::STATUS_COMPLETED; }
}
