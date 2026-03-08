<?php

namespace Tests\Feature\Pricing;

use App\Modules\User\Models\User;
use App\Modules\Vendor\Models\Vendor;
use App\Modules\Product\Models\Category;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductVariant;
use App\Modules\Pricing\Models\PricingRule;
use App\Modules\Pricing\Models\CustomerContract;
use App\Modules\Pricing\Services\PricingEngine;
use App\Modules\Pricing\Services\OpisFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected User    $admin;
    protected User    $customer;
    protected Product $product;
    protected Vendor  $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->admin()->create();
        $this->admin->assignRole('super_admin');

        $this->customer = User::factory()->create([
            'type' => 'customer', 'status' => 'active',
            'customer_tier' => 'standard', 'credit_limit' => 100000, 'credit_used' => 0,
        ]);
        $this->customer->assignRole('customer');

        $vendorUser = User::factory()->vendor()->create();
        $vendorUser->assignRole('vendor');
        $this->vendor = Vendor::create([
            'user_id' => $vendorUser->id, 'store_name' => 'Test Vendor',
            'slug' => 'test-vendor', 'status' => 'active', 'commission_rate' => 10,
        ]);

        $category      = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);
        $this->product = Product::create([
            'vendor_id' => $this->vendor->id, 'category_id' => $category->id,
            'name' => 'Test Product', 'slug' => 'test-product', 'sku' => 'TST-001',
            'base_price' => 10000, 'status' => 'active', 'condition' => 'new',
        ]);
    }

    // ── PRICE CALCULATE API ───────────────────────────────────────────────────

    public function test_price_calculate_endpoint_returns_breakdown(): void
    {
        $response = $this->getJson("/api/v1/pricing/calculate?product_id={$this->product->id}");

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['data' => ['breakdown' => [
                     'base_price', 'final_price', 'vat_amount',
                     'margin_amount', 'currency', 'price_source',
                     'rules_applied', 'from_mock_feed',
                 ]]]);
    }

    public function test_price_calculate_returns_notice_when_mock_feed(): void
    {
        // OPIS not configured in test env — should return mock notice
        $response = $this->getJson("/api/v1/pricing/calculate?product_id={$this->product->id}");

        $response->assertStatus(200);
        // Mock feed means notice is set
        $data = $response->json('data');
        $this->assertNotNull($data['notice']);
        $this->assertTrue($data['breakdown']['from_mock_feed']);
    }

    public function test_batch_price_calculate(): void
    {
        $response = $this->postJson('/api/v1/pricing/calculate-batch', [
            'product_ids' => [$this->product->id],
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['prices']]);

        $prices = $response->json('data.prices');
        $this->assertArrayHasKey($this->product->id, $prices);
    }

    // ── PRICING RULES ─────────────────────────────────────────────────────────

    public function test_admin_can_create_pricing_rule(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson('/api/v1/admin/pricing/rules', [
                             'name'      => 'Electronics Category Margin',
                             'type'      => 'margin',
                             'scope'     => 'category',
                             'scope_id'  => $this->product->category_id,
                             'value'     => 18.00,
                             'priority'  => 50,
                             'is_active' => true,
                         ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.name', 'Electronics Category Margin')
                 ->assertJsonPath('data.type', 'margin');

        $this->assertDatabaseHas('pricing_rules', ['name' => 'Electronics Category Margin', 'value' => 18.00]);
    }

    public function test_admin_can_create_volume_rule(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson('/api/v1/admin/pricing/rules', [
                             'name'    => 'Buy 20+ Get 7% Off',
                             'type'    => 'volume',
                             'scope'   => 'global',
                             'value'   => 7.00,
                             'min_qty' => 20,
                             'max_qty' => null,
                         ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.min_qty', 20);
    }

    public function test_admin_can_update_pricing_rule(): void
    {
        $rule = PricingRule::create([
            'name' => 'Test Rule', 'type' => 'margin', 'scope' => 'global',
            'value' => 10.00, 'priority' => 1, 'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
                         ->putJson("/api/v1/admin/pricing/rules/{$rule->id}", [
                             'value' => 12.50,
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.value', '12.5000');
    }

    public function test_admin_can_delete_pricing_rule(): void
    {
        $rule = PricingRule::create([
            'name' => 'Deletable Rule', 'type' => 'flat', 'scope' => 'global',
            'value' => 500, 'priority' => 1, 'is_active' => true,
        ]);

        $this->actingAs($this->admin, 'sanctum')
             ->deleteJson("/api/v1/admin/pricing/rules/{$rule->id}")
             ->assertStatus(204);

        $this->assertDatabaseMissing('pricing_rules', ['id' => $rule->id]);
    }

    public function test_non_admin_cannot_manage_pricing_rules(): void
    {
        $this->actingAs($this->customer, 'sanctum')
             ->postJson('/api/v1/admin/pricing/rules', ['name' => 'Hack', 'type' => 'discount', 'scope' => 'global', 'value' => 99])
             ->assertStatus(403);
    }

    // ── CUSTOMER CONTRACTS ────────────────────────────────────────────────────

    public function test_admin_can_create_customer_contract(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson('/api/v1/admin/pricing/contracts', [
                             'customer_id'   => $this->customer->id,
                             'vendor_id'     => $this->vendor->id,
                             'scope'         => 'global',
                             'price_type'    => 'discount_off_list',
                             'price_value'   => 10.00,
                             'payment_terms' => 'net_30',
                             'credit_limit'  => 500000,
                             'starts_at'     => now()->toDateString(),
                             'ends_at'       => now()->addYear()->toDateString(),
                         ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', 'active')
                 ->assertJsonStructure(['data' => ['contract_number', 'customer', 'vendor']]);

        $this->assertDatabaseHas('customer_contracts', ['customer_id' => $this->customer->id]);
    }

    public function test_contract_price_overrides_standard_rules(): void
    {
        // Create a rule that would give 20% margin
        PricingRule::create([
            'name' => 'High Margin Rule', 'type' => 'margin', 'scope' => 'global',
            'value' => 20.00, 'priority' => 100, 'is_active' => true,
        ]);

        // Create a contract giving 5% discount off list
        CustomerContract::create([
            'customer_id'     => $this->customer->id,
            'vendor_id'       => $this->vendor->id,
            'contract_number' => 'CTR-TEST-001',
            'scope'           => 'global',
            'price_type'      => 'discount_off_list',
            'price_value'     => 5.00,
            'status'          => 'active',
            'currency'        => 'BDT',
            'starts_at'       => now()->subDay(),
            'ends_at'         => now()->addYear(),
        ]);

        $engine    = app(PricingEngine::class);
        $breakdown = $engine->calculate($this->product, null, $this->customer);

        // Contract overrides the 20% rule
        $this->assertEquals('contract', $breakdown->priceSource);
        $this->assertNotNull($breakdown->contractId);
        // Discount should be positive (price lower than base)
        $this->assertGreaterThan(0, $breakdown->discountAmount);
    }

    public function test_opis_status_endpoint_shows_placeholder_notice(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/pricing/opis-status');

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'mock')
                 ->assertJsonPath('data.configured', false);

        // Should show which credentials are needed
        $this->assertNotNull($response->json('data.credentials_needed'));
    }

    // ── PRICING ENGINE UNIT-LEVEL TESTS ───────────────────────────────────────

    public function test_margin_rule_applied_correctly(): void
    {
        PricingRule::create([
            'name' => '20% Margin', 'type' => 'margin', 'scope' => 'global',
            'value' => 20.00, 'priority' => 5, 'is_active' => true,
        ]);

        $engine    = app(PricingEngine::class);
        $breakdown = $engine->calculate($this->product);

        // marginAmount should be ~20% of base (OPIS mock price may differ)
        $this->assertGreaterThan(0, $breakdown->marginAmount);
        $this->assertGreaterThan($breakdown->basePrice, $breakdown->finalPrice);
        $this->assertContains('margin', array_column($breakdown->rulesApplied, 'type'));
    }

    public function test_vat_is_applied_to_final_price(): void
    {
        $engine    = app(PricingEngine::class);
        $breakdown = $engine->calculate($this->product);

        $this->assertGreaterThan(0, $breakdown->vatAmount);
        // VAT should equal vatRate * (finalPrice - vatAmount)
        $priceBeforeVat = $breakdown->finalPrice - $breakdown->vatAmount;
        $expectedVat    = round($priceBeforeVat * $breakdown->vatRate, 2);
        $this->assertEquals($expectedVat, $breakdown->vatAmount);
    }

    public function test_volume_rule_applies_only_at_correct_quantity(): void
    {
        PricingRule::create([
            'name' => 'Bulk 10+', 'type' => 'volume', 'scope' => 'global',
            'value' => 10.00, 'min_qty' => 10, 'max_qty' => null,
            'priority' => 50, 'is_active' => true,
        ]);

        $engine    = app(PricingEngine::class);
        $noVolume  = $engine->calculate($this->product, null, null, 5);   // qty 5 — no discount
        $withVolume= $engine->calculate($this->product, null, null, 10);  // qty 10 — discount applies

        $ruleTypes5  = array_column($noVolume->rulesApplied, 'type');
        $ruleTypes10 = array_column($withVolume->rulesApplied, 'type');

        $this->assertNotContains('volume_discount', $ruleTypes5);
        $this->assertContains('volume_discount', $ruleTypes10);
    }
}
