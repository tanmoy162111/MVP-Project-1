<?php

namespace Tests\Feature\Invoice;

use App\Modules\User\Models\User;
use App\Modules\Vendor\Models\Vendor;
use App\Modules\Product\Models\Category;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductVariant;
use App\Modules\Order\Models\Order;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\Invoice\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected User    $admin;
    protected User    $customer;
    protected Invoice $invoice;
    protected Order   $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->admin()->create();
        $this->admin->assignRole('super_admin');

        $this->customer = User::factory()->create([
            'type' => 'customer', 'status' => 'active',
            'credit_limit' => 100000, 'credit_used' => 0,
        ]);
        $this->customer->assignRole('customer');

        // Seed the minimum data needed for invoice tests
        [$this->order, $this->invoice] = $this->seedOrderAndInvoice();
    }

    // ── INVOICE GENERATION ────────────────────────────────────────────────────

    public function test_invoice_is_generated_from_order(): void
    {
        $this->assertNotNull($this->invoice);
        $this->assertEquals($this->order->id, $this->invoice->order_id);
        $this->assertEquals($this->customer->id, $this->invoice->customer_id);
        $this->assertEquals(Invoice::STATUS_ISSUED, $this->invoice->status);
        $this->assertNotEmpty($this->invoice->invoice_number);
        $this->assertStringStartsWith('INV-', $this->invoice->invoice_number);
    }

    public function test_invoice_generation_is_idempotent(): void
    {
        // Calling generateFromOrder twice must return same invoice
        $service  = app(InvoiceService::class);
        $invoice2 = $service->generateFromOrder($this->order);

        $this->assertEquals($this->invoice->id, $invoice2->id);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_invoice_amounts_match_order(): void
    {
        $this->assertEquals($this->order->total_amount, $this->invoice->total_amount);
        $this->assertEquals($this->order->subtotal, $this->invoice->subtotal);
        $this->assertEquals($this->order->tax_amount, $this->invoice->tax_amount);
        $this->assertEquals('0.00', $this->invoice->amount_paid);
        $this->assertEquals($this->order->total_amount, $this->invoice->balance_due);
    }

    public function test_invoice_line_items_snapshot_is_correct(): void
    {
        $lineItems = $this->invoice->line_items;
        $this->assertNotEmpty($lineItems);
        $this->assertArrayHasKey('product_name', $lineItems[0]);
        $this->assertArrayHasKey('unit_price', $lineItems[0]);
        $this->assertArrayHasKey('quantity', $lineItems[0]);
    }

    public function test_due_date_is_set(): void
    {
        $this->assertNotNull($this->invoice->due_at);
        $this->assertTrue($this->invoice->due_at->isFuture());
    }

    // ── INVOICE LISTING ───────────────────────────────────────────────────────

    public function test_customer_can_list_own_invoices(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
                         ->getJson('/api/v1/invoices');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_customer_cannot_see_another_customers_invoice(): void
    {
        $other = User::factory()->create(['type' => 'customer', 'status' => 'active']);
        $other->assignRole('customer');

        $response = $this->actingAs($other, 'sanctum')
                         ->getJson("/api/v1/invoices/{$this->invoice->id}");

        $response->assertStatus(403);
    }

    public function test_admin_can_list_all_invoices(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/invoices');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    // ── PAYMENT FLOW ──────────────────────────────────────────────────────────

    public function test_customer_can_initiate_payment_with_mock_gateway(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
                         ->postJson('/api/v1/payments/initiate', [
                             'invoice_id' => $this->invoice->id,
                             'method'     => 'mock',
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.is_mock', true)
                 ->assertJsonPath('data.gateway', 'mock')
                 ->assertJsonStructure(['data' => ['reference', 'token', 'notice']]);

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $this->invoice->id,
            'status'     => Payment::STATUS_PENDING,
        ]);
    }

    public function test_mock_gateway_callback_completes_invoice(): void
    {
        // Step 1: initiate
        $initResponse = $this->actingAs($this->customer, 'sanctum')
                             ->postJson('/api/v1/payments/initiate', [
                                 'invoice_id' => $this->invoice->id,
                                 'method'     => 'mock',
                             ]);
        $token = $initResponse->json('data.token');

        // Step 2: callback
        $response = $this->postJson('/api/v1/payments/mock/callback', [
            'transaction_id' => $token,
            'amount'         => $this->invoice->total_amount,
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        $this->assertDatabaseHas('invoices', [
            'id'     => $this->invoice->id,
            'status' => Invoice::STATUS_PAID,
        ]);
    }

    public function test_partial_payment_sets_partial_status(): void
    {
        $service = app(InvoiceService::class);
        $partial = (float) $this->invoice->total_amount / 2;

        $service->applyPayment($this->invoice, $partial, 'TXN-PARTIAL-001', 'bank_transfer');

        $this->invoice->refresh();
        $this->assertEquals(Invoice::STATUS_PARTIAL, $this->invoice->status);
        $this->assertEquals($partial, (float) $this->invoice->amount_paid);
        $this->assertEquals((float) $this->invoice->total_amount - $partial, (float) $this->invoice->balance_due);
    }

    public function test_full_payment_locks_invoice(): void
    {
        $service = app(InvoiceService::class);
        $service->applyPayment($this->invoice, (float) $this->invoice->total_amount, 'TXN-FULL-001', 'bank_transfer');

        $this->invoice->refresh();
        $this->assertEquals(Invoice::STATUS_PAID, $this->invoice->status);
        $this->assertTrue($this->invoice->is_locked);
        $this->assertEquals('0.00', $this->invoice->balance_due);
        $this->assertNotNull($this->invoice->paid_at);
    }

    public function test_cannot_pay_already_paid_invoice(): void
    {
        $service = app(InvoiceService::class);
        $service->applyPayment($this->invoice, (float) $this->invoice->total_amount, 'TXN-001', 'mock');

        $this->invoice->refresh();

        $this->expectException(\RuntimeException::class);
        $service->applyPayment($this->invoice, 100, 'TXN-002', 'mock');
    }

    // ── OVERDUE & VOID ────────────────────────────────────────────────────────

    public function test_overdue_invoices_are_marked(): void
    {
        // Backdate the due_at
        $this->invoice->update(['due_at' => now()->subDays(5)]);

        $service = app(InvoiceService::class);
        $count   = $service->markOverdueInvoices();

        $this->assertEquals(1, $count);
        $this->assertEquals(Invoice::STATUS_OVERDUE, $this->invoice->fresh()->status);
    }

    public function test_overdue_filter_in_listing(): void
    {
        $this->invoice->update(['due_at' => now()->subDays(3)]);
        app(InvoiceService::class)->markOverdueInvoices();

        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/invoices?overdue=1');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_admin_can_void_unpaid_invoice(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson("/api/v1/admin/invoices/{$this->invoice->id}/void", [
                             'reason' => 'Order was cancelled by customer.',
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', Invoice::STATUS_VOID);

        $this->assertTrue($this->invoice->fresh()->is_locked);
    }

    public function test_cannot_void_paid_invoice(): void
    {
        app(InvoiceService::class)->applyPayment(
            $this->invoice, (float) $this->invoice->total_amount, 'TXN-PAID', 'mock'
        );

        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson("/api/v1/admin/invoices/{$this->invoice->id}/void", [
                             'reason' => 'Trying to void paid invoice.',
                         ]);

        $response->assertStatus(400);
    }

    // ── GATEWAY STATUS ────────────────────────────────────────────────────────

    public function test_gateway_status_shows_placeholders(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->getJson('/api/v1/admin/payments/gateway-status');

        $response->assertStatus(200);
        $gateways = collect($response->json('data.gateways'));

        // bKash and SSLCommerz should be unconfigured in test env
        $bkash = $gateways->firstWhere('key', 'bkash');
        $this->assertFalse($bkash['configured']);
        $this->assertEquals('mock', $bkash['status']);
        $this->assertNotNull($bkash['credentials_needed']);
    }

    // ── HELPER ───────────────────────────────────────────────────────────────

    private function seedOrderAndInvoice(): array
    {
        $vendorUser = User::factory()->vendor()->create();
        $vendorUser->assignRole('vendor');
        $vendor = Vendor::create([
            'user_id' => $vendorUser->id, 'store_name' => 'Test Vendor',
            'slug' => 'test-vendor', 'status' => 'active', 'commission_rate' => 10,
        ]);

        $category = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);
        $product  = Product::create([
            'vendor_id' => $vendor->id, 'category_id' => $category->id,
            'name' => 'Test Phone', 'slug' => 'test-phone', 'sku' => 'PHN-001',
            'base_price' => 10000, 'status' => 'active', 'condition' => 'new',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'PHN-BLK',
            'name' => '8GB Black', 'attributes' => ['color' => 'black'],
            'price_adjustment' => 0, 'stock_quantity' => 50, 'is_active' => true,
        ]);

        $order = Order::create([
            'order_number'    => 'ORD-TEST-001',
            'customer_id'     => $this->customer->id,
            'status'          => Order::STATUS_CONFIRMED,
            'subtotal'        => 10000,
            'freight_cost'    => 100,
            'tax_amount'      => 505,
            'discount_amount' => 0,
            'total_amount'    => 10605,
            'payment_method'  => 'bank_transfer',
            'payment_status'  => Order::PAYMENT_PENDING,
            'shipping_address'=> ['name' => 'Test', 'phone' => '01711000000', 'line1' => 'Dhaka', 'city' => 'Dhaka'],
        ]);

        \App\Modules\Order\Models\OrderItem::create([
            'order_id'     => $order->id, 'vendor_id' => $vendor->id,
            'product_id'   => $product->id, 'variant_id' => $variant->id,
            'product_name' => 'Test Phone', 'variant_name' => '8GB Black', 'sku' => 'PHN-BLK',
            'quantity'     => 1, 'unit_price' => 10000, 'total_price' => 10000,
            'vendor_payout'=> 9000, 'status' => 'pending',
        ]);

        $invoice = app(InvoiceService::class)->generateFromOrder($order);

        return [$order, $invoice];
    }
}
