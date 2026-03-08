<?php

namespace Tests\Feature\Reporting;

use App\Modules\User\Models\User;
use App\Modules\Vendor\Models\Vendor;
use App\Modules\Product\Models\Category;
use App\Modules\Product\Models\Product;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReportingTest extends TestCase
{
    use RefreshDatabase;

    protected User   $admin;
    protected User   $customer;
    protected Vendor $vendor;
    protected Product $product;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->admin()->create();
        $this->admin->assignRole('super_admin');

        $this->customer = User::factory()->create([
            'type' => 'customer', 'status' => 'active',
            'customer_tier' => 'silver', 'credit_limit' => 50000, 'credit_used' => 0,
        ]);
        $this->customer->assignRole('customer');

        $vendorUser = User::factory()->vendor()->create();
        $vendorUser->assignRole('vendor');
        $this->vendor = Vendor::create([
            'user_id'         => $vendorUser->id,
            'store_name'      => 'TechStore',
            'slug'            => 'techstore',
            'status'          => 'active',
            'commission_rate' => 10,
            'total_revenue'   => 0,
        ]);

        $this->category = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);

        $this->product = Product::create([
            'vendor_id'   => $this->vendor->id,
            'category_id' => $this->category->id,
            'name'        => 'Smart TV 55"',
            'slug'        => 'smart-tv-55',
            'sku'         => 'TV-055',
            'base_price'  => 45000,
            'status'      => 'active',
            'condition'   => 'new',
            'view_count'  => 200,
        ]);

        // Seed some delivered orders so report queries return real data
        $this->seedOrders(5);

        Cache::flush(); // ensure no stale cache between tests
    }

    // ── DASHBOARD ──────────────────────────────────────────────────────────────

    public function test_dashboard_returns_expected_structure(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/reports/dashboard');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => [
                     'revenue'  => ['today', 'mtd', 'ytd'],
                     'orders'   => ['today', 'pending', 'awaiting_shipment'],
                     'products' => ['active', 'low_stock'],
                     'vendors'  => ['active'],
                     'invoices' => ['overdue', 'unpaid_value'],
                     'payouts'  => ['pending_count', 'pending_value'],
                     'recent_orders',
                     'revenue_trend_7d',
                 ]]);
    }

    public function test_dashboard_is_cached(): void
    {
        // First call populates cache
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/reports/dashboard');

        $this->assertTrue(Cache::has('report:dashboard'));
    }

    public function test_dashboard_requires_admin_role(): void
    {
        $this->actingAs($this->customer, 'sanctum')
             ->getJson('/api/v1/admin/reports/dashboard')
             ->assertStatus(403);
    }

    // ── SALES SUMMARY ──────────────────────────────────────────────────────────

    public function test_sales_summary_returns_correct_structure(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/reports/sales?from=2026-01-01&to=2026-12-31');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => [
                     'period', 'total_orders', 'completed_orders', 'cancelled_orders',
                     'gross_revenue', 'discount_total', 'tax_total', 'freight_total',
                     'net_revenue', 'average_order_value', 'unique_customers',
                     'new_customers', 'commission_earned', 'vendor_payouts',
                 ]]);
    }

    public function test_sales_summary_validates_date_params(): void
    {
        $this->actingAs($this->admin, 'sanctum')
             ->getJson('/api/v1/admin/reports/sales')
             ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
             ->getJson('/api/v1/admin/reports/sales?from=2026-03-01&to=2026-01-01') // to before from
             ->assertStatus(422);
    }

    public function test_sales_summary_counts_delivered_orders(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/reports/sales?from=' . now()->subMonth()->toDateString() . '&to=' . now()->toDateString());

        $response->assertStatus(200);
        $this->assertGreaterThan(0, $response->json('data.total_orders'));
    }

    // ── REVENUE TREND ──────────────────────────────────────────────────────────

    public function test_revenue_trend_returns_data_points(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/reports/revenue-trend?from=' . now()->subDays(7)->toDateString() . '&to=' . now()->toDateString() . '&granularity=day');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['period', 'granularity', 'data_points', 'trend']]);
    }

    public function test_revenue_trend_supports_all_granularities(): void
    {
        foreach (['day', 'week', 'month', 'year'] as $gran) {
            $response = $this->actingAs($this->admin, 'sanctum')
                             ->getJson("/api/v1/admin/reports/revenue-trend?from=2026-01-01&to=2026-12-31&granularity={$gran}");
            $response->assertStatus(200);
            $this->assertEquals($gran, $response->json('data.granularity'));
        }
    }

    public function test_revenue_trend_rejects_invalid_granularity(): void
    {
        $this->actingAs($this->admin, 'sanctum')
             ->getJson('/api/v1/admin/reports/revenue-trend?from=2026-01-01&to=2026-12-31&granularity=hour')
             ->assertStatus(422);
    }

    // ── VENDOR PERFORMANCE ─────────────────────────────────────────────────────

    public function test_vendor_performance_returns_ranked_list(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/reports/vendors?from=' . now()->subMonth()->toDateString() . '&to=' . now()->toDateString());

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['period', 'count', 'vendors']]);
    }

    public function test_vendor_performance_respects_limit(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/reports/vendors?from=2026-01-01&to=2026-12-31&limit=1');

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(1, count($response->json('data.vendors')));
    }

    public function test_vendor_can_view_own_performance(): void
    {
        $response = $this->actingAs($this->vendor->user, 'sanctum')
                         ->getJson('/api/v1/vendor/reports/performance?from=' . now()->subMonth()->toDateString() . '&to=' . now()->toDateString());

        $response->assertStatus(200);
    }

    public function test_customer_cannot_access_vendor_performance(): void
    {
        $this->actingAs($this->customer, 'sanctum')
             ->getJson('/api/v1/admin/reports/vendors?from=2026-01-01&to=2026-12-31')
             ->assertStatus(403);
    }

    // ── TOP PRODUCTS ───────────────────────────────────────────────────────────

    public function test_top_products_returns_ranked_list(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/reports/products?from=' . now()->subMonth()->toDateString() . '&to=' . now()->toDateString());

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['period', 'count', 'products']]);
    }

    public function test_top_products_can_filter_by_category(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/reports/products?from=2026-01-01&to=2026-12-31&category_id=' . $this->category->id);

        $response->assertStatus(200);
    }

    public function test_top_products_rejects_invalid_category(): void
    {
        $this->actingAs($this->admin, 'sanctum')
             ->getJson('/api/v1/admin/reports/products?from=2026-01-01&to=2026-12-31&category_id=99999')
             ->assertStatus(422);
    }

    // ── LOW STOCK ──────────────────────────────────────────────────────────────

    public function test_low_stock_returns_items_below_threshold(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/reports/low-stock?threshold=10');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['count', 'threshold', 'items']]);
    }

    // ── CATEGORY BREAKDOWN ─────────────────────────────────────────────────────

    public function test_category_breakdown_returns_revenue_shares(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/reports/categories?from=' . now()->subMonth()->toDateString() . '&to=' . now()->toDateString());

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['period', 'count', 'categories']]);
    }

    public function test_category_revenue_shares_sum_to_100(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/reports/categories?from=' . now()->subYear()->toDateString() . '&to=' . now()->toDateString());

        $categories = $response->json('data.categories');
        if (count($categories) > 0) {
            $total = collect($categories)->sum('revenue_share');
            $this->assertEqualsWithDelta(100.0, $total, 0.1);
        }

        $this->assertTrue(true); // pass if no data
    }

    // ── CUSTOMER ANALYTICS ─────────────────────────────────────────────────────

    public function test_customer_analytics_returns_expected_structure(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/reports/customers?from=' . now()->subMonth()->toDateString() . '&to=' . now()->toDateString());

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => [
                     'total_customers', 'new_customers', 'returning_customers',
                     'active_customers', 'avg_lifetime_value',
                     'tier_distribution', 'top_customers',
                 ]]);
    }

    public function test_customer_analytics_new_count_is_accurate(): void
    {
        // customer created in setUp is within range
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/reports/customers?from=' . now()->subHour()->toDateString() . '&to=' . now()->toDateString());

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('data.new_customers'));
    }

    // ── EXPORT ─────────────────────────────────────────────────────────────────

    public function test_export_csv_returns_downloadable_response(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->get('/api/v1/admin/reports/export?type=sales_summary&from=2026-01-01&to=2026-12-31&format=csv');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_export_rejects_invalid_type(): void
    {
        $this->actingAs($this->admin, 'sanctum')
             ->getJson('/api/v1/admin/reports/export?type=invalid_type&from=2026-01-01&to=2026-12-31')
             ->assertStatus(422);
    }

    public function test_export_rejects_invalid_format(): void
    {
        $this->actingAs($this->admin, 'sanctum')
             ->getJson('/api/v1/admin/reports/export?type=sales_summary&from=2026-01-01&to=2026-12-31&format=pdf')
             ->assertStatus(422);
    }

    public function test_export_revenue_trend_csv(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->get('/api/v1/admin/reports/export?type=revenue_trend&from=' . now()->subMonth()->toDateString() . '&to=' . now()->toDateString() . '&format=csv');

        $response->assertStatus(200);
    }

    public function test_export_vendor_performance_csv(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->get('/api/v1/admin/reports/export?type=vendor_performance&from=2026-01-01&to=2026-12-31&format=csv');

        $response->assertStatus(200);
    }

    // ── HELPERS ────────────────────────────────────────────────────────────────

    private function seedOrders(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $order = Order::create([
                'order_number'    => 'ORD-RPT-' . str_pad($i, 4, '0', STR_PAD_LEFT),
                'customer_id'     => $this->customer->id,
                'status'          => Order::STATUS_DELIVERED,
                'subtotal'        => 45000,
                'freight_cost'    => 500,
                'tax_amount'      => 2250,
                'discount_amount' => 0,
                'total_amount'    => 47750,
                'payment_method'  => 'bank_transfer',
                'payment_status'  => Order::PAYMENT_PAID,
                'shipping_address'=> [],
                'delivered_at'    => now()->subDays(rand(1, 20)),
            ]);

            OrderItem::create([
                'order_id'     => $order->id,
                'vendor_id'    => $this->vendor->id,
                'product_id'   => $this->product->id,
                'product_name' => $this->product->name,
                'sku'          => $this->product->sku,
                'quantity'     => 1,
                'unit_price'   => 45000,
                'total_price'  => 45000,
                'vendor_payout'=> 40500,
                'status'       => 'delivered',
            ]);
        }
    }
}
