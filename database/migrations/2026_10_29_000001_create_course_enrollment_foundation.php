<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('name', 140);
            $table->unsignedSmallInteger('minimum_courses')->default(0);
            $table->unsignedSmallInteger('maximum_courses')->default(8);
            $table->decimal('maximum_credits', 8, 3)->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->boolean('waitlist_enabled')->default(true);
            $table->unsignedSmallInteger('waitlist_offer_hours')->default(48);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'enroll_policy_tenant_id_uq');
            $table->unique(['tenant_id', 'institution_id', 'name'], 'enroll_policy_name_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'enroll_policy_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedBigInteger('academic_period_id');
            $table->unsignedBigInteger('academic_program_id')->nullable();
            $table->unsignedBigInteger('academic_course_id');
            $table->unsignedBigInteger('academic_course_version_id')->nullable();
            $table->unsignedBigInteger('policy_id');
            $table->unsignedBigInteger('delivery_workspace_id')->nullable();
            $table->string('offering_code', 48);
            $table->string('delivery_mode', 24)->default('in_person');
            $table->string('status', 20)->default('draft');
            $table->dateTime('registration_opens_at')->nullable();
            $table->dateTime('registration_closes_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'course_offer_tenant_id_uq');
            $table->unique(['tenant_id', 'institution_id', 'academic_period_id', 'offering_code'], 'course_offer_code_uq');
            $table->index(['tenant_id', 'institution_id', 'academic_period_id', 'status'], 'course_offer_period_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'course_offer_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'course_offer_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_year_id'], 'course_offer_year_fk')->references(['tenant_id', 'id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_period_id'], 'course_offer_period_fk')->references(['tenant_id', 'id'])->on('academic_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_program_id'], 'course_offer_program_fk')->references(['tenant_id', 'id'])->on('academic_programs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_course_id'], 'course_offer_course_fk')->references(['tenant_id', 'id'])->on('academic_courses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_course_version_id'], 'course_offer_version_fk')->references(['tenant_id', 'id'])->on('academic_course_versions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'policy_id'], 'course_offer_policy_fk')->references(['tenant_id', 'id'])->on('enrollment_policies')->restrictOnDelete();
            $table->foreign(['tenant_id', 'delivery_workspace_id'], 'course_offer_workspace_fk')->references(['tenant_id', 'id'])->on('course_delivery_workspaces')->restrictOnDelete();
        });

        Schema::create('course_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('offering_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->string('section_code', 32);
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('reserved_seats')->default(0);
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'course_section_tenant_id_uq');
            $table->unique(['tenant_id', 'offering_id', 'section_code'], 'course_section_code_uq');
            $table->index(['tenant_id', 'offering_id', 'status'], 'course_section_offering_idx');
            $table->foreign(['tenant_id', 'offering_id'], 'course_section_offer_fk')->references(['tenant_id', 'id'])->on('course_offerings')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'course_section_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        Schema::create('course_section_instructors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('section_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 24)->default('instructor');
            $table->timestamps();
            $table->unique(['tenant_id', 'section_id', 'user_id'], 'course_section_teacher_uq');
            $table->foreign(['tenant_id', 'section_id'], 'course_section_teacher_section_fk')->references(['tenant_id', 'id'])->on('course_sections')->cascadeOnDelete();
        });

        Schema::create('course_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('offering_id');
            $table->unsignedBigInteger('section_id');
            $table->string('status', 24)->default('pending');
            $table->string('registration_source', 24)->default('student');
            $table->string('idempotency_key', 80)->nullable();
            $table->dateTime('requested_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('dropped_at')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'course_enroll_tenant_id_uq');
            $table->unique(['tenant_id', 'student_id', 'offering_id'], 'course_enroll_student_offer_uq');
            $table->unique(['tenant_id', 'idempotency_key'], 'course_enroll_idem_uq');
            $table->index(['tenant_id', 'section_id', 'status'], 'course_enroll_section_status_idx');
            $table->index(['tenant_id', 'student_id', 'status'], 'course_enroll_student_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'course_enroll_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'course_enroll_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'offering_id'], 'course_enroll_offer_fk')->references(['tenant_id', 'id'])->on('course_offerings')->restrictOnDelete();
            $table->foreign(['tenant_id', 'section_id'], 'course_enroll_section_fk')->references(['tenant_id', 'id'])->on('course_sections')->restrictOnDelete();
        });

        Schema::create('course_waitlist_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('offering_id');
            $table->unsignedBigInteger('section_id');
            $table->unsignedInteger('position');
            $table->string('status', 24)->default('waiting');
            $table->dateTime('offered_at')->nullable();
            $table->dateTime('offer_expires_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'course_waitlist_tenant_id_uq');
            $table->unique(['tenant_id', 'student_id', 'section_id'], 'course_waitlist_student_section_uq');
            $table->unique(['tenant_id', 'section_id', 'position'], 'course_waitlist_position_uq');
            $table->index(['tenant_id', 'section_id', 'status', 'position'], 'course_waitlist_queue_idx');
            $table->foreign(['tenant_id', 'student_id'], 'course_waitlist_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'offering_id'], 'course_waitlist_offer_fk')->references(['tenant_id', 'id'])->on('course_offerings')->restrictOnDelete();
            $table->foreign(['tenant_id', 'section_id'], 'course_waitlist_section_fk')->references(['tenant_id', 'id'])->on('course_sections')->restrictOnDelete();
        });

        Schema::create('course_registration_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->string('hold_type', 24);
            $table->string('reason_code', 48);
            $table->text('reason');
            $table->dateTime('effective_at');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'student_id', 'revoked_at', 'expires_at'], 'course_hold_active_idx');
            $table->foreign(['tenant_id', 'student_id'], 'course_hold_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_registration_holds');
        Schema::dropIfExists('course_waitlist_entries');
        Schema::dropIfExists('course_enrollments');
        Schema::dropIfExists('course_section_instructors');
        Schema::dropIfExists('course_sections');
        Schema::dropIfExists('course_offerings');
        Schema::dropIfExists('enrollment_policies');
    }
};
