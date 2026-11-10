<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->string('campus_scope_key', 24)->default('institution');
            $table->string('name', 120);
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('current_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'att_policy_tenant_id_uq');
            $table->unique(['tenant_id', 'institution_id', 'campus_scope_key', 'name'], 'att_policy_scope_name_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'att_policy_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'att_policy_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        Schema::create('attendance_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('policy_id');
            $table->unsignedInteger('version');
            $table->dateTime('effective_from');
            $table->json('config_snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'att_policy_ver_tenant_id_uq');
            $table->unique(['tenant_id', 'policy_id', 'version'], 'att_policy_ver_number_uq');
            $table->foreign(['tenant_id', 'policy_id'], 'att_policy_ver_policy_fk')->references(['tenant_id', 'id'])->on('attendance_policies')->restrictOnDelete();
        });

        Schema::create('attendance_status_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('policy_version_id');
            $table->string('code', 24);
            $table->string('label', 60);
            $table->string('color', 7)->default('#64748b');
            $table->boolean('counts_attended')->default(false);
            $table->boolean('counts_expected')->default(true);
            $table->boolean('requires_reason')->default(false);
            $table->boolean('requires_evidence')->default(false);
            $table->boolean('system_controlled')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'att_status_tenant_id_uq');
            $table->unique(['tenant_id', 'policy_version_id', 'code'], 'att_status_policy_code_uq');
            $table->foreign(['tenant_id', 'policy_version_id'], 'att_status_policy_ver_fk')->references(['tenant_id', 'id'])->on('attendance_policy_versions')->restrictOnDelete();
        });

        Schema::create('attendance_registers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('meeting_id');
            $table->date('occurrence_date');
            $table->unsignedBigInteger('policy_version_id');
            $table->foreignId('instructor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('delivery_mode', 16);
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('expected_count')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('roster_snapshotted_at');
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reopen_reason_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'att_register_tenant_id_uq');
            $table->unique(['tenant_id', 'meeting_id', 'occurrence_date'], 'att_register_occurrence_uq');
            $table->index(['tenant_id', 'institution_id', 'campus_id', 'status', 'occurrence_date'], 'att_register_scope_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'att_register_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'att_register_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'meeting_id'], 'att_register_meeting_fk')->references(['tenant_id', 'id'])->on('academic_timetable_meetings')->restrictOnDelete();
            $table->foreign(['tenant_id', 'policy_version_id'], 'att_register_policy_ver_fk')->references(['tenant_id', 'id'])->on('attendance_policy_versions')->restrictOnDelete();
        });

        Schema::create('attendance_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('register_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('status_definition_id')->nullable();
            $table->unsignedSmallInteger('minutes_attended')->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->text('instructor_note')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('marked_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'att_entry_tenant_id_uq');
            $table->unique(['tenant_id', 'register_id', 'student_id'], 'att_entry_learner_uq');
            $table->index(['tenant_id', 'student_id', 'register_id'], 'att_entry_student_register_idx');
            $table->foreign(['tenant_id', 'register_id'], 'att_entry_register_fk')->references(['tenant_id', 'id'])->on('attendance_registers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'att_entry_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'enrollment_id'], 'att_entry_enrollment_fk')->references(['tenant_id', 'id'])->on('course_enrollments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'status_definition_id'], 'att_entry_status_fk')->references(['tenant_id', 'id'])->on('attendance_status_definitions')->restrictOnDelete();
        });

        Schema::create('attendance_correction_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('entry_id');
            $table->unsignedBigInteger('proposed_status_id');
            $table->unsignedSmallInteger('proposed_minutes_attended')->nullable();
            $table->text('reason');
            $table->string('status', 16)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reviewer_note')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at'], 'att_correction_queue_idx');
            $table->foreign(['tenant_id', 'entry_id'], 'att_correction_entry_fk')->references(['tenant_id', 'id'])->on('attendance_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'proposed_status_id'], 'att_correction_status_fk')->references(['tenant_id', 'id'])->on('attendance_status_definitions')->restrictOnDelete();
        });

        Schema::create('attendance_entry_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('entry_id');
            $table->unsignedBigInteger('previous_status_id')->nullable();
            $table->unsignedBigInteger('new_status_id')->nullable();
            $table->unsignedSmallInteger('previous_minutes_attended')->nullable();
            $table->unsignedSmallInteger('new_minutes_attended')->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('change_source', 24);
            $table->dateTime('created_at');
            $table->index(['tenant_id', 'entry_id', 'created_at'], 'att_entry_revision_history_idx');
            $table->foreign(['tenant_id', 'entry_id'], 'att_revision_entry_fk')->references(['tenant_id', 'id'])->on('attendance_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'previous_status_id'], 'att_revision_previous_status_fk')->references(['tenant_id', 'id'])->on('attendance_status_definitions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'new_status_id'], 'att_revision_new_status_fk')->references(['tenant_id', 'id'])->on('attendance_status_definitions')->restrictOnDelete();
        });

        Schema::create('attendance_justifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('entry_id');
            $table->foreignId('student_user_id')->constrained('users')->restrictOnDelete();
            $table->string('open_key', 64)->nullable();
            $table->string('category', 48);
            $table->text('reason');
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reviewer_note')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at'], 'att_justification_queue_idx');
            $table->unique(['tenant_id', 'open_key'], 'att_justification_open_uq');
            $table->foreign(['tenant_id', 'entry_id'], 'att_justification_entry_fk')->references(['tenant_id', 'id'])->on('attendance_entries')->restrictOnDelete();
        });

        Schema::create('attendance_outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('event_key', 120);
            $table->string('event_type', 80);
            $table->json('safe_payload');
            $table->string('status', 16)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('available_at');
            $table->dateTime('delivered_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'event_key'], 'att_outbox_event_key_uq');
            $table->index(['status', 'available_at'], 'att_outbox_delivery_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_outbox_events');
        Schema::dropIfExists('attendance_justifications');
        Schema::dropIfExists('attendance_entry_revisions');
        Schema::dropIfExists('attendance_correction_requests');
        Schema::dropIfExists('attendance_entries');
        Schema::dropIfExists('attendance_registers');
        Schema::dropIfExists('attendance_status_definitions');
        Schema::dropIfExists('attendance_policy_versions');
        Schema::dropIfExists('attendance_policies');
    }
};
