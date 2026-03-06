<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ledger model — rows are never updated, only inserted.
 * The balance_after column provides a running total snapshot.
 */
class InventoryMovement extends Model
{
    protected $fillable = [
        'product_id', 'variant_id', 'type', 'quantity',
        'balance_after', 'reference_type', 'reference_id',
        'created_by', 'note',
    ];

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Product\Models\Product::class);
    }

    public function variant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Product\Models\ProductVariant::class);
    }
}
