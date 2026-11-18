<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_fee_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('fee_structure_id');
            $table->unsignedBigInteger('course_enrollment_id')->nullable();
            $table->unsignedBigInteger('academic_period_id')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('source', 24)->default('manual');
            $table->string('status', 20)->default('assigned');
            $table->string('idempotency_key', 100);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('context_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'bill_assignment_tenant_id_uq');
            $table->unique(['tenant_id', 'idempotency_key'], 'bill_assignment_idem_uq');
            $table->unique(['tenant_id', 'student_id', 'fee_structure_id', 'period_start', 'period_end'], 'bill_assignment_period_uq');
            $table->index(['tenant_id', 'institution_id', 'campus_id', 'status', 'effective_from'], 'bill_assignment_scope_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'bill_assignment_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'bill_assignment_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'bill_assignment_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'fee_structure_id'], 'bill_assignment_structure_fk')->references(['tenant_id', 'id'])->on('billing_fee_structures')->restrictOnDelete();
            $table->foreign(['tenant_id', 'course_enrollment_id'], 'bill_assignment_enrollment_fk')->references(['tenant_id', 'id'])->on('course_enrollments')->restrictOnDelete();
        });

        Schema::create('billing_concessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('student_id');
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('kind', 24);
            $table->decimal('value', 14, 2);
            $table->string('status', 24)->default('pending_approval');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'bill_concession_tenant_id_uq');
            $table->unique(['tenant_id', 'student_id', 'code'], 'bill_concession_student_code_uq');
            $table->index(['tenant_id', 'institution_id', 'campus_id', 'status', 'effective_from'], 'bill_concession_scope_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'bill_concession_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'bill_concession_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'bill_concession_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
        });

        Schema::create('billing_concession_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('concession_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 32);
            $table->string('request_key', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'request_key'], 'bill_concession_event_req_uq');
            $table->index(['tenant_id', 'concession_id', 'occurred_at'], 'bill_concession_event_idx');
            $table->foreign(['tenant_id', 'concession_id'], 'bill_concession_event_fk')->references(['tenant_id', 'id'])->on('billing_concessions')->restrictOnDelete();
        });

        Schema::create('billing_concession_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('concession_id');
            $table->unsignedBigInteger('invoice_id');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_amount', 14, 2);
            $table->json('calculation_snapshot');
            $table->dateTime('applied_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'invoice_id'], 'bill_concession_invoice_uq');
            $table->unique(['tenant_id', 'id'], 'bill_concession_application_id_uq');
            $table->foreign(['tenant_id', 'concession_id'], 'bill_concession_application_fk')->references(['tenant_id', 'id'])->on('billing_concessions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'invoice_id'], 'bill_concession_invoice_fk')->references(['tenant_id', 'id'])->on('billing_invoices')->restrictOnDelete();
        });

        Schema::create('billing_installment_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('invoice_id');
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('installment_count');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'bill_installment_plan_id_uq');
            $table->unique(['tenant_id', 'invoice_id'], 'bill_installment_invoice_uq');
            $table->foreign(['tenant_id', 'invoice_id'], 'bill_installment_invoice_fk')->references(['tenant_id', 'id'])->on('billing_invoices')->restrictOnDelete();
        });

        Schema::create('billing_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('plan_id');
            $table->unsignedSmallInteger('sequence');
            $table->date('due_on');
            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('scheduled');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'bill_installment_item_id_uq');
            $table->unique(['tenant_id', 'plan_id', 'sequence'], 'bill_installment_sequence_uq');
            $table->foreign(['tenant_id', 'plan_id'], 'bill_installment_plan_fk')->references(['tenant_id', 'id'])->on('billing_installment_plans')->restrictOnDelete();
        });

        Schema::create('billing_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('invoice_id');
            $table->string('adjustment_number', 48);
            $table->string('idempotency_key', 100);
            $table->string('kind', 16);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('status', 24)->default('pending_approval');
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'bill_adjustment_id_uq');
            $table->unique(['tenant_id', 'adjustment_number'], 'bill_adjustment_number_uq');
            $table->unique(['tenant_id', 'idempotency_key'], 'bill_adjustment_idem_uq');
            $table->index(['tenant_id', 'invoice_id', 'status', 'created_at'], 'bill_adjustment_invoice_idx');
            $table->foreign(['tenant_id', 'invoice_id'], 'bill_adjustment_invoice_fk')->references(['tenant_id', 'id'])->on('billing_invoices')->restrictOnDelete();
        });

        Schema::create('billing_adjustment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('adjustment_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 32);
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'adjustment_id', 'occurred_at'], 'bill_adjustment_event_idx');
            $table->foreign(['tenant_id', 'adjustment_id'], 'bill_adjustment_event_fk')->references(['tenant_id', 'id'])->on('billing_adjustments')->restrictOnDelete();
        });

        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('fee_assignment_id')->nullable();
            $table->unsignedBigInteger('concession_id')->nullable();
            $table->decimal('adjusted_total', 14, 2)->default(0);
            $table->json('concession_snapshot')->nullable();
            $table->foreign(['tenant_id', 'fee_assignment_id'], 'bill_invoice_assignment_fk')->references(['tenant_id', 'id'])->on('billing_fee_assignments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'concession_id'], 'bill_invoice_concession_fk')->references(['tenant_id', 'id'])->on('billing_concessions')->restrictOnDelete();
        });
        DB::table('billing_invoices')->where('adjusted_total', 0)->update(['adjusted_total' => DB::raw('total')]);
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropForeign('bill_invoice_assignment_fk');
            $table->dropForeign('bill_invoice_concession_fk');
            $table->dropColumn(['fee_assignment_id', 'concession_id', 'adjusted_total', 'concession_snapshot']);
        });
        foreach ([
            'billing_adjustment_events', 'billing_adjustments', 'billing_installments', 'billing_installment_plans',
            'billing_concession_applications', 'billing_concession_events', 'billing_concessions', 'billing_fee_assignments',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
