<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_recurring_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_enrollment_id')->nullable();
            $table->unsignedBigInteger('fee_structure_id');
            $table->string('frequency', 20);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedSmallInteger('due_days_after_period')->default(0);
            $table->string('timezone', 64);
            $table->dateTime('next_run_at');
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'bill_recurring_plan_id_uq');
            $table->unique(['tenant_id', 'student_id', 'fee_structure_id', 'period_start', 'period_end'], 'bill_recurring_student_period_uq');
            $table->index(['tenant_id', 'status', 'next_run_at'], 'bill_recurring_due_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'bill_recurring_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'bill_recurring_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'bill_recurring_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'course_enrollment_id'], 'bill_recurring_enrollment_fk')->references(['tenant_id', 'id'])->on('course_enrollments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'fee_structure_id'], 'bill_recurring_structure_fk')->references(['tenant_id', 'id'])->on('billing_fee_structures')->restrictOnDelete();
        });
        Schema::create('billing_recurring_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->string('failure_code', 64)->nullable();
            $table->dateTime('last_attempt_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'bill_recurring_run_id_uq');
            $table->unique(['tenant_id', 'plan_id', 'period_start', 'period_end'], 'bill_recurring_run_period_uq');
            $table->foreign(['tenant_id', 'plan_id'], 'bill_recurring_run_plan_fk')->references(['tenant_id', 'id'])->on('billing_recurring_plans')->restrictOnDelete();
            $table->foreign(['tenant_id', 'invoice_id'], 'bill_recurring_run_invoice_fk')->references(['tenant_id', 'id'])->on('billing_invoices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_recurring_runs');
        Schema::dropIfExists('billing_recurring_plans');
    }
};
