<?php

namespace Tests\Feature;

use App\Modules\User\Models\User;
use App\Modules\Vendor\Models\Vendor;
use App\Modules\Product\Models\Category;
use App\Modules\Product\Models\Brand;
use App\Modules\Product\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorProductTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $vendorUser;
    protected Vendor $vendor;
    protected Category $category;
    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // Admin
        $this->admin = User::factory()->admin()->create();
        $this->admin->assignRole('super_admin');

        // Vendor user + approved vendor profile
        $this->vendorUser = User::factory()->vendor()->create();
        $this->vendorUser->assignRole('vendor');
        $this->vendor = Vendor::create([
            'user_id'    => $this->vendorUser->id,
            'store_name' => 'Tech Store BD',
            'slug'       => 'tech-store-bd',
            'status'     => 'active',
            'commission_rate' => 10,
        ]);

        // Catalogue setup
        $this->category = Category::create(['name' => 'Smartphones', 'slug' => 'smartphones', 'is_active' => true]);
        $this->brand     = Brand::create(['name' => 'Samsung', 'slug' => 'samsung', 'is_active' => true]);
    }

    // ── VENDOR REGISTRATION ───────────────────────────────────────────────────

    public function test_authenticated_user_can_register_as_vendor(): void
    {
        $user = User::factory()->create(['type' => 'customer', 'status' => 'active']);
        $user->assignRole('customer');

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/vendor/register', [
            'store_name' => 'My Electronics Store',
            'phone'      => '01700000000',
            'address'    => 'Dhaka, Bangladesh',
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', 'pending')
                 ->assertJsonPath('data.store_name', 'My Electronics Store');

        $this->assertDatabaseHas('vendors', ['store_name' => 'My Electronics Store', 'status' => 'pending']);
    }

    public function test_vendor_cannot_register_twice(): void
    {
        $response = $this->actingAs($this->vendorUser, 'sanctum')->postJson('/api/v1/vendor/register', [
            'store_name' => 'Another Store',
        ]);

        $response->assertStatus(400);
    }

    // ── VENDOR APPROVAL ───────────────────────────────────────────────────────

    public function test_admin_can_approve_vendor(): void
    {
        $pendingVendor = Vendor::create([
            'user_id'    => User::factory()->create()->id,
            'store_name' => 'Pending Store',
            'slug'       => 'pending-store',
            'status'     => 'pending',
            'commission_rate' => 10,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson("/api/v1/admin/vendors/{$pendingVendor->id}/approve");

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'active');
    }

    public function test_admin_can_reject_vendor_with_reason(): void
    {
        $pendingVendor = Vendor::create([
            'user_id'    => User::factory()->create()->id,
            'store_name' => 'Reject Me',
            'slug'       => 'reject-me',
            'status'     => 'pending',
            'commission_rate' => 10,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson("/api/v1/admin/vendors/{$pendingVendor->id}/reject", [
                             'reason' => 'Trade license document is missing or invalid.',
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'rejected');
    }

    public function test_reject_requires_reason(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson("/api/v1/admin/vendors/{$this->vendor->id}/reject", []);

        $response->assertStatus(422);
    }

    // ── PRODUCT CREATION ──────────────────────────────────────────────────────

    public function test_vendor_can_create_product_with_variants(): void
    {
        $response = $this->actingAs($this->vendorUser, 'sanctum')->postJson('/api/v1/vendor/products', [
            'category_id'       => $this->category->id,
            'brand_id'          => $this->brand->id,
            'name'              => 'Samsung Galaxy S24',
            'short_description' => 'Flagship Android smartphone',
            'base_price'        => 85000,
            'cost_price'        => 70000,
            'condition'         => 'new',
            'warranty_period'   => '1 year',
            'variants' => [
                ['attributes' => ['ram' => '8GB', 'storage' => '128GB'], 'price_adjustment' => 0,     'stock_quantity' => 10],
                ['attributes' => ['ram' => '12GB','storage' => '256GB'], 'price_adjustment' => 8000,  'stock_quantity' => 5],
            ],
            'attributes' => [
                ['name' => 'Processor',  'value' => 'Snapdragon 8 Gen 3'],
                ['name' => 'Display',    'value' => '6.2 inch Dynamic AMOLED'],
                ['name' => 'Battery',    'value' => '4000mAh'],
            ],
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', 'pending_review')
                 ->assertJsonPath('data.name', 'Samsung Galaxy S24');

        $this->assertDatabaseHas('products', ['name' => 'Samsung Galaxy S24', 'status' => 'pending_review']);
        $this->assertDatabaseCount('product_variants', 2);
        $this->assertDatabaseCount('product_attributes', 3);
    }

    public function test_non_active_vendor_cannot_create_product(): void
    {
        $inactiveUser = User::factory()->create(['type' => 'vendor', 'status' => 'active']);
        $inactiveUser->assignRole('vendor');
        Vendor::create([
            'user_id' => $inactiveUser->id, 'store_name' => 'Inactive',
            'slug' => 'inactive', 'status' => 'pending', 'commission_rate' => 10,
        ]);

        $response = $this->actingAs($inactiveUser, 'sanctum')->postJson('/api/v1/vendor/products', [
            'category_id' => $this->category->id,
            'name'        => 'Test Product',
            'base_price'  => 1000,
        ]);

        $response->assertStatus(403);
    }

    // ── PRODUCT APPROVAL ──────────────────────────────────────────────────────

    public function test_admin_can_approve_product(): void
    {
        $product = Product::create([
            'vendor_id'   => $this->vendor->id,
            'category_id' => $this->category->id,
            'name'        => 'Pending Product',
            'slug'        => 'pending-product',
            'sku'         => 'PEND-001',
            'base_price'  => 5000,
            'status'      => 'pending_review',
            'condition'   => 'new',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson("/api/v1/admin/products/{$product->id}/approve");

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'active');
    }

    // ── STOREFRONT ────────────────────────────────────────────────────────────

    public function test_storefront_shows_only_active_products(): void
    {
        Product::create(['vendor_id' => $this->vendor->id, 'category_id' => $this->category->id,
            'name' => 'Active Product',   'slug' => 'active-prod',   'sku' => 'ACT-001', 'base_price' => 1000, 'status' => 'active',         'condition' => 'new']);
        Product::create(['vendor_id' => $this->vendor->id, 'category_id' => $this->category->id,
            'name' => 'Pending Product',  'slug' => 'pending-prod',  'sku' => 'PND-001', 'base_price' => 1000, 'status' => 'pending_review', 'condition' => 'new']);

        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Active Product'));
        $this->assertFalse($names->contains('Pending Product'));
    }

    public function test_storefront_filters_by_category(): void
    {
        $other = Category::create(['name' => 'Laptops', 'slug' => 'laptops', 'is_active' => true]);

        Product::create(['vendor_id' => $this->vendor->id, 'category_id' => $this->category->id,
            'name' => 'Phone', 'slug' => 'phone', 'sku' => 'PHN-001', 'base_price' => 5000, 'status' => 'active', 'condition' => 'new']);
        Product::create(['vendor_id' => $this->vendor->id, 'category_id' => $other->id,
            'name' => 'Laptop', 'slug' => 'laptop', 'sku' => 'LPT-001', 'base_price' => 80000, 'status' => 'active', 'condition' => 'new']);

        $response = $this->getJson("/api/v1/products?category_id={$this->category->id}");

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Phone'));
        $this->assertFalse($names->contains('Laptop'));
    }

    public function test_category_tree_is_returned(): void
    {
        $parent = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);
        Category::create(['name' => 'Phones', 'slug' => 'phones', 'parent_id' => $parent->id, 'is_active' => true]);

        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(200);
        $electronics = collect($response->json('data'))->firstWhere('slug', 'electronics');
        $this->assertNotNull($electronics);
        $this->assertNotEmpty($electronics['children']);
    }
}
