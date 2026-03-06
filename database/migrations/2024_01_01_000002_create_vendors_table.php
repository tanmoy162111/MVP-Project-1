<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('store_name', 150);
            $table->string('slug', 170)->unique();
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->string('banner')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();

            // Business verification
            $table->string('trade_license')->nullable();
            $table->string('tin_number', 50)->nullable();
            $table->string('bin_number', 50)->nullable();
            $table->json('documents')->nullable(); // array of uploaded document paths

            // Financial
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_routing_number')->nullable();
            $table->decimal('commission_rate', 5, 2)->default(10.00); // platform commission %

            // Status
            $table->enum('status', ['pending', 'active', 'suspended', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            // Stats (denormalised for performance)
            $table->unsignedBigInteger('total_sales')->default(0);
            $table->decimal('total_revenue', 15, 2)->default(0);
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);

            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
