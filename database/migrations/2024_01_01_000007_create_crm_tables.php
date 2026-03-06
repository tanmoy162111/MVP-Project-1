<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── CUSTOMER COMMUNICATION LOG ───────────────────────────────────────
        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->enum('channel', ['call', 'email', 'meeting', 'whatsapp', 'note']);
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->timestamp('communicated_at');
            $table->timestamps();

            $table->index(['customer_id', 'communicated_at']);
        });

        // ── WISHLISTS ────────────────────────────────────────────────────────
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'product_id']);
        });

        // ── NOTIFICATIONS ────────────────────────────────────────────────────
        // Using Laravel's built-in notifications table
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // ── VENDOR PAYOUTS ───────────────────────────────────────────────────
        Schema::create('vendor_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->decimal('amount', 15, 2);
            $table->decimal('commission_deducted', 15, 2);
            $table->decimal('net_payout', 15, 2);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('payment_reference')->nullable();
            $table->date('period_from');
            $table->date('period_to');
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
        });

        // ── COUPONS ──────────────────────────────────────────────────────────
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete(); // null = platform-wide
            $table->string('code', 50)->unique();
            $table->enum('type', ['percentage', 'fixed']);
            $table->decimal('value', 10, 2);
            $table->decimal('min_order_amount', 15, 2)->default(0);
            $table->decimal('max_discount_amount', 15, 2)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('vendor_payouts');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('wishlists');
        Schema::dropIfExists('communication_logs');
    }
};
