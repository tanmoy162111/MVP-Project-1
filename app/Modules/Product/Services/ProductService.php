<?php

namespace App\Modules\Product\Services;

use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductVariant;
use App\Modules\Product\Models\ProductAttribute;
use App\Modules\Product\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    /**
     * Create a product with variants and attributes in one transaction.
     */
    public function create(array $data, int $vendorId): Product
    {
        return DB::transaction(function () use ($data, $vendorId) {
            $product = Product::create([
                'vendor_id'         => $vendorId,
                'category_id'       => $data['category_id'],
                'brand_id'          => $data['brand_id'] ?? null,
                'name'              => $data['name'],
                'slug'              => $this->generateSlug($data['name']),
                'short_description' => $data['short_description'] ?? null,
                'description'       => $data['description'] ?? null,
                'sku'               => $data['sku'] ?? $this->generateSku($data['name']),
                'base_price'        => $data['base_price'],
                'cost_price'        => $data['cost_price'] ?? null,
                'status'            => 'pending_review', // always starts pending
                'condition'         => $data['condition'] ?? 'new',
                'weight'            => $data['weight'] ?? null,
                'dimensions'        => $data['dimensions'] ?? null,
                'warranty_period'   => $data['warranty_period'] ?? null,
                'warranty_terms'    => $data['warranty_terms'] ?? null,
                'meta_title'        => $data['meta_title'] ?? $data['name'],
                'meta_description'  => $data['meta_description'] ?? $data['short_description'] ?? null,
            ]);

            // Create variants if provided
            if (! empty($data['variants'])) {
                foreach ($data['variants'] as $variantData) {
                    ProductVariant::create([
                        'product_id'          => $product->id,
                        'sku'                 => $variantData['sku'] ?? $this->generateSku($data['name'] . '-' . implode('-', $variantData['attributes'])),
                        'name'                => $variantData['name'] ?? null,
                        'attributes'          => $variantData['attributes'],
                        'price_adjustment'    => $variantData['price_adjustment'] ?? 0,
                        'stock_quantity'      => $variantData['stock_quantity'] ?? 0,
                        'low_stock_threshold' => $variantData['low_stock_threshold'] ?? 5,
                        'is_active'           => true,
                    ]);
                }
            }

            // Create attributes (specs)
            if (! empty($data['attributes'])) {
                foreach ($data['attributes'] as $index => $attr) {
                    ProductAttribute::create([
                        'product_id'      => $product->id,
                        'attribute_name'  => $attr['name'],
                        'attribute_value' => $attr['value'],
                        'sort_order'      => $index,
                    ]);
                }
            }

            return $product->load(['variants', 'attributes', 'images']);
        });
    }

    /**
     * Update product details.
     */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            if (isset($data['name']) && $data['name'] !== $product->name) {
                $data['slug'] = $this->generateSlug($data['name']);
            }

            // If vendor edits an active product, send it back to pending review
            if ($product->status === 'active') {
                $data['status'] = 'pending_review';
            }

            $product->update($data);

            // Sync attributes if provided
            if (isset($data['attributes'])) {
                $product->attributes()->delete();
                foreach ($data['attributes'] as $index => $attr) {
                    ProductAttribute::create([
                        'product_id'      => $product->id,
                        'attribute_name'  => $attr['name'],
                        'attribute_value' => $attr['value'],
                        'sort_order'      => $index,
                    ]);
                }
            }

            return $product->fresh()->load(['variants', 'attributes', 'images']);
        });
    }

    /**
     * Admin approves a product listing.
     */
    public function approve(Product $product): Product
    {
        $product->update(['status' => 'active']);
        return $product->fresh();
    }

    /**
     * Admin rejects a product listing.
     */
    public function reject(Product $product, string $reason): Product
    {
        $product->update(['status' => 'rejected']);
        // Store rejection reason in meta_description as a temporary note
        // (Phase 3 will add a proper rejection_reason column)
        return $product->fresh();
    }

    /**
     * Increment view count (called on product detail page).
     * Uses DB increment to avoid race conditions.
     */
    public function incrementViewCount(Product $product): void
    {
        Product::where('id', $product->id)->increment('view_count');
    }

    private function generateSlug(string $name): string
    {
        $slug  = Str::slug($name);
        $count = Product::where('slug', 'like', "{$slug}%")->count();
        return $count > 0 ? "{$slug}-{$count}" : $slug;
    }

    private function generateSku(string $name): string
    {
        return strtoupper(Str::substr(Str::slug($name), 0, 8)) . '-' . strtoupper(Str::random(4));
    }
}
