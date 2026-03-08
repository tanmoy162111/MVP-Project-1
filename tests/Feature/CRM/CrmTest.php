<?php

namespace Tests\Feature\CRM;

use App\Modules\User\Models\User;
use App\Modules\Vendor\Models\Vendor;
use App\Modules\Product\Models\Category;
use App\Modules\Product\Models\Product;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\CRM\Models\CommunicationLog;
use App\Modules\CRM\Models\Coupon;
use App\Modules\CRM\Models\Notification;
use App\Modules\CRM\Models\VendorPayout;
use App\Modules\CRM\Models\Wishlist;
use App\Modules\CRM\Services\CustomerTierService;
use App\Modules\CRM\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmTest extends TestCase
{
    use RefreshDatabase;

    protected User   $admin;
    protected User   $customer;
    protected Vendor $vendor;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->admin()->create();
        $this->admin->assignRole('super_admin');

        $this->customer = User::factory()->create([
            'type' => 'customer', 'status' => 'active',
            'customer_tier' => 'standard', 'credit_limit' => 0, 'credit_used' => 0,
        ]);
        $this->customer->assignRole('customer');

        $vendorUser = User::factory()->vendor()->create();
        $vendorUser->assignRole('vendor');
        $this->vendor = Vendor::create([
            'user_id' => $vendorUser->id, 'store_name' => 'TestShop',
            'slug' => 'testshop', 'status' => 'active', 'commission_rate' => 10,
        ]);

        $category      = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);
        $this->product = Product::create([
            'vendor_id' => $this->vendor->id, 'category_id' => $category->id,
            'name' => 'Smart TV', 'slug' => 'smart-tv', 'sku' => 'TV-001',
            'base_price' => 50000, 'status' => 'active', 'condition' => 'new',
        ]);
    }

    // ── TIER EVALUATION ───────────────────────────────────────────────────────

    public function test_customer_starts_at_bronze(): void
    {
        $this->assertEquals('standard', $this->customer->customer_tier);
    }

    public function test_tier_upgrades_to_silver_after_qualifying_spend(): void
    {
        // Create 3 delivered orders totalling BDT 1,20,000
        $this->createDeliveredOrders(3, 40000);

        $service = app(CustomerTierService::class);
        $result  = $service->evaluate($this->customer);

        $this->assertTrue($result['changed']);
        $this->assertEquals('standard', $result['old_tier']);
        $this->assertEquals('silver', $result['new_tier']);
        $this->assertEquals(50000, $this->customer->fresh()->credit_limit);
    }

    public function test_tier_evaluation_is_idempotent_when_no_change(): void
    {
        $service = app(CustomerTierService::class);
        $result  = $service->evaluate($this->customer);

        $this->assertFalse($result['changed']);
        $this->assertEquals('standard', $result['new_tier']);
    }

    public function test_tier_upgrade_fires_notification(): void
    {
        $this->createDeliveredOrders(3, 40000);
        app(CustomerTierService::class)->evaluate($this->customer);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->customer->id,
            'type'    => Notification::TYPE_SYSTEM,
        ]);
    }

    public function test_tier_upgrade_creates_comm_log_entry(): void
    {
        $this->createDeliveredOrders(3, 40000);
        app(CustomerTierService::class)->evaluate($this->customer);

        $this->assertDatabaseHas('communication_logs', [
            'customer_id' => $this->customer->id,
            'type'        => CommunicationLog::TYPE_SYSTEM,
        ]);
    }

    public function test_tier_progress_endpoint_returns_correct_structure(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
                         ->getJson('/api/v1/account/tier');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => [
                     'current_tier', 'credit_limit', 'stats',
                     'next_tier_progress' => ['next_tier', 'required_spend', 'remaining_spend', 'percent_complete'],
                 ]]);
    }

    public function test_admin_can_manually_evaluate_tier(): void
    {
        $this->createDeliveredOrders(3, 40000);

        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson("/api/v1/admin/customers/{$this->customer->id}/evaluate-tier");

        $response->assertStatus(200)
                 ->assertJsonPath('data.changed', true)
                 ->assertJsonPath('data.new_tier', 'silver');
    }

    // ── COMMUNICATION LOG ─────────────────────────────────────────────────────

    public function test_admin_can_log_a_call(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson("/api/v1/admin/customers/{$this->customer->id}/communications", [
                             'type'      => 'call',
                             'direction' => 'inbound',
                             'subject'   => 'Customer inquiry about bulk pricing',
                             'body'      => 'Customer called asking about gold tier discount on TV orders above 50 units.',
                         ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.type', 'call')
                 ->assertJsonPath('data.subject', 'Customer inquiry about bulk pricing');

        $this->assertDatabaseHas('communication_logs', [
            'customer_id' => $this->customer->id,
            'type'        => 'call',
            'created_by'  => $this->admin->id,
        ]);
    }

    public function test_admin_can_pin_and_unpin_log_entry(): void
    {
        $log = CommunicationLog::create([
            'customer_id' => $this->customer->id, 'created_by' => $this->admin->id,
            'type' => 'note', 'direction' => 'outbound',
            'subject' => 'Important note', 'body' => 'VIP customer — priority support.',
        ]);

        $this->actingAs($this->admin, 'sanctum')
             ->patchJson("/api/v1/admin/customers/{$this->customer->id}/communications/{$log->id}/pin")
             ->assertStatus(200)
             ->assertJsonPath('data.is_pinned', true);

        // Toggle again
        $this->actingAs($this->admin, 'sanctum')
             ->patchJson("/api/v1/admin/customers/{$this->customer->id}/communications/{$log->id}/pin")
             ->assertStatus(200)
             ->assertJsonPath('data.is_pinned', false);
    }

    public function test_comm_log_listing_can_filter_by_type(): void
    {
        CommunicationLog::create(['customer_id'=>$this->customer->id,'created_by'=>$this->admin->id,'type'=>'call','direction'=>'inbound','subject'=>'S1','body'=>'B1']);
        CommunicationLog::create(['customer_id'=>$this->customer->id,'created_by'=>$this->admin->id,'type'=>'note','direction'=>'outbound','subject'=>'S2','body'=>'B2']);

        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson("/api/v1/admin/customers/{$this->customer->id}/communications?type=call");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('call', $response->json('data.0.type'));
    }

    // ── WISHLIST ──────────────────────────────────────────────────────────────

    public function test_customer_can_add_product_to_wishlist(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
                         ->postJson('/api/v1/account/wishlist', [
                             'product_id' => $this->product->id,
                         ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('wishlists', [
            'customer_id' => $this->customer->id,
            'product_id'  => $this->product->id,
        ]);
    }

    public function test_adding_same_product_twice_does_not_duplicate(): void
    {
        $this->actingAs($this->customer, 'sanctum')
             ->postJson('/api/v1/account/wishlist', ['product_id' => $this->product->id]);
        $this->actingAs($this->customer, 'sanctum')
             ->postJson('/api/v1/account/wishlist', ['product_id' => $this->product->id]);

        $this->assertDatabaseCount('wishlists', 1);
    }

    public function test_customer_can_remove_from_wishlist(): void
    {
        $item = Wishlist::create(['customer_id' => $this->customer->id, 'product_id' => $this->product->id]);

        $this->actingAs($this->customer, 'sanctum')
             ->deleteJson("/api/v1/account/wishlist/{$item->id}")
             ->assertStatus(204);

        $this->assertDatabaseMissing('wishlists', ['id' => $item->id]);
    }

    // ── NOTIFICATIONS ─────────────────────────────────────────────────────────

    public function test_customer_can_list_notifications(): void
    {
        Notification::create([
            'user_id' => $this->customer->id, 'type' => Notification::TYPE_SYSTEM,
            'title' => 'Test Notif', 'body' => 'Hello', 'is_read' => false,
        ]);

        $response = $this->actingAs($this->customer, 'sanctum')
                         ->getJson('/api/v1/account/notifications');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data', 'unread_count']);

        $this->assertEquals(1, $response->json('unread_count'));
    }

    public function test_customer_can_mark_all_notifications_read(): void
    {
        Notification::create(['user_id' => $this->customer->id, 'type' => 'system', 'title' => 'N1', 'body' => 'B1', 'is_read' => false]);
        Notification::create(['user_id' => $this->customer->id, 'type' => 'system', 'title' => 'N2', 'body' => 'B2', 'is_read' => false]);

        $this->actingAs($this->customer, 'sanctum')
             ->postJson('/api/v1/account/notifications/read-all')
             ->assertStatus(200);

        $this->assertEquals(0, Notification::where('user_id', $this->customer->id)->unread()->count());
    }

    // ── COUPONS ───────────────────────────────────────────────────────────────

    public function test_admin_can_create_coupon(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson('/api/v1/admin/coupons', [
                             'code'      => 'SAVE10',
                             'type'      => 'percent',
                             'value'     => 10,
                             'min_order_amount' => 5000,
                         ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.code', 'SAVE10');
    }

    public function test_valid_coupon_applies_correct_discount(): void
    {
        Coupon::create([
            'code' => 'FLAT500', 'type' => 'flat', 'value' => 500,
            'is_active' => true, 'used_count' => 0,
        ]);

        $response = $this->actingAs($this->customer, 'sanctum')
                         ->postJson('/api/v1/coupons/validate', [
                             'code'           => 'FLAT500',
                             'order_subtotal' => 10000,
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.discount', 500.0)
                 ->assertJsonPath('data.coupon_code', 'FLAT500');
    }

    public function test_coupon_rejected_below_minimum_order(): void
    {
        Coupon::create([
            'code' => 'BIG10', 'type' => 'percent', 'value' => 10,
            'min_order_amount' => 20000, 'is_active' => true, 'used_count' => 0,
        ]);

        $response = $this->actingAs($this->customer, 'sanctum')
                         ->postJson('/api/v1/coupons/validate', [
                             'code'           => 'BIG10',
                             'order_subtotal' => 5000,
                         ]);

        $response->assertStatus(422)
                 ->assertJsonPath('success', false);
    }

    public function test_exhausted_coupon_is_rejected(): void
    {
        Coupon::create([
            'code' => 'LIMITED', 'type' => 'flat', 'value' => 100,
            'usage_limit' => 2, 'used_count' => 2, 'is_active' => true,
        ]);

        $response = $this->actingAs($this->customer, 'sanctum')
                         ->postJson('/api/v1/coupons/validate', [
                             'code' => 'LIMITED', 'order_subtotal' => 5000,
                         ]);

        $response->assertStatus(422);
    }

    public function test_percent_discount_is_capped_by_max_discount(): void
    {
        Coupon::create([
            'code' => 'CAP20', 'type' => 'percent', 'value' => 20,
            'max_discount_amount' => 500, 'is_active' => true, 'used_count' => 0,
        ]);

        $service = app(CouponService::class);
        $result  = $service->validate('CAP20', $this->customer, 10000);

        // 20% of 10000 = 2000, but capped at 500
        $this->assertEquals(500.0, $result['discount']);
    }

    public function test_invalid_coupon_code_returns_error(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
                         ->postJson('/api/v1/coupons/validate', [
                             'code' => 'DOESNTEXIST', 'order_subtotal' => 5000,
                         ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
    }

    // ── VENDOR PAYOUTS ────────────────────────────────────────────────────────

    public function test_payout_preview_shows_correct_amounts(): void
    {
        $this->createDeliveredOrderItems(amount: 50000);

        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/payouts/preview?period_from=' . now()->subMonth()->toDateString() . '&period_to=' . now()->toDateString());

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['vendors', 'total_payout']]);

        $vendor = collect($response->json('data.vendors'))->firstWhere('vendor_id', $this->vendor->id);
        $this->assertNotNull($vendor);
        $this->assertEquals(50000, $vendor['gross_sales']);
        $this->assertEquals(5000, $vendor['commission_amount']); // 10%
        $this->assertEquals(45000, $vendor['net_amount']);
    }

    public function test_admin_can_create_and_complete_payout(): void
    {
        $this->createDeliveredOrderItems(amount: 50000);

        // Create payout
        $createResponse = $this->actingAs($this->admin, 'sanctum')
                               ->postJson('/api/v1/admin/payouts', [
                                   'vendor_id'   => $this->vendor->id,
                                   'period_from' => now()->subMonth()->toDateString(),
                                   'period_to'   => now()->toDateString(),
                               ]);

        $createResponse->assertStatus(201);
        $payoutId = $createResponse->json('data.id');

        // Process it
        $this->actingAs($this->admin, 'sanctum')
             ->postJson("/api/v1/admin/payouts/{$payoutId}/process", [
                 'transaction_ref' => 'BKASH-TXN-12345',
             ])
             ->assertStatus(200)
             ->assertJsonPath('data.status', VendorPayout::STATUS_PROCESSING);

        // Complete it
        $this->actingAs($this->admin, 'sanctum')
             ->postJson("/api/v1/admin/payouts/{$payoutId}/complete")
             ->assertStatus(200)
             ->assertJsonPath('data.status', VendorPayout::STATUS_COMPLETED);

        $this->assertNotNull(VendorPayout::find($payoutId)->processed_at);
    }

    public function test_vendor_can_view_own_payout_history(): void
    {
        $response = $this->actingAs($this->vendor->user, 'sanctum')
                         ->getJson('/api/v1/vendor/payouts');

        $response->assertStatus(200);
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    private function createDeliveredOrders(int $count, float $amount): void
    {
        for ($i = 0; $i < $count; $i++) {
            Order::create([
                'order_number'     => 'ORD-TIER-' . $i,
                'customer_id'      => $this->customer->id,
                'status'           => Order::STATUS_DELIVERED,
                'subtotal'         => $amount,
                'freight_cost'     => 0,
                'tax_amount'       => 0,
                'discount_amount'  => 0,
                'total_amount'     => $amount,
                'payment_method'   => 'bank_transfer',
                'payment_status'   => Order::PAYMENT_PAID,
                'shipping_address' => [],
                'delivered_at'     => now()->subDays(rand(1, 300)),
            ]);
        }
    }

    private function createDeliveredOrderItems(float $amount): void
    {
        $order = Order::create([
            'order_number'     => 'ORD-PAYOUT-001',
            'customer_id'      => $this->customer->id,
            'status'           => Order::STATUS_DELIVERED,
            'subtotal'         => $amount,
            'freight_cost'     => 0,
            'tax_amount'       => 0,
            'discount_amount'  => 0,
            'total_amount'     => $amount,
            'payment_method'   => 'bank_transfer',
            'payment_status'   => Order::PAYMENT_PAID,
            'shipping_address' => [],
            'delivered_at'     => now()->subDays(5),
        ]);

        OrderItem::create([
            'order_id'     => $order->id,
            'vendor_id'    => $this->vendor->id,
            'product_id'   => $this->product->id,
            'product_name' => 'Smart TV',
            'sku'          => 'TV-001',
            'quantity'     => 1,
            'unit_price'   => $amount,
            'total_price'  => $amount,
            'vendor_payout'=> $amount * 0.9,
            'status'       => 'delivered',
        ]);
    }
}
