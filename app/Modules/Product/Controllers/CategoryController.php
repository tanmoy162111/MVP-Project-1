<?php

namespace App\Modules\Product\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * GET /api/v1/categories
     * Public — full category tree.
     */
    public function index(): JsonResponse
    {
        $categories = Category::with('children.children')
            ->active()
            ->roots()
            ->orderBy('sort_order')
            ->get();

        return $this->success($categories->map(fn($c) => $this->formatCategory($c, withChildren: true)));
    }

    /**
     * GET /api/v1/categories/{id}
     */
    public function show(int $id): JsonResponse
    {
        $category = Category::with('children', 'parent')->findOrFail($id);
        return $this->success($this->formatCategory($category, withChildren: true));
    }

    /**
     * POST /api/v1/admin/categories
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'               => 'required|string|max:100',
            'parent_id'          => 'nullable|exists:categories,id',
            'image'              => 'nullable|string',
            'attribute_template' => 'nullable|array',
            'sort_order'         => 'nullable|integer|min:0',
            'is_active'          => 'nullable|boolean',
        ]);

        $data['slug'] = Str::slug($data['name']);

        $category = Category::create($data);

        return $this->created($this->formatCategory($category));
    }

    /**
     * PUT /api/v1/admin/categories/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $data = $request->validate([
            'name'               => 'sometimes|string|max:100',
            'parent_id'          => 'nullable|exists:categories,id',
            'image'              => 'nullable|string',
            'attribute_template' => 'nullable|array',
            'sort_order'         => 'nullable|integer|min:0',
            'is_active'          => 'nullable|boolean',
        ]);

        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $category->update($data);

        return $this->success($this->formatCategory($category->fresh()), 'Category updated.');
    }

    /**
     * DELETE /api/v1/admin/categories/{id}
     * Only allowed if no products are attached.
     */
    public function destroy(int $id): JsonResponse
    {
        $category = Category::withCount('products')->findOrFail($id);

        if ($category->products_count > 0) {
            return $this->badRequest("Cannot delete category — it has {$category->products_count} products attached.");
        }

        $category->delete();

        return $this->noContent();
    }

    private function formatCategory(Category $category, bool $withChildren = false): array
    {
        $data = [
            'id'                 => $category->id,
            'name'               => $category->name,
            'slug'               => $category->slug,
            'image'              => $category->image,
            'parent_id'          => $category->parent_id,
            'attribute_template' => $category->attribute_template,
            'sort_order'         => $category->sort_order,
            'is_active'          => $category->is_active,
        ];

        if ($withChildren && $category->relationLoaded('children')) {
            $data['children'] = $category->children->map(fn($c) => $this->formatCategory($c, withChildren: true));
        }

        return $data;
    }
}
