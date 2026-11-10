<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'member_tenant_id_uq');
        });

        Schema::create('assignment_rubrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('status', 20)->default('draft');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'asrub_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'status'], 'asrub_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'asrub_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('assignment_rubric_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('rubric_id');
            $table->unsignedInteger('version_number');
            $table->decimal('maximum_points', 8, 2);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'asrv_tenant_id_uq');
            $table->unique(['tenant_id', 'rubric_id', 'version_number'], 'asrv_version_uq');
            $table->foreign(['tenant_id', 'rubric_id'], 'asrv_rubric_fk')->references(['tenant_id', 'id'])->on('assignment_rubrics')->cascadeOnDelete();
        });

        Schema::create('assignment_rubric_criteria', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('rubric_version_id');
            $table->unsignedSmallInteger('position');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->decimal('maximum_points', 8, 2);
            $table->json('rating_levels')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'rubric_version_id', 'position'], 'asrc_position_uq');
            $table->foreign(['tenant_id', 'rubric_version_id'], 'asrc_version_fk')->references(['tenant_id', 'id'])->on('assignment_rubric_versions')->cascadeOnDelete();
        });

        Schema::create('assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('offering_id');
            $table->unsignedBigInteger('rubric_version_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 200);
            $table->string('assignment_type', 32)->default('coursework');
            $table->string('category', 100)->nullable();
            $table->text('summary')->nullable();
            $table->longText('instructions')->nullable();
            $table->string('submission_mode', 32)->default('text');
            $table->string('collaboration_mode', 16)->default('individual');
            $table->unsignedSmallInteger('maximum_attempts')->default(1);
            $table->decimal('maximum_points', 8, 2)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->dateTime('available_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('late_until')->nullable();
            $table->string('status', 24)->default('draft');
            $table->boolean('review_required')->default(false);
            $table->unsignedInteger('current_version')->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'asgn_tenant_id_uq');
            $table->index(['tenant_id', 'offering_id', 'status', 'due_at'], 'asgn_offering_due_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'asgn_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'offering_id'], 'asgn_offer_fk')->references(['tenant_id', 'id'])->on('course_offerings')->restrictOnDelete();
            $table->foreign(['tenant_id', 'rubric_version_id'], 'asgn_rubric_fk')->references(['tenant_id', 'id'])->on('assignment_rubric_versions')->restrictOnDelete();
        });

        Schema::create('assignment_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('assignment_id');
            $table->unsignedInteger('version_number');
            $table->json('snapshot');
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'assignment_id', 'version_number'], 'asv_version_uq');
            $table->foreign(['tenant_id', 'assignment_id'], 'asv_assignment_fk')->references(['tenant_id', 'id'])->on('assignments')->cascadeOnDelete();
        });

        Schema::create('assignment_sections', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('assignment_id');
            $table->unsignedBigInteger('section_id');
            $table->timestamps();
            $table->primary(['tenant_id', 'assignment_id', 'section_id'], 'asect_pk');
            $table->foreign(['tenant_id', 'assignment_id'], 'asect_assignment_fk')->references(['tenant_id', 'id'])->on('assignments')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'section_id'], 'asect_section_fk')->references(['tenant_id', 'id'])->on('course_sections')->restrictOnDelete();
        });

        Schema::create('assignment_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('assignment_id');
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('section_id');
            $table->dateTime('eligible_from');
            $table->dateTime('effective_due_at')->nullable();
            $table->string('status', 20)->default('eligible');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'arec_tenant_id_uq');
            $table->unique(['tenant_id', 'assignment_id', 'membership_id'], 'arec_assignment_member_uq');
            $table->index(['tenant_id', 'membership_id', 'status'], 'arec_member_status_idx');
            $table->foreign(['tenant_id', 'assignment_id'], 'arec_assignment_fk')->references(['tenant_id', 'id'])->on('assignments')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'membership_id'], 'arec_member_fk')->references(['tenant_id', 'id'])->on('tenant_memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'arec_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'section_id'], 'arec_section_fk')->references(['tenant_id', 'id'])->on('course_sections')->restrictOnDelete();
        });

        Schema::create('assignment_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('assignment_id');
            $table->unsignedBigInteger('section_id');
            $table->string('name', 120);
            $table->unsignedBigInteger('leader_membership_id')->nullable();
            $table->dateTime('frozen_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'agrp_tenant_id_uq');
            $table->unique(['tenant_id', 'assignment_id', 'section_id', 'name'], 'agrp_name_uq');
            $table->foreign(['tenant_id', 'assignment_id'], 'agrp_assignment_fk')->references(['tenant_id', 'id'])->on('assignments')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'section_id'], 'agrp_section_fk')->references(['tenant_id', 'id'])->on('course_sections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'leader_membership_id'], 'agrp_leader_fk')->references(['tenant_id', 'id'])->on('tenant_memberships')->restrictOnDelete();
        });

        Schema::create('assignment_group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('membership_id');
            $table->dateTime('joined_at');
            $table->dateTime('left_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'group_id', 'membership_id'], 'agmem_group_member_uq');
            $table->foreign(['tenant_id', 'group_id'], 'agmem_group_fk')->references(['tenant_id', 'id'])->on('assignment_groups')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'membership_id'], 'agmem_member_fk')->references(['tenant_id', 'id'])->on('tenant_memberships')->restrictOnDelete();
        });

        Schema::create('assignment_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('assignment_id');
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('status', 24)->default('not_started');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('finalized_at')->nullable();
            $table->json('group_membership_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'asub_tenant_id_uq');
            $table->unique(['tenant_id', 'assignment_id', 'recipient_id'], 'asub_recipient_uq');
            $table->unique(['tenant_id', 'assignment_id', 'group_id'], 'asub_group_uq');
            $table->index(['tenant_id', 'assignment_id', 'status'], 'asub_status_idx');
            $table->foreign(['tenant_id', 'assignment_id'], 'asub_assignment_fk')->references(['tenant_id', 'id'])->on('assignments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'recipient_id'], 'asub_recipient_fk')->references(['tenant_id', 'id'])->on('assignment_recipients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'group_id'], 'asub_group_fk')->references(['tenant_id', 'id'])->on('assignment_groups')->restrictOnDelete();
        });

        Schema::create('assignment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('submission_id');
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 20)->default('draft');
            $table->longText('response_text')->nullable();
            $table->text('response_url')->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->string('receipt_reference', 48)->nullable();
            $table->json('receipt_snapshot')->nullable();
            $table->string('content_sha256', 64)->nullable();
            $table->boolean('is_late')->default(false);
            $table->dateTime('effective_due_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'aat_tenant_id_uq');
            $table->unique(['tenant_id', 'submission_id', 'attempt_number'], 'aat_number_uq');
            $table->unique(['tenant_id', 'idempotency_key'], 'aat_idempotency_uq');
            $table->unique(['tenant_id', 'receipt_reference'], 'aat_receipt_uq');
            $table->foreign(['tenant_id', 'submission_id'], 'aat_submission_fk')->references(['tenant_id', 'id'])->on('assignment_submissions')->cascadeOnDelete();
        });

        Schema::create('assignment_attempt_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('attempt_id');
            $table->unsignedInteger('revision_number');
            $table->json('content_snapshot');
            $table->string('content_sha256', 64);
            $table->foreignId('saved_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('saved_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'attempt_id', 'revision_number'], 'aarev_version_uq');
            $table->foreign(['tenant_id', 'attempt_id'], 'aarev_attempt_fk')->references(['tenant_id', 'id'])->on('assignment_attempts')->cascadeOnDelete();
        });

        Schema::create('assignment_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('attempt_id');
            $table->string('private_path', 512)->unique();
            $table->string('original_name', 255);
            $table->string('mime_type', 127);
            $table->unsignedInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('scan_status', 16)->default('quarantined');
            $table->string('scanner', 120)->nullable();
            $table->string('result_code', 64)->nullable();
            $table->dateTime('scanned_at')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'aart_tenant_id_uq');
            $table->index(['tenant_id', 'attempt_id', 'scan_status'], 'aart_scan_idx');
            $table->foreign(['tenant_id', 'attempt_id'], 'aart_attempt_fk')->references(['tenant_id', 'id'])->on('assignment_attempts')->cascadeOnDelete();
        });

        Schema::create('assignment_extensions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('recipient_id');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->dateTime('previous_due_at')->nullable();
            $table->dateTime('requested_due_at');
            $table->dateTime('effective_due_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'aext_tenant_id_uq');
            $table->index(['tenant_id', 'status', 'created_at'], 'aext_review_idx');
            $table->foreign(['tenant_id', 'recipient_id'], 'aext_recipient_fk')->references(['tenant_id', 'id'])->on('assignment_recipients')->restrictOnDelete();
        });

        Schema::create('assignment_grades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('attempt_id');
            $table->unsignedBigInteger('rubric_version_id')->nullable();
            $table->decimal('score_points', 8, 2)->nullable();
            $table->decimal('maximum_points', 8, 2)->nullable();
            $table->json('criterion_scores')->nullable();
            $table->text('learner_feedback')->nullable();
            $table->text('private_notes')->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignId('marked_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('released_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'agrade_tenant_id_uq');
            $table->unique(['tenant_id', 'attempt_id'], 'agrade_attempt_uq');
            $table->index(['tenant_id', 'status', 'released_at'], 'agrade_release_idx');
            $table->foreign(['tenant_id', 'attempt_id'], 'agrade_attempt_fk')->references(['tenant_id', 'id'])->on('assignment_attempts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'rubric_version_id'], 'agrade_rubric_fk')->references(['tenant_id', 'id'])->on('assignment_rubric_versions')->restrictOnDelete();
        });

        Schema::create('assignment_integrity_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('attempt_id');
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('case_type', 32);
            $table->string('similarity_status', 24)->default('not_configured');
            $table->string('provider_reference', 180)->nullable();
            $table->string('status', 24)->default('open');
            $table->text('concern_summary');
            $table->text('learner_response')->nullable();
            $table->text('review_notes')->nullable();
            $table->string('disposition', 24)->nullable();
            $table->dateTime('response_due_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'aic_tenant_id_uq');
            $table->index(['tenant_id', 'status', 'created_at'], 'aic_review_idx');
            $table->foreign(['tenant_id', 'attempt_id'], 'aic_attempt_fk')->references(['tenant_id', 'id'])->on('assignment_attempts')->restrictOnDelete();
        });

        Schema::create('assignment_outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('event_key', 120);
            $table->string('event_type', 64);
            $table->json('safe_payload');
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('available_at');
            $table->dateTime('locked_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'event_key'], 'aob_event_key_uq');
            $table->index(['tenant_id', 'status', 'available_at'], 'aob_dispatch_idx');
        });

        Schema::create('assignment_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 120);
            $table->string('event_type', 64);
            $table->string('title', 180);
            $table->string('message', 500);
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'event_key'], 'anot_user_event_uq');
            $table->index(['tenant_id', 'user_id', 'read_at', 'created_at'], 'anot_inbox_idx');
        });

        $this->seedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_notifications');
        Schema::dropIfExists('assignment_outbox_events');
        Schema::dropIfExists('assignment_integrity_cases');
        Schema::dropIfExists('assignment_grades');
        Schema::dropIfExists('assignment_extensions');
        Schema::dropIfExists('assignment_artifacts');
        Schema::dropIfExists('assignment_attempt_revisions');
        Schema::dropIfExists('assignment_attempts');
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignment_group_members');
        Schema::dropIfExists('assignment_groups');
        Schema::dropIfExists('assignment_recipients');
        Schema::dropIfExists('assignment_sections');
        Schema::dropIfExists('assignment_versions');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('assignment_rubric_criteria');
        Schema::dropIfExists('assignment_rubric_versions');
        Schema::dropIfExists('assignment_rubrics');
        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->dropUnique('member_tenant_id_uq');
        });
    }

    private function seedPermissions(): void
    {
        $permissions = [
            'assignments.view' => 'View assignments within enrollment and teaching scope.',
            'assignments.manage' => 'Create and maintain draft assignments.',
            'assignments.publish' => 'Publish approved assignment versions.',
            'assignments.review' => 'Independently review assignments requiring approval.',
            'assignments.submission.view' => 'Review submissions for assigned sections.',
            'assignments.submission.create' => 'Create and submit work for the signed-in learner.',
            'assignments.submission.grade' => 'Mark submissions for assigned sections.',
            'assignments.grade.release' => 'Release assignment grades to learners.',
            'assignments.extension.request' => 'Request an extension for eligible coursework.',
            'assignments.extension.review' => 'Review extension requests within assigned scope.',
            'assignments.integrity.view' => 'View authorized academic integrity cases.',
            'assignments.integrity.report' => 'Report a suspected academic integrity concern for independent review.',
            'assignments.integrity.manage' => 'Record human-reviewed integrity case decisions.',
            'assignments.rubrics.manage' => 'Create and version assignment rubrics.',
            'assignments.groups.manage' => 'Manage eligible assignment groups.',
            'assignments.report.view' => 'View scoped assignment and submission reports.',
        ];
        $now = now();
        foreach ($permissions as $key => $description) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['description' => $description, 'scope' => 'tenant', 'updated_at' => $now, 'created_at' => $now]);
        }
        $permissionIds = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id', 'key');
        $mapping = [
            'academic.admin' => array_keys($permissions),
            'teacher' => ['assignments.view', 'assignments.manage', 'assignments.publish', 'assignments.submission.view', 'assignments.submission.grade', 'assignments.extension.review', 'assignments.integrity.report', 'assignments.rubrics.manage', 'assignments.groups.manage', 'assignments.report.view'],
            'student' => ['assignments.view', 'assignments.submission.create', 'assignments.extension.request'],
        ];
        foreach ($mapping as $roleKey => $keys) {
            $roles = DB::table('roles')->where('scope', 'tenant')->where('key', $roleKey)->pluck('id');
            foreach ($roles as $roleId) {
                foreach ($keys as $key) {
                    $permissionId = $permissionIds[$key] ?? null;
                    if ($permissionId !== null) {
                        DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }
        }
    }
};
