<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── ORDERS ───────────────────────────────────────────────────────────
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 30)->unique(); // ORD-2024-000001
            $table->foreignId('customer_id')->constrained('users');

            // State machine — valid transitions defined in OrderStateMachine
            $table->enum('status', [
                'pending',
                'payment_pending',
                'confirmed',
                'processing',
                'shipped',
                'delivered',
                'cancelled',
                'refund_requested',
                'refunded',
                'on_hold',
            ])->default('pending');

            // Pricing snapshot — prices at time of order (immutable)
            $table->decimal('subtotal', 15, 2);
            $table->decimal('freight_cost', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->json('price_snapshot')->nullable(); // full PriceBreakdown DTO stored here

            // Payment
            $table->enum('payment_method', [
                'bkash', 'nagad', 'sslcommerz', 'bank_transfer', 'cod', 'credit_account'
            ])->nullable();
            $table->enum('payment_status', ['pending', 'partial', 'paid', 'refunded'])->default('pending');

            // Delivery
            $table->json('shipping_address');
            $table->text('delivery_notes')->nullable();
            $table->timestamp('delivered_at')->nullable();

            // Approval
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index('status');
            $table->index('payment_status');
        });

        // ── ORDER ITEMS ──────────────────────────────────────────────────────
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            $table->string('product_name', 200); // snapshot
            $table->string('variant_name', 200)->nullable(); // snapshot
            $table->string('sku', 100); // snapshot

            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2);      // price at time of order
            $table->decimal('total_price', 15, 2);     // unit_price * quantity
            $table->decimal('vendor_payout', 15, 2)->default(0); // after commission deduction

            $table->enum('status', [
                'pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'
            ])->default('pending');

            $table->timestamps();

            $table->index(['order_id', 'vendor_id']);
        });

        // ── ORDER STATUS HISTORY (audit trail) ──────────────────────────────
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });

        // ── INVENTORY MOVEMENTS (ledger — never update, only insert) ─────────
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->enum('type', ['purchase', 'sale', 'return', 'adjustment', 'reservation', 'release']);
            $table->integer('quantity'); // positive = in, negative = out
            $table->integer('balance_after');
            $table->string('reference_type')->nullable(); // order, return, manual
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
