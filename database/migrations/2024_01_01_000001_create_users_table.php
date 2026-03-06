<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email', 150)->unique();
            $table->string('phone', 20)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('type', ['customer', 'vendor', 'admin'])->default('customer');
            $table->enum('status', ['active', 'suspended', 'pending'])->default('active');
            $table->enum('customer_tier', ['standard', 'silver', 'gold', 'corporate'])->default('standard');
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->decimal('credit_used', 15, 2)->default(0);
            $table->string('avatar')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();

            // Indexes for common lookups
            $table->index('type');
            $table->index('status');
            $table->index('customer_tier');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
