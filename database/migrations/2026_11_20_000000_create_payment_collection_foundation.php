<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('student_id');
            $table->string('payment_number', 48);
            $table->string('idempotency_key', 80);
            $table->string('method', 24);
            $table->string('status', 24);
            $table->char('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->decimal('unapplied_amount', 14, 2)->default(0);
            $table->string('reference', 120)->nullable();
            $table->string('receipt_number', 48)->nullable();
            $table->dateTime('recorded_at');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('verification_note')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'payment_tenant_id_uq');
            $table->unique(['tenant_id', 'payment_number'], 'payment_number_uq');
            $table->unique(['tenant_id', 'idempotency_key'], 'payment_idempotency_uq');
            $table->unique(['tenant_id', 'institution_id', 'method', 'reference'], 'payment_reference_uq');
            $table->unique(['tenant_id', 'receipt_number'], 'payment_receipt_uq');
            $table->index(['tenant_id', 'institution_id', 'campus_id', 'status', 'recorded_at'], 'payment_scope_status_idx');
            $table->index(['tenant_id', 'student_id', 'recorded_at'], 'payment_student_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'payment_institution_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'payment_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'payment_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
        });

        Schema::create('billing_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('invoice_id');
            $table->string('request_key', 100);
            $table->decimal('amount', 14, 2);
            $table->foreignId('allocated_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('allocated_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'payment_allocation_tenant_id_uq');
            $table->unique(['tenant_id', 'request_key'], 'payment_allocation_idempotency_uq');
            $table->index(['tenant_id', 'payment_id', 'invoice_id'], 'payment_allocation_payment_invoice_idx');
            $table->foreign(['tenant_id', 'payment_id'], 'payment_allocation_payment_fk')->references(['tenant_id', 'id'])->on('billing_payments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'invoice_id'], 'payment_allocation_invoice_fk')->references(['tenant_id', 'id'])->on('billing_invoices')->restrictOnDelete();
        });

        Schema::create('billing_payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('payment_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 48);
            $table->string('request_key', 100)->nullable();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'request_key'], 'payment_event_idempotency_uq');
            $table->index(['tenant_id', 'payment_id', 'occurred_at'], 'payment_event_history_idx');
            $table->foreign(['tenant_id', 'payment_id'], 'payment_event_payment_fk')->references(['tenant_id', 'id'])->on('billing_payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payment_events');
        Schema::dropIfExists('billing_payment_allocations');
        Schema::dropIfExists('billing_payments');
    }
};
