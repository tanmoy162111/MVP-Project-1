<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use SoftDeletes, HasFactory;

    // ── STATUS CONSTANTS ──────────────────────────────────────────────────────
    const STATUS_PENDING          = 'pending';
    const STATUS_PAYMENT_PENDING  = 'payment_pending';
    const STATUS_CONFIRMED        = 'confirmed';
    const STATUS_PROCESSING       = 'processing';
    const STATUS_SHIPPED          = 'shipped';
    const STATUS_DELIVERED        = 'delivered';
    const STATUS_CANCELLED        = 'cancelled';
    const STATUS_REFUND_REQUESTED = 'refund_requested';
    const STATUS_REFUNDED         = 'refunded';
    const STATUS_ON_HOLD          = 'on_hold';

    // ── PAYMENT STATUS CONSTANTS ──────────────────────────────────────────────
    const PAYMENT_PENDING  = 'pending';
    const PAYMENT_PARTIAL  = 'partial';
    const PAYMENT_PAID     = 'paid';
    const PAYMENT_REFUNDED = 'refunded';

    protected $fillable = [
        'order_number', 'customer_id', 'status',
        'subtotal', 'freight_cost', 'tax_amount', 'discount_amount', 'total_amount',
        'price_snapshot',
        'payment_method', 'payment_status',
        'shipping_address', 'delivery_notes',
        'delivered_at', 'approved_by', 'approved_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal'         => 'decimal:2',
            'freight_cost'     => 'decimal:2',
            'tax_amount'       => 'decimal:2',
            'discount_amount'  => 'decimal:2',
            'total_amount'     => 'decimal:2',
            'price_snapshot'   => 'array',
            'shipping_address' => 'array',
            'delivered_at'     => 'datetime',
            'approved_at'      => 'datetime',
        ];
    }

    // ── RELATIONSHIPS ─────────────────────────────────────────────────────────

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\User\Models\User::class, 'customer_id');
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest();
    }

    public function approvedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\User\Models\User::class, 'approved_by');
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    public function isPending(): bool    { return $this->status === self::STATUS_PENDING; }
    public function isConfirmed(): bool  { return $this->status === self::STATUS_CONFIRMED; }
    public function isCancelled(): bool  { return $this->status === self::STATUS_CANCELLED; }
    public function isDelivered(): bool  { return $this->status === self::STATUS_DELIVERED; }
    public function isPaid(): bool       { return $this->payment_status === self::PAYMENT_PAID; }
}
