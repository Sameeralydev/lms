<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_fee_heads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('code', 40);
            $table->string('name', 140);
            $table->string('category', 32)->default('tuition');
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'bill_fee_head_tenant_id_uq');
            $table->unique(['tenant_id', 'institution_id', 'code'], 'bill_fee_head_code_uq');
            $table->index(['tenant_id', 'institution_id', 'status'], 'bill_fee_head_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'bill_fee_head_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('billing_fee_structures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->char('currency', 3);
            $table->string('frequency', 24)->default('one_time');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'bill_fee_structure_tenant_id_uq');
            $table->unique(['tenant_id', 'institution_id', 'code'], 'bill_fee_structure_code_uq');
            $table->index(['tenant_id', 'institution_id', 'campus_id', 'status', 'effective_from'], 'bill_fee_structure_scope_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'bill_fee_structure_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'bill_fee_structure_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        Schema::create('billing_fee_structure_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('fee_structure_id');
            $table->unsignedBigInteger('fee_head_id');
            $table->decimal('unit_amount', 14, 2);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'fee_structure_id', 'fee_head_id'], 'bill_structure_head_uq');
            $table->foreign(['tenant_id', 'fee_structure_id'], 'bill_struct_item_structure_fk')->references(['tenant_id', 'id'])->on('billing_fee_structures')->restrictOnDelete();
            $table->foreign(['tenant_id', 'fee_head_id'], 'bill_struct_item_head_fk')->references(['tenant_id', 'id'])->on('billing_fee_heads')->restrictOnDelete();
        });

        Schema::create('billing_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('fee_structure_id');
            $table->string('invoice_number', 48);
            $table->string('idempotency_key', 80);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_on');
            $table->char('currency', 3);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('outstanding_amount', 14, 2);
            $table->string('status', 20)->default('issued');
            $table->dateTime('issued_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'bill_invoice_tenant_id_uq');
            $table->unique(['tenant_id', 'invoice_number'], 'bill_invoice_number_uq');
            $table->unique(['tenant_id', 'idempotency_key'], 'bill_invoice_idempotency_uq');
            $table->unique(['tenant_id', 'student_id', 'fee_structure_id', 'period_start', 'period_end'], 'bill_invoice_period_uq');
            $table->index(['tenant_id', 'institution_id', 'campus_id', 'status', 'due_on'], 'bill_invoice_due_idx');
            $table->index(['tenant_id', 'student_id', 'issued_at'], 'bill_invoice_student_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'bill_invoice_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'bill_invoice_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'bill_invoice_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'fee_structure_id'], 'bill_invoice_structure_fk')->references(['tenant_id', 'id'])->on('billing_fee_structures')->restrictOnDelete();
        });

        Schema::create('billing_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('invoice_id');
            $table->string('fee_head_code', 40);
            $table->string('fee_head_name', 140);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('unit_amount', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
            $table->index(['tenant_id', 'invoice_id'], 'bill_invoice_item_idx');
            $table->foreign(['tenant_id', 'invoice_id'], 'bill_invoice_item_invoice_fk')->references(['tenant_id', 'id'])->on('billing_invoices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoice_items');
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('billing_fee_structure_items');
        Schema::dropIfExists('billing_fee_structures');
        Schema::dropIfExists('billing_fee_heads');
    }
};
