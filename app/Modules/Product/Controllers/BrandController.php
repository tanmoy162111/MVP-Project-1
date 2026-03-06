<?php

namespace App\Modules\Product\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BrandController extends Controller
{
    /**
     * GET /api/v1/brands — Public listing.
     */
    public function index(): JsonResponse
    {
        $brands = Brand::active()->orderBy('name')->get(['id', 'name', 'slug', 'logo']);
        return $this->success($brands);
    }

    /**
     * POST /api/v1/admin/brands
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => 'required|string|max:100|unique:brands,name',
            'logo'      => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $data['slug'] = Str::slug($data['name']);

        $brand = Brand::create($data);

        return $this->created($brand, 'Brand created.');
    }

    /**
     * PUT /api/v1/admin/brands/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $brand = Brand::findOrFail($id);

        $data = $request->validate([
            'name'      => "sometimes|string|max:100|unique:brands,name,{$id}",
            'logo'      => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $brand->update($data);

        return $this->success($brand->fresh(), 'Brand updated.');
    }

    /**
     * DELETE /api/v1/admin/brands/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $brand = Brand::withCount('products')->findOrFail($id);

        if ($brand->products_count > 0) {
            return $this->badRequest("Cannot delete brand — {$brand->products_count} products are using it.");
        }

        $brand->delete();

        return $this->noContent();
    }
}
