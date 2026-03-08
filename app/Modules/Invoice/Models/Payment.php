<?php

namespace App\Modules\Invoice\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Payment
 *
 * One invoice can have multiple partial payments.
 * Each payment records the gateway response for audit purposes.
 */
class Payment extends Model
{
    const METHOD_BKASH       = 'bkash';
    const METHOD_NAGAD       = 'nagad';
    const METHOD_SSLCOMMERZ  = 'sslcommerz';
    const METHOD_BANK        = 'bank_transfer';
    const METHOD_COD         = 'cod';
    const METHOD_CREDIT      = 'credit_account';
    const METHOD_MOCK        = 'mock';  // used when gateway credentials not set

    const STATUS_PENDING   = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';
    const STATUS_REFUNDED  = 'refunded';

    protected $fillable = [
        'invoice_id', 'order_id', 'customer_id',
        'method', 'status', 'currency', 'amount',
        'transaction_id',       // Gateway transaction reference
        'gateway_reference',    // Our internal reference
        'gateway_response',     // Full JSON response from gateway
        'notes',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'           => 'decimal:2',
            'gateway_response' => 'array',
            'processed_at'     => 'datetime',
        ];
    }

    public function invoice(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\User\Models\User::class, 'customer_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
