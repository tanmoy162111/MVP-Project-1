<?php

namespace App\Modules\Product\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Services\ProductService;
use App\Modules\Product\Requests\CreateProductRequest;
use App\Modules\Product\Requests\UpdateProductRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private ProductService $productService) {}

    /**
     * GET /api/v1/products
     * Public storefront — active products only with rich filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['vendor:id,store_name,slug', 'category:id,name,slug', 'brand:id,name', 'primaryImage'])
            ->active()
            ->when($request->category_id, fn($q, $v) => $q->where('category_id', $v))
            ->when($request->brand_id,    fn($q, $v) => $q->where('brand_id', $v))
            ->when($request->vendor_id,   fn($q, $v) => $q->where('vendor_id', $v))
            ->when($request->condition,   fn($q, $v) => $q->where('condition', $v))
            ->when($request->featured,    fn($q)     => $q->featured())
            ->when($request->min_price,   fn($q, $v) => $q->where('base_price', '>=', $v))
            ->when($request->max_price,   fn($q, $v) => $q->where('base_price', '<=', $v))
            ->when($request->search,      fn($q, $v) =>
                $q->where('name', 'like', "%{$v}%")
                  ->orWhere('short_description', 'like', "%{$v}%")
                  ->orWhere('sku', 'like', "%{$v}%")
            )
            ->when($request->sort === 'price_asc',    fn($q) => $q->orderBy('base_price'))
            ->when($request->sort === 'price_desc',   fn($q) => $q->orderByDesc('base_price'))
            ->when($request->sort === 'newest',       fn($q) => $q->latest())
            ->when($request->sort === 'popular',      fn($q) => $q->orderByDesc('sales_count'))
            ->when(! $request->sort,                  fn($q) => $q->latest());

        return $this->paginated($query->paginate($request->integer('per_page', 20)));
    }

    /**
     * GET /api/v1/products/{id}
     * Public — product detail with all relations.
     */
    public function show(int $id): JsonResponse
    {
        $product = Product::with([
            'vendor:id,store_name,slug,average_rating,logo',
            'category:id,name,slug',
            'brand:id,name,logo',
            'variants',
            'attributes',
            'images',
        ])->active()->findOrFail($id);

        $this->productService->incrementViewCount($product);

        return $this->success($this->formatProduct($product, detailed: true));
    }

    /**
     * POST /api/v1/vendor/products
     * Vendor creates a new product listing.
     */
    public function store(CreateProductRequest $request): JsonResponse
    {
        $vendor = $request->user()->vendor;

        if (! $vendor || ! $vendor->isActive()) {
            return $this->forbidden('Only active vendors can create product listings.');
        }

        $product = $this->productService->create($request->validated(), $vendor->id);

        return $this->created(
            $this->formatProduct($product, detailed: true),
            'Product submitted for review.'
        );
    }

    /**
     * PUT /api/v1/vendor/products/{id}
     * Vendor updates own product.
     */
    public function update(UpdateProductRequest $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $user    = $request->user();

        if (! $user->hasRole(['admin', 'super_admin']) && $product->vendor->user_id !== $user->id) {
            return $this->forbidden('You can only edit your own products.');
        }

        $product = $this->productService->update($product, $request->validated());

        return $this->success($this->formatProduct($product, detailed: true), 'Product updated.');
    }

    /**
     * DELETE /api/v1/vendor/products/{id}
     * Soft delete — vendor own products only.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $user    = $request->user();

        if (! $user->hasRole(['admin', 'super_admin']) && $product->vendor->user_id !== $user->id) {
            return $this->forbidden('You can only delete your own products.');
        }

        $product->delete();

        return $this->noContent();
    }

    /**
     * GET /api/v1/admin/products
     * Admin listing — all statuses with filters.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = Product::with(['vendor:id,store_name', 'category:id,name', 'brand:id,name'])
            ->when($request->status,    fn($q, $v) => $q->where('status', $v))
            ->when($request->vendor_id, fn($q, $v) => $q->where('vendor_id', $v))
            ->when($request->search,    fn($q, $v) =>
                $q->where('name', 'like', "%{$v}%")->orWhere('sku', 'like', "%{$v}%")
            )
            ->latest();

        return $this->paginated($query->paginate($request->integer('per_page', 20)));
    }

    /**
     * POST /api/v1/admin/products/{id}/approve
     */
    public function approve(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        if ($product->status === 'active') {
            return $this->badRequest('Product is already active.');
        }

        $product = $this->productService->approve($product);

        return $this->success($this->formatProduct($product), 'Product approved and now live.');
    }

    /**
     * POST /api/v1/admin/products/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'required|string|min:10']);

        $product = Product::findOrFail($id);
        $product = $this->productService->reject($product, $request->reason);

        return $this->success($this->formatProduct($product), 'Product rejected.');
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────────

    private function formatProduct(Product $product, bool $detailed = false): array
    {
        $base = [
            'id'          => $product->id,
            'name'        => $product->name,
            'slug'        => $product->slug,
            'sku'         => $product->sku,
            'base_price'  => $product->base_price,
            'condition'   => $product->condition,
            'status'      => $product->status,
            'is_featured' => $product->is_featured,
            'warranty_period' => $product->warranty_period,
            'primary_image'   => $product->relationLoaded('primaryImage') ? $product->primaryImage?->path : null,
            'vendor'      => $product->relationLoaded('vendor') ? [
                'id'   => $product->vendor->id,
                'name' => $product->vendor->store_name,
                'slug' => $product->vendor->slug,
            ] : null,
            'category' => $product->relationLoaded('category') ? [
                'id'   => $product->category->id,
                'name' => $product->category->name,
            ] : null,
            'brand' => $product->relationLoaded('brand') && $product->brand ? [
                'id'   => $product->brand->id,
                'name' => $product->brand->name,
            ] : null,
        ];

        if (! $detailed) {
            return $base;
        }

        return array_merge($base, [
            'short_description' => $product->short_description,
            'description'       => $product->description,
            'weight'            => $product->weight,
            'dimensions'        => $product->dimensions,
            'warranty_terms'    => $product->warranty_terms,
            'view_count'        => $product->view_count,
            'sales_count'       => $product->sales_count,
            'variants'  => $product->relationLoaded('variants')
                ? $product->variants->map(fn($v) => [
                    'id'               => $v->id,
                    'sku'              => $v->sku,
                    'name'             => $v->name,
                    'attributes'       => $v->attributes,
                    'price_adjustment' => $v->price_adjustment,
                    'effective_price'  => $v->effectivePrice(),
                    'stock_quantity'   => $v->stock_quantity,
                    'is_active'        => $v->is_active,
                    'is_in_stock'      => $v->isInStock(),
                    'is_low_stock'     => $v->isLowStock(),
                ]) : [],
            'attributes' => $product->relationLoaded('attributes')
                ? $product->attributes->map(fn($a) => [
                    'name'  => $a->attribute_name,
                    'value' => $a->attribute_value,
                ]) : [],
            'images' => $product->relationLoaded('images')
                ? $product->images->map(fn($i) => [
                    'path'       => $i->path,
                    'alt_text'   => $i->alt_text,
                    'is_primary' => $i->is_primary,
                ]) : [],
        ]);
    }
}
