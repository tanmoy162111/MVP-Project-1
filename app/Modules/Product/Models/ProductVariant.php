<?php

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id', 'sku', 'name', 'attributes',
        'price_adjustment', 'stock_quantity', 'low_stock_threshold',
        'image', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'attributes'       => 'array',
            'price_adjustment' => 'decimal:2',
            'is_active'        => 'boolean',
        ];
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function effectivePrice(): float
    {
        return (float) $this->product->base_price + (float) $this->price_adjustment;
    }

    public function isInStock(): bool
    {
        return $this->stock_quantity > 0;
    }

    public function isLowStock(): bool
    {
        return $this->stock_quantity > 0 && $this->stock_quantity <= $this->low_stock_threshold;
    }
}


class ProductAttribute extends Model
{
    protected $fillable = ['product_id', 'attribute_name', 'attribute_value', 'sort_order'];

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}


class ProductImage extends Model
{
    protected $fillable = ['product_id', 'path', 'alt_text', 'sort_order', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
