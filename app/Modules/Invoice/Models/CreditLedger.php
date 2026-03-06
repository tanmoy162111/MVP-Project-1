<?php

namespace App\Modules\Invoice\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only ledger — rows are NEVER updated after insert.
 * balance_after stores a running snapshot for fast balance reads.
 */
class CreditLedger extends Model
{
    protected $table = 'credit_ledger';

    protected $fillable = [
        'customer_id', 'type', 'amount', 'balance_after',
        'reference_type', 'reference_id', 'created_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'amount'        => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\User\Models\User::class, 'customer_id');
    }
}
