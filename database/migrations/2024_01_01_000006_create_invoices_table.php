<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── INVOICE SEQUENCES (per year, guaranteed unique numbers) ──────────
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('month');
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(['year', 'month']);
        });

        // ── INVOICES ─────────────────────────────────────────────────────────
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 30)->unique(); // INV-2024-01-000001
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();

            // Amounts — immutable after creation
            $table->decimal('subtotal', 15, 2);
            $table->decimal('freight_cost', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('amount_due', 15, 2);

            // Dates
            $table->date('issued_at');
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Status
            $table->enum('status', ['draft', 'issued', 'partial', 'paid', 'overdue', 'cancelled', 'void'])
                  ->default('draft');

            // PDF
            $table->string('pdf_path')->nullable();
            $table->timestamp('pdf_generated_at')->nullable();

            // Email delivery
            $table->boolean('email_sent')->default(false);
            $table->timestamp('email_sent_at')->nullable();

            // Notes
            $table->text('notes')->nullable();

            // Lock flag — once paid or void, no edits allowed
            $table->boolean('is_locked')->default(false);

            $table->softDeletes();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['status', 'due_date']);
        });

        // ── PAYMENTS ─────────────────────────────────────────────────────────
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('customer_id')->constrained('users');

            $table->decimal('amount', 15, 2);
            $table->enum('method', ['bkash', 'nagad', 'sslcommerz', 'bank_transfer', 'cod', 'credit_account']);
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded'])->default('pending');

            $table->string('transaction_id')->nullable(); // gateway transaction ID
            $table->json('gateway_response')->nullable(); // full gateway response stored for audit
            $table->timestamp('paid_at')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['invoice_id', 'status']);
        });

        // ── CREDIT LEDGER (append-only, never update rows) ───────────────────
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users');
            $table->enum('type', ['charge', 'payment', 'credit_limit_increase', 'credit_limit_decrease', 'adjustment']);
            $table->decimal('amount', 15, 2);  // always positive
            $table->decimal('balance_after', 15, 2); // running balance snapshot
            $table->string('reference_type')->nullable(); // order | invoice | manual
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
    }
};
