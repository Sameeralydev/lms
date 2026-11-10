<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sis_student_promotion_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedBigInteger('academic_period_id')->nullable();
            $table->unsignedBigInteger('academic_program_id')->nullable();
            $table->unsignedBigInteger('academic_class_id');
            $table->unsignedBigInteger('academic_section_id')->nullable();
            $table->date('effective_from');
            $table->string('reason_code', 48);
            $table->string('reason', 500)->nullable();
            $table->dateTime('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'sis_promo_batch_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'requested_at'], 'sis_promo_batch_review_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'sis_promo_batch_inst_fk')
                ->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'sis_promo_batch_campus_fk')
                ->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_year_id'], 'sis_promo_batch_year_fk')
                ->references(['tenant_id', 'id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_period_id'], 'sis_promo_batch_period_fk')
                ->references(['tenant_id', 'id'])->on('academic_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_program_id'], 'sis_promo_batch_program_fk')
                ->references(['tenant_id', 'id'])->on('academic_programs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_class_id'], 'sis_promo_batch_class_fk')
                ->references(['tenant_id', 'id'])->on('academic_classes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_section_id'], 'sis_promo_batch_section_fk')
                ->references(['tenant_id', 'id'])->on('academic_sections')->restrictOnDelete();
        });

        Schema::create('sis_student_promotion_batch_students', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('promotion_batch_id');
            $table->unsignedBigInteger('student_id');
            $table->string('outcome_status', 16)->default('pending');
            $table->string('outcome_reason', 500)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'promotion_batch_id', 'student_id'], 'sis_promo_batch_student_uq');
            $table->index(['tenant_id', 'student_id', 'outcome_status'], 'sis_promo_outcome_student_idx');
            $table->foreign(['tenant_id', 'promotion_batch_id'], 'sis_promo_batch_student_batch_fk')
                ->references(['tenant_id', 'id'])->on('sis_student_promotion_batches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'sis_promo_batch_student_student_fk')
                ->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sis_student_promotion_batch_students');
        Schema::dropIfExists('sis_student_promotion_batches');
    }
};
