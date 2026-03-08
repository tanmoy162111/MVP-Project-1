<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Wishlist
 * Customer saves products for later. One row per customer+product combo.
 */
class Wishlist extends Model
{
    protected $fillable = ['customer_id', 'product_id', 'variant_id', 'note'];

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\User\Models\User::class, 'customer_id');
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Product\Models\Product::class);
    }

    public function variant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Product\Models\ProductVariant::class);
    }
}
