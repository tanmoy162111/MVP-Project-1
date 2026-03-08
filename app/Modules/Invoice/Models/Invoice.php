<?php

namespace App\Modules\Invoice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Invoice
 *
 * Auto-generated when an order is confirmed.
 * Once is_locked = true (payment received or overdue), no edits are allowed.
 * The line_items JSON is a snapshot — immune to product/price changes.
 */
class Invoice extends Model
{
    use SoftDeletes;

    const STATUS_DRAFT    = 'draft';
    const STATUS_ISSUED   = 'issued';
    const STATUS_PARTIAL  = 'partial';
    const STATUS_PAID     = 'paid';
    const STATUS_OVERDUE  = 'overdue';
    const STATUS_CANCELLED= 'cancelled';
    const STATUS_VOID     = 'void';

    protected $fillable = [
        'invoice_number', 'order_id', 'customer_id', 'vendor_id',
        'status', 'currency',
        'subtotal', 'discount_amount', 'tax_amount', 'freight_cost', 'total_amount', 'amount_paid', 'balance_due',
        'line_items',           // JSON snapshot of order items at invoice time
        'billing_address',      // JSON
        'notes',
        'payment_terms',        // net_7 | net_15 | net_30 | net_60 | cod
        'issued_at', 'due_at', 'paid_at',
        'is_locked',
        'pdf_path',             // Stored path to generated PDF
        'sent_at',              // When invoice was emailed to customer
    ];

    protected function casts(): array
    {
        return [
            'subtotal'        => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount'      => 'decimal:2',
            'freight_cost'    => 'decimal:2',
            'total_amount'    => 'decimal:2',
            'amount_paid'     => 'decimal:2',
            'balance_due'     => 'decimal:2',
            'line_items'      => 'array',
            'billing_address' => 'array',
            'is_locked'       => 'boolean',
            'issued_at'       => 'datetime',
            'due_at'          => 'datetime',
            'paid_at'         => 'datetime',
            'sent_at'         => 'datetime',
        ];
    }

    // ── RELATIONSHIPS ──────────────────────────────────────────────────────────

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Order\Models\Order::class);
    }

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\User\Models\User::class, 'customer_id');
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // ── HELPERS ────────────────────────────────────────────────────────────────

    public function isOverdue(): bool
    {
        return ! $this->isPaid()
            && $this->due_at
            && now()->isAfter($this->due_at);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isLocked(): bool
    {
        return (bool) $this->is_locked;
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotIn('status', [self::STATUS_PAID, self::STATUS_VOID, self::STATUS_CANCELLED])
                     ->where('due_at', '<', now());
    }

    public function scopeUnpaid($query)
    {
        return $query->whereIn('status', [self::STATUS_ISSUED, self::STATUS_PARTIAL, self::STATUS_OVERDUE]);
    }
}
