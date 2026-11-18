<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('payment_id');
            $table->string('refund_number', 48);
            $table->string('request_key', 80);
            $table->char('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->decimal('unapplied_refund_amount', 14, 2)->default(0);
            $table->string('status', 24);
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('executed_at')->nullable();
            $table->string('payout_reference', 120)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'payment_refund_tenant_id_uq');
            $table->unique(['tenant_id', 'refund_number'], 'payment_refund_number_uq');
            $table->unique(['tenant_id', 'request_key'], 'payment_refund_request_key_uq');
            $table->unique(['tenant_id', 'payout_reference'], 'payment_refund_payout_ref_uq');
            $table->index(['tenant_id', 'status', 'requested_at'], 'payment_refund_status_idx');
            $table->foreign(['tenant_id', 'payment_id'], 'payment_refund_payment_fk')->references(['tenant_id', 'id'])->on('billing_payments')->restrictOnDelete();
        });

        Schema::create('billing_payment_refund_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('refund_id');
            $table->unsignedBigInteger('payment_allocation_id');
            $table->unsignedBigInteger('invoice_id');
            $table->decimal('amount', 14, 2);
            $table->dateTime('reversed_at');
            $table->timestamps();
            $table->index(['tenant_id', 'payment_allocation_id'], 'payment_refund_alloc_source_idx');
            $table->foreign(['tenant_id', 'refund_id'], 'payment_refund_alloc_refund_fk')->references(['tenant_id', 'id'])->on('billing_payment_refunds')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_allocation_id'], 'payment_refund_alloc_payment_allocation_fk')->references(['tenant_id', 'id'])->on('billing_payment_allocations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'invoice_id'], 'payment_refund_alloc_invoice_fk')->references(['tenant_id', 'id'])->on('billing_invoices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payment_refund_allocations');
        Schema::dropIfExists('billing_payment_refunds');
    }
};
