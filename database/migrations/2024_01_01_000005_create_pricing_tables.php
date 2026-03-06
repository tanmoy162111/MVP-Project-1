<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── OPIS PRICE FEED ──────────────────────────────────────────────────
        Schema::create('opis_prices', function (Blueprint $table) {
            $table->id();
            $table->string('product_code', 100)->index();
            $table->decimal('opis_price', 15, 2);
            $table->string('currency', 3)->default('BDT');
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->boolean('is_current')->default(true);
            $table->string('source', 50)->default('api'); // api | manual | import
            $table->timestamps();

            $table->index(['product_code', 'effective_from', 'effective_to']);
        });

        // ── PRICING RULES ────────────────────────────────────────────────────
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->enum('type', ['margin', 'freight', 'tax', 'volume_discount', 'customer_tier', 'contract']);
            $table->enum('applies_to', ['all', 'category', 'product', 'vendor', 'customer_tier']);
            $table->unsignedBigInteger('applies_to_id')->nullable(); // FK to whichever entity
            $table->json('rule_config'); // {"margin_percent":15, "min_qty":10, "discount_percent":5}
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('priority')->default(100); // lower = applied first
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        // ── CUSTOMER CONTRACT PRICING ────────────────────────────────────────
        Schema::create('customer_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('contract_number', 50)->unique();
            $table->json('price_overrides'); // {"product_id:123": 450.00, "category_id:5": {"discount_percent": 8}}
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->enum('payment_terms', ['immediate', 'net_7', 'net_15', 'net_30'])->default('immediate');
            $table->string('document_path')->nullable();
            $table->enum('status', ['draft', 'active', 'expired', 'terminated'])->default('draft');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });

        // ── PRICING AUDIT LOG (append-only) ─────────────────────────────────
        Schema::create('pricing_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->decimal('opis_price', 15, 2)->nullable();
            $table->decimal('freight_cost', 15, 2)->default(0);
            $table->decimal('margin_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('volume_discount', 15, 2)->default(0);
            $table->decimal('contract_override', 15, 2)->nullable();
            $table->decimal('final_price', 15, 2);
            $table->boolean('contract_override_applied')->default(false);
            $table->json('config_snapshot'); // exact pricing rule IDs and values used
            $table->timestamps();

            $table->index(['product_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_audit_log');
        Schema::dropIfExists('customer_contracts');
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('opis_prices');
    }
};
