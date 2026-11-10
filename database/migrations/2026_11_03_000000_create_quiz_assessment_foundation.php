<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_question_banks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('academic_course_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('sharing_scope', 20)->default('campus');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'qqb_tenant_id_uq');
            $table->unique(['tenant_id', 'code'], 'qqb_tenant_code_uq');
            $table->index(['tenant_id', 'institution_id', 'status'], 'qqb_scope_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'qqb_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'qqb_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_course_id'], 'qqb_course_fk')->references(['tenant_id', 'id'])->on('academic_courses')->restrictOnDelete();
        });

        Schema::create('quiz_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('bank_id');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'qq_tenant_id_uq');
            $table->index(['tenant_id', 'bank_id', 'status'], 'qq_bank_status_idx');
            $table->foreign(['tenant_id', 'bank_id'], 'qq_bank_fk')->references(['tenant_id', 'id'])->on('quiz_question_banks')->cascadeOnDelete();
        });

        Schema::create('quiz_question_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('question_id');
            $table->unsignedInteger('version_number');
            $table->string('question_type', 32);
            $table->string('topic', 120)->nullable();
            $table->string('subtopic', 120)->nullable();
            $table->string('difficulty', 16)->default('moderate');
            $table->unsignedSmallInteger('estimated_seconds')->default(60);
            $table->decimal('default_points', 8, 2)->default(1);
            $table->json('content');
            $table->longText('answer_key')->nullable();
            $table->json('scoring_rules')->nullable();
            $table->json('hints')->nullable();
            $table->text('explanation')->nullable();
            $table->string('language', 16)->default('en');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'question_id', 'version_number'], 'qqv_question_version_uq');
            $table->unique(['tenant_id', 'id'], 'qqv_tenant_id_uq');
            $table->index(['tenant_id', 'question_type', 'difficulty', 'status'], 'qqv_search_idx');
            $table->foreign(['tenant_id', 'question_id'], 'qqv_question_fk')->references(['tenant_id', 'id'])->on('quiz_questions')->cascadeOnDelete();
        });

        Schema::create('quiz_question_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 48);
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'qqt_tenant_id_uq');
            $table->unique(['tenant_id', 'name'], 'qqt_tenant_name_uq');
        });

        Schema::create('quiz_question_tag_links', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('question_id');
            $table->unsignedBigInteger('tag_id');
            $table->timestamps();
            $table->primary(['tenant_id', 'question_id', 'tag_id'], 'qqtl_pk');
            $table->foreign(['tenant_id', 'question_id'], 'qqtl_question_fk')->references(['tenant_id', 'id'])->on('quiz_questions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'tag_id'], 'qqtl_tag_fk')->references(['tenant_id', 'id'])->on('quiz_question_tags')->cascadeOnDelete();
        });

        Schema::create('quiz_question_outcomes', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('question_version_id');
            $table->unsignedBigInteger('learning_outcome_id');
            $table->timestamps();
            $table->primary(['tenant_id', 'question_version_id', 'learning_outcome_id'], 'qqo_pk');
            $table->foreign(['tenant_id', 'question_version_id'], 'qqo_version_fk')->references(['tenant_id', 'id'])->on('quiz_question_versions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'learning_outcome_id'], 'qqo_outcome_fk')->references(['tenant_id', 'id'])->on('academic_learning_outcomes')->restrictOnDelete();
        });

        Schema::create('quizzes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('offering_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('current_version')->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'quiz_tenant_id_uq');
            $table->index(['tenant_id', 'offering_id', 'status'], 'quiz_offering_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'quiz_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'offering_id'], 'quiz_offering_fk')->references(['tenant_id', 'id'])->on('course_offerings')->restrictOnDelete();
        });

        Schema::create('quiz_sections', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quiz_id');
            $table->unsignedBigInteger('section_id');
            $table->timestamps();
            $table->primary(['tenant_id', 'quiz_id', 'section_id'], 'qsec_pk');
            $table->foreign(['tenant_id', 'quiz_id'], 'qsec_quiz_fk')->references(['tenant_id', 'id'])->on('quizzes')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'section_id'], 'qsec_section_fk')->references(['tenant_id', 'id'])->on('course_sections')->restrictOnDelete();
        });

        Schema::create('quiz_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quiz_id');
            $table->unsignedInteger('version_number');
            $table->string('title', 200);
            $table->string('category', 48)->default('formative');
            $table->string('mode', 24)->default('graded');
            $table->text('description')->nullable();
            $table->longText('instructions');
            $table->json('settings');
            $table->decimal('total_points', 9, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'quiz_id', 'version_number'], 'qv_quiz_version_uq');
            $table->unique(['tenant_id', 'id'], 'qv_tenant_id_uq');
            $table->index(['tenant_id', 'status', 'published_at'], 'qv_status_pub_idx');
            $table->foreign(['tenant_id', 'quiz_id'], 'qv_quiz_fk')->references(['tenant_id', 'id'])->on('quizzes')->cascadeOnDelete();
        });

        Schema::create('quiz_version_questions', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quiz_version_id');
            $table->unsignedBigInteger('question_version_id');
            $table->unsignedInteger('position');
            $table->decimal('points', 8, 2);
            $table->string('group_key', 64)->nullable();
            $table->timestamps();
            $table->primary(['tenant_id', 'quiz_version_id', 'question_version_id'], 'qvq_pk');
            $table->unique(['tenant_id', 'quiz_version_id', 'position'], 'qvq_position_uq');
            $table->foreign(['tenant_id', 'quiz_version_id'], 'qvq_version_fk')->references(['tenant_id', 'id'])->on('quiz_versions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'question_version_id'], 'qvq_question_version_fk')->references(['tenant_id', 'id'])->on('quiz_question_versions')->restrictOnDelete();
        });

        Schema::create('quiz_question_pools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quiz_version_id');
            $table->string('pool_key', 64);
            $table->string('topic', 120)->nullable();
            $table->string('difficulty', 16)->nullable();
            $table->unsignedSmallInteger('select_count');
            $table->decimal('points_per_question', 8, 2);
            $table->boolean('required')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'qqp_tenant_id_uq');
            $table->unique(['tenant_id', 'quiz_version_id', 'pool_key'], 'qqp_version_key_uq');
            $table->foreign(['tenant_id', 'quiz_version_id'], 'qqp_version_fk')->references(['tenant_id', 'id'])->on('quiz_versions')->cascadeOnDelete();
        });

        Schema::create('quiz_pool_question_versions', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('pool_id');
            $table->unsignedBigInteger('question_version_id');
            $table->timestamps();
            $table->primary(['tenant_id', 'pool_id', 'question_version_id'], 'qpqv_pk');
            $table->foreign(['tenant_id', 'pool_id'], 'qpqv_pool_fk')->references(['tenant_id', 'id'])->on('quiz_question_pools')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'question_version_id'], 'qpqv_question_fk')->references(['tenant_id', 'id'])->on('quiz_question_versions')->restrictOnDelete();
        });

        Schema::create('quiz_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quiz_id');
            $table->unsignedBigInteger('quiz_version_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('membership_id');
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 24)->default('in_progress');
            $table->dateTime('started_at');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('graded_at')->nullable();
            $table->dateTime('result_released_at')->nullable();
            $table->json('question_snapshot');
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('idempotency_key', 80)->nullable();
            $table->string('receipt_reference', 48)->nullable();
            $table->decimal('score_points', 9, 2)->nullable();
            $table->decimal('maximum_points', 9, 2)->nullable();
            $table->decimal('percentage', 6, 2)->nullable();
            $table->boolean('manual_review_pending')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'qat_tenant_id_uq');
            $table->unique(['tenant_id', 'quiz_id', 'membership_id', 'attempt_number'], 'qat_attempt_num_uq');
            $table->unique(['tenant_id', 'membership_id', 'idempotency_key'], 'qat_member_idem_uq');
            $table->unique(['tenant_id', 'receipt_reference'], 'qat_receipt_uq');
            $table->index(['tenant_id', 'quiz_id', 'status'], 'qat_quiz_status_idx');
            $table->index(['tenant_id', 'student_id', 'started_at'], 'qat_student_started_idx');
            $table->foreign(['tenant_id', 'quiz_id'], 'qat_quiz_fk')->references(['tenant_id', 'id'])->on('quizzes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'quiz_version_id'], 'qat_version_fk')->references(['tenant_id', 'id'])->on('quiz_versions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'qat_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'membership_id'], 'qat_member_fk')->references(['tenant_id', 'id'])->on('tenant_memberships')->restrictOnDelete();
        });

        Schema::create('quiz_attempt_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('attempt_id');
            $table->unsignedBigInteger('question_version_id');
            $table->longText('response_payload')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->dateTime('saved_at')->nullable();
            $table->decimal('points_awarded', 8, 2)->nullable();
            $table->string('grading_status', 20)->default('ungraded');
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('graded_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'qar_tenant_id_uq');
            $table->unique(['tenant_id', 'attempt_id', 'question_version_id'], 'qar_attempt_question_uq');
            $table->index(['tenant_id', 'grading_status', 'created_at'], 'qar_grading_queue_idx');
            $table->foreign(['tenant_id', 'attempt_id'], 'qar_attempt_fk')->references(['tenant_id', 'id'])->on('quiz_attempts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'question_version_id'], 'qar_question_fk')->references(['tenant_id', 'id'])->on('quiz_question_versions')->restrictOnDelete();
        });

        Schema::create('quiz_response_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('response_id');
            $table->unsignedInteger('revision_number');
            $table->longText('response_payload');
            $table->foreignId('saved_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at');
            $table->unique(['tenant_id', 'response_id', 'revision_number'], 'qrr_response_rev_uq');
            $table->foreign(['tenant_id', 'response_id'], 'qrr_response_fk')->references(['tenant_id', 'id'])->on('quiz_attempt_responses')->cascadeOnDelete();
        });

        Schema::create('quiz_grading_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('response_id');
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision_number')->default(1);
            $table->decimal('points_awarded', 8, 2);
            $table->text('learner_feedback')->nullable();
            $table->text('private_notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'response_id', 'revision_number'], 'qgr_response_rev_uq');
            $table->foreign(['tenant_id', 'response_id'], 'qgr_response_fk')->references(['tenant_id', 'id'])->on('quiz_attempt_responses')->cascadeOnDelete();
        });

        Schema::create('quiz_regrade_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('attempt_id');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('open');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_notes')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at'], 'qrrq_status_created_idx');
            $table->foreign(['tenant_id', 'attempt_id'], 'qrrq_attempt_fk')->references(['tenant_id', 'id'])->on('quiz_attempts')->restrictOnDelete();
        });

        Schema::create('quiz_assessment_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->string('scope_key', 32)->default('institution');
            $table->json('settings');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'scope_key'], 'qap_scope_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'qap_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'qap_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        Schema::create('quiz_outbox_events', function (Blueprint $table): void {
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
            $table->unique(['tenant_id', 'event_key'], 'qob_event_key_uq');
            $table->index(['tenant_id', 'status', 'available_at'], 'qob_dispatch_idx');
        });

        Schema::create('quiz_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 120);
            $table->string('event_type', 64);
            $table->string('title', 180);
            $table->string('message', 500);
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'event_key'], 'qn_user_event_uq');
            $table->index(['tenant_id', 'user_id', 'read_at', 'created_at'], 'qn_inbox_idx');
        });

        $this->seedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_notifications');
        Schema::dropIfExists('quiz_outbox_events');
        Schema::dropIfExists('quiz_assessment_policies');
        Schema::dropIfExists('quiz_regrade_requests');
        Schema::dropIfExists('quiz_grading_reviews');
        Schema::dropIfExists('quiz_response_revisions');
        Schema::dropIfExists('quiz_attempt_responses');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('quiz_pool_question_versions');
        Schema::dropIfExists('quiz_question_pools');
        Schema::dropIfExists('quiz_version_questions');
        Schema::dropIfExists('quiz_versions');
        Schema::dropIfExists('quiz_sections');
        Schema::dropIfExists('quizzes');
        Schema::dropIfExists('quiz_question_outcomes');
        Schema::dropIfExists('quiz_question_tag_links');
        Schema::dropIfExists('quiz_question_tags');
        Schema::dropIfExists('quiz_question_versions');
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('quiz_question_banks');
    }

    private function seedPermissions(): void
    {
        $permissions = [
            'assessment.bank.view' => 'View question banks within institution and campus scope.',
            'assessment.bank.manage' => 'Create and revise draft question bank content.',
            'assessment.bank.review' => 'Independently review and activate question versions.',
            'assessment.bank.share' => 'Authorize institution-scoped question bank sharing.',
            'assessment.quiz.view' => 'View quizzes within course and section scope.',
            'assessment.quiz.manage' => 'Create and edit draft quizzes.',
            'assessment.quiz.manage-all' => 'Manage quizzes across the assigned institution or campus scope.',
            'assessment.quiz.review' => 'Independently review quiz publication requests.',
            'assessment.quiz.publish' => 'Schedule, publish, close, or archive approved quizzes.',
            'assessment.policy.manage' => 'Configure institution assessment policies.',
            'assessment.attempt.start' => 'Start eligible quizzes for the signed-in learner.',
            'assessment.attempt.respond' => 'Save responses to the signed-in learner attempt.',
            'assessment.attempt.view-own' => 'View own attempts and released results only.',
            'assessment.grade.manage' => 'Manually grade open-ended quiz responses.',
            'assessment.results.release' => 'Release quiz results under the configured policy.',
            'assessment.results.view-own' => 'View only results released to the signed-in learner.',
            'assessment.regrade.request' => 'Request a review of a released quiz score.',
            'assessment.regrade.review' => 'Review and decide quiz regrade requests.',
            'assessment.analytics.view' => 'View privacy-conscious quiz analytics.',
            'assessment.audit.view' => 'View assessment audit history within assigned scope.',
            'assessment.accommodation.manage' => 'Authorize confidential learner assessment accommodations.',
        ];
        $now = now();
        foreach ($permissions as $key => $description) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['description' => $description, 'scope' => 'tenant', 'updated_at' => $now, 'created_at' => $now]);
        }
        $ids = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id', 'key');
        $mappings = [
            'tenant.owner' => array_keys($permissions),
            'tenant.admin' => array_keys($permissions),
            'academic.admin' => array_keys($permissions),
            'campus.admin' => ['assessment.bank.view', 'assessment.quiz.view', 'assessment.analytics.view', 'assessment.audit.view'],
            'teacher' => ['assessment.bank.view', 'assessment.bank.manage', 'assessment.bank.review', 'assessment.quiz.view', 'assessment.quiz.manage', 'assessment.quiz.review', 'assessment.quiz.publish', 'assessment.attempt.view-own', 'assessment.grade.manage', 'assessment.results.release', 'assessment.regrade.review', 'assessment.analytics.view', 'assessment.audit.view'],
            'student' => ['assessment.quiz.view', 'assessment.attempt.start', 'assessment.attempt.respond', 'assessment.attempt.view-own', 'assessment.results.view-own', 'assessment.regrade.request'],
            'sis.registrar' => ['assessment.quiz.view', 'assessment.analytics.view', 'assessment.audit.view'],
        ];
        foreach ($mappings as $roleKey => $keys) {
            $roleIds = DB::table('roles')->where('scope', 'tenant')->where('key', $roleKey)->pluck('id');
            foreach ($roleIds as $roleId) {
                foreach ($keys as $key) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $ids[$key], 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }
};
