<?php

namespace Tests\Feature\Checkout;

use App\Modules\User\Models\User;
use App\Modules\Vendor\Models\Vendor;
use App\Modules\Product\Models\Category;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductVariant;
use App\Modules\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected User     $customer;
    protected User     $admin;
    protected Product  $product;
    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // Admin
        $this->admin = User::factory()->admin()->create();
        $this->admin->assignRole('super_admin');

        // Customer
        $this->customer = User::factory()->create([
            'type' => 'customer', 'status' => 'active',
            'credit_limit' => 50000, 'credit_used' => 0,
        ]);
        $this->customer->assignRole('customer');

        // Vendor + product
        $vendorUser = User::factory()->vendor()->create();
        $vendorUser->assignRole('vendor');
        $vendor = Vendor::create([
            'user_id' => $vendorUser->id, 'store_name' => 'Test Vendor',
            'slug' => 'test-vendor', 'status' => 'active', 'commission_rate' => 10,
        ]);

        $category = Category::create(['name' => 'Phones', 'slug' => 'phones', 'is_active' => true]);

        $this->product = Product::create([
            'vendor_id' => $vendor->id, 'category_id' => $category->id,
            'name' => 'Test Phone', 'slug' => 'test-phone', 'sku' => 'PHN-001',
            'base_price' => 10000, 'status' => 'active', 'condition' => 'new',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id, 'sku' => 'PHN-001-BLK',
            'name' => '8GB / Black', 'attributes' => ['ram' => '8GB', 'color' => 'Black'],
            'price_adjustment' => 0, 'stock_quantity' => 20, 'is_active' => true,
        ]);
    }

    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'items' => [
                ['product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 1],
            ],
            'payment_method'   => 'cod',
            'shipping_address' => [
                'name'  => 'John Doe', 'phone' => '01711000000',
                'line1' => 'Road 1, House 5', 'city' => 'Dhaka',
            ],
        ], $overrides);
    }

    // ── PLACE ORDER ───────────────────────────────────────────────────────────

    public function test_customer_can_place_order(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
                         ->postJson('/api/v1/orders', $this->orderPayload());

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', 'pending')
                 ->assertJsonPath('data.payment_method', 'cod')
                 ->assertJsonStructure(['data' => ['order_number', 'total_amount', 'items']]);

        $this->assertDatabaseHas('orders', ['customer_id' => $this->customer->id, 'status' => 'pending']);
        $this->assertDatabaseHas('order_items', ['product_id' => $this->product->id, 'quantity' => 1]);
    }

    public function test_placing_order_decrements_stock(): void
    {
        $this->actingAs($this->customer, 'sanctum')
             ->postJson('/api/v1/orders', $this->orderPayload(['items' => [
                 ['product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 3],
             ]]));

        $this->assertEquals(17, $this->variant->fresh()->stock_quantity);
    }

    public function test_placing_order_creates_inventory_movement(): void
    {
        $this->actingAs($this->customer, 'sanctum')
             ->postJson('/api/v1/orders', $this->orderPayload());

        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->product->id,
            'type'       => 'reservation',
            'quantity'   => -1,
        ]);
    }

    public function test_order_fails_when_stock_is_insufficient(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
                         ->postJson('/api/v1/orders', $this->orderPayload(['items' => [
                             ['product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 999],
                         ]]));

        $response->assertStatus(400)
                 ->assertJsonPath('success', false);

        // Stock must be unchanged
        $this->assertEquals(20, $this->variant->fresh()->stock_quantity);
    }

    public function test_order_validation_fails_with_empty_cart(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
                         ->postJson('/api/v1/orders', $this->orderPayload(['items' => []]));

        $response->assertStatus(422)
                 ->assertJsonPath('errors.items.0', 'Your cart is empty.');
    }

    // ── CREDIT LIMIT ─────────────────────────────────────────────────────────

    public function test_credit_account_payment_enforces_limit(): void
    {
        // Customer has BDT 50,000 credit limit but 0 available (fully used)
        $this->customer->update(['credit_limit' => 5000, 'credit_used' => 5000]);

        $response = $this->actingAs($this->customer, 'sanctum')
                         ->postJson('/api/v1/orders', $this->orderPayload([
                             'payment_method' => 'credit_account',
                         ]));

        $response->assertStatus(422)
                 ->assertJsonPath('code', 'CREDIT_LIMIT_EXCEEDED');

        // No order should be created
        $this->assertDatabaseCount('orders', 0);
        // Stock must be unchanged
        $this->assertEquals(20, $this->variant->fresh()->stock_quantity);
    }

    public function test_credit_account_payment_charges_credit(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
                         ->postJson('/api/v1/orders', $this->orderPayload([
                             'payment_method' => 'credit_account',
                         ]));

        $response->assertStatus(201);

        // credit_used should have increased
        $this->assertGreaterThan(0, $this->customer->fresh()->credit_used);

        // credit_ledger should have a charge entry
        $this->assertDatabaseHas('credit_ledger', [
            'customer_id' => $this->customer->id,
            'type'        => 'charge',
        ]);
    }

    // ── ORDER TRANSITIONS ─────────────────────────────────────────────────────

    public function test_admin_can_confirm_order(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson("/api/v1/admin/orders/{$order->id}/transition", [
                             'status' => 'confirmed',
                             'note'   => 'Approved by admin.',
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('order_status_history', [
            'order_id'    => $order->id,
            'from_status' => 'pending',
            'to_status'   => 'confirmed',
        ]);
    }

    public function test_invalid_transition_returns_400(): void
    {
        $order = $this->createOrder(); // pending

        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson("/api/v1/admin/orders/{$order->id}/transition", [
                             'status' => 'delivered', // can't jump from pending to delivered
                         ]);

        $response->assertStatus(400)
                 ->assertJsonPath('success', false);
    }

    // ── CANCEL ────────────────────────────────────────────────────────────────

    public function test_customer_can_cancel_pending_order(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->customer, 'sanctum')
                         ->postJson("/api/v1/orders/{$order->id}/cancel", [
                             'reason' => 'Changed my mind.',
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'cancelled');

        // Stock should be returned
        $this->assertEquals(20, $this->variant->fresh()->stock_quantity);
    }

    public function test_customer_cannot_cancel_confirmed_order(): void
    {
        $order = $this->createOrder();
        // Admin confirms it first
        $this->actingAs($this->admin, 'sanctum')
             ->postJson("/api/v1/admin/orders/{$order->id}/transition", ['status' => 'confirmed']);

        $response = $this->actingAs($this->customer, 'sanctum')
                         ->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Too late.']);

        $response->assertStatus(400);
    }

    public function test_customer_cannot_view_another_customers_order(): void
    {
        $order = $this->createOrder();
        $other = User::factory()->create(['type' => 'customer', 'status' => 'active']);
        $other->assignRole('customer');

        $response = $this->actingAs($other, 'sanctum')
                         ->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(403);
    }

    // ── HELPER ────────────────────────────────────────────────────────────────

    private function createOrder(): Order
    {
        $response = $this->actingAs($this->customer, 'sanctum')
                         ->postJson('/api/v1/orders', $this->orderPayload());
        $response->assertStatus(201);
        return Order::first();
    }
}
