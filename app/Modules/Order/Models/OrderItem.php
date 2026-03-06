<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'vendor_id', 'product_id', 'variant_id',
        'product_name', 'variant_name', 'sku',
        'quantity', 'unit_price', 'total_price', 'vendor_payout',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'unit_price'    => 'decimal:2',
            'total_price'   => 'decimal:2',
            'vendor_payout' => 'decimal:2',
        ];
    }

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Product\Models\Product::class);
    }

    public function variant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Product\Models\ProductVariant::class);
    }

    public function vendor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Vendor\Models\Vendor::class);
    }
}
