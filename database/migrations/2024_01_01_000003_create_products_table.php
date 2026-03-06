<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── BRANDS ───────────────────────────────────────────────────────────
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug', 120)->unique();
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── CATEGORIES (self-referencing tree) ───────────────────────────────
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->string('image')->nullable();
            $table->json('attribute_template')->nullable(); // which attributes apply to this category
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('parent_id');
        });

        // ── PRODUCTS ─────────────────────────────────────────────────────────
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories');
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();

            $table->string('name', 200);
            $table->string('slug', 220)->unique();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->string('sku', 100)->unique();

            // Pricing (base — overridden by pricing engine in Phase 4)
            $table->decimal('base_price', 15, 2);
            $table->decimal('cost_price', 15, 2)->nullable(); // vendor's cost

            // Status
            $table->enum('status', ['draft', 'pending_review', 'active', 'inactive', 'rejected'])->default('draft');
            $table->enum('condition', ['new', 'refurbished', 'used'])->default('new');
            $table->boolean('is_featured')->default(false);

            // Shipping
            $table->decimal('weight', 8, 3)->nullable(); // kg
            $table->json('dimensions')->nullable(); // {length, width, height} in cm

            // Warranty
            $table->string('warranty_period')->nullable(); // e.g. "1 year", "6 months"
            $table->text('warranty_terms')->nullable();

            // Search / SEO
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();

            // Stats
            $table->unsignedBigInteger('view_count')->default(0);
            $table->unsignedBigInteger('sales_count')->default(0);

            $table->softDeletes();
            $table->timestamps();

            $table->index(['vendor_id', 'category_id']);
            $table->index('status');
            $table->index('sku');
        });

        // ── PRODUCT VARIANTS ─────────────────────────────────────────────────
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku', 100)->unique();
            $table->string('name', 200)->nullable(); // e.g. "128GB / Midnight Black"
            $table->json('attributes'); // {"ram":"8GB","storage":"128GB","color":"Black"}
            $table->decimal('price_adjustment', 10, 2)->default(0); // +/- from base_price
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('product_id');
        });

        // ── PRODUCT ATTRIBUTES (EAV) ─────────────────────────────────────────
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('attribute_name', 100);  // e.g. "processor"
            $table->string('attribute_value', 255); // e.g. "Snapdragon 8 Gen 3"
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'attribute_name']);
        });

        // ── PRODUCT IMAGES ───────────────────────────────────────────────────
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('path');
            $table->string('alt_text', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('product_id');
        });

        // ── PRODUCT WARRANTIES ───────────────────────────────────────────────
        Schema::create('product_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('certification_name', 100); // e.g. "CE", "FCC", "BTRC"
            $table->string('certificate_number', 100)->nullable();
            $table->string('document_path')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_certifications');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
    }
};
