<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('session_id');
            $table->string('component_type', 16);
            $table->string('code', 48);
            $table->string('title', 180);
            $table->text('instructions')->nullable();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->dateTime('starts_at_utc')->nullable();
            $table->dateTime('ends_at_utc')->nullable();
            $table->decimal('maximum_marks', 9, 2);
            $table->string('status', 16)->default('draft');
            $table->boolean('second_marking_required')->default(false);
            $table->decimal('moderation_difference_threshold', 6, 2)->nullable();
            $table->boolean('blind_second_marking')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'exam_component_tenant_id_uq');
            $table->unique(['tenant_id', 'session_id', 'code'], 'exam_component_session_code_uq');
            $table->index(['tenant_id', 'component_type', 'status', 'starts_at_utc'], 'exam_component_queue_idx');
            $table->foreign(['tenant_id', 'session_id'], 'exam_component_session_fk')->references(['tenant_id', 'id'])->on('exam_sessions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'room_id'], 'exam_component_room_fk')->references(['tenant_id', 'id'])->on('academic_rooms')->restrictOnDelete();
        });

        Schema::create('exam_component_rubrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('component_id');
            $table->string('criterion_key', 48);
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->decimal('maximum_marks', 9, 2);
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->unique(['tenant_id', 'component_id', 'criterion_key'], 'exam_rubric_key_uq');
            $table->unique(['tenant_id', 'component_id', 'position'], 'exam_rubric_position_uq');
            $table->foreign(['tenant_id', 'component_id'], 'exam_rubric_component_fk')->references(['tenant_id', 'id'])->on('exam_components')->cascadeOnDelete();
        });

        Schema::create('exam_component_examiners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('component_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('examiner_role', 16);
            $table->string('status', 16)->default('assigned');
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('assigned_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'component_id', 'user_id', 'examiner_role'], 'exam_component_examiner_uq');
            $table->index(['tenant_id', 'user_id', 'status', 'examiner_role'], 'exam_component_examiner_user_idx');
            $table->foreign(['tenant_id', 'component_id'], 'exam_component_examiner_component_fk')->references(['tenant_id', 'id'])->on('exam_components')->cascadeOnDelete();
        });

        Schema::create('exam_component_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('component_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedTinyInteger('marking_round')->default(1);
            $table->foreignId('examiner_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('assigned');
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('assigned_at');
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'component_id', 'candidate_id', 'marking_round'], 'exam_allocation_round_uq');
            $table->unique(['tenant_id', 'id'], 'exam_allocation_tenant_id_uq');
            $table->index(['tenant_id', 'examiner_id', 'status', 'assigned_at'], 'exam_allocation_queue_idx');
            $table->foreign(['tenant_id', 'component_id'], 'exam_allocation_component_fk')->references(['tenant_id', 'id'])->on('exam_components')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'candidate_id'], 'exam_allocation_candidate_fk')->references(['tenant_id', 'id'])->on('exam_candidates')->restrictOnDelete();
        });

        Schema::create('exam_component_allocation_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('allocation_id');
            $table->foreignId('previous_examiner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('new_examiner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->dateTime('created_at');
            $table->foreign(['tenant_id', 'allocation_id'], 'exam_allocation_history_fk')->references(['tenant_id', 'id'])->on('exam_component_allocations')->restrictOnDelete();
            $table->index(['tenant_id', 'allocation_id', 'created_at'], 'exam_allocation_history_idx');
        });

        Schema::create('exam_component_markings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('allocation_id');
            $table->unsignedInteger('revision');
            $table->json('rubric_marks');
            $table->decimal('total_marks', 9, 2);
            $table->decimal('maximum_marks', 9, 2);
            $table->text('feedback')->nullable();
            $table->text('private_notes')->nullable();
            $table->string('status', 16)->default('submitted');
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'allocation_id', 'revision'], 'exam_marking_revision_uq');
            $table->unique(['tenant_id', 'id'], 'exam_marking_tenant_id_uq');
            $table->foreign(['tenant_id', 'allocation_id'], 'exam_marking_allocation_fk')->references(['tenant_id', 'id'])->on('exam_component_allocations')->restrictOnDelete();
        });

        Schema::create('exam_component_mark_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('allocation_id');
            $table->json('rubric_marks');
            $table->text('feedback')->nullable();
            $table->text('private_notes')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'allocation_id'], 'exam_mark_draft_allocation_uq');
            $table->foreign(['tenant_id', 'allocation_id'], 'exam_mark_draft_allocation_fk')->references(['tenant_id', 'id'])->on('exam_component_allocations')->cascadeOnDelete();
        });

        Schema::create('exam_component_moderation_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('component_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedInteger('revision');
            $table->unsignedBigInteger('first_marking_id');
            $table->unsignedBigInteger('second_marking_id');
            $table->decimal('first_marks', 9, 2);
            $table->decimal('second_marks', 9, 2);
            $table->decimal('final_marks', 9, 2);
            $table->string('decision', 20);
            $table->text('reason');
            $table->foreignId('moderated_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at');
            $table->unique(['tenant_id', 'component_id', 'candidate_id', 'revision'], 'exam_moderation_revision_uq');
            $table->unique(['tenant_id', 'id'], 'exam_moderation_tenant_id_uq');
            $table->foreign(['tenant_id', 'component_id'], 'exam_moderation_component_fk')->references(['tenant_id', 'id'])->on('exam_components')->restrictOnDelete();
            $table->foreign(['tenant_id', 'candidate_id'], 'exam_moderation_candidate_fk')->references(['tenant_id', 'id'])->on('exam_candidates')->restrictOnDelete();
            $table->foreign(['tenant_id', 'first_marking_id'], 'exam_moderation_first_fk')->references(['tenant_id', 'id'])->on('exam_component_markings')->restrictOnDelete();
            $table->foreign(['tenant_id', 'second_marking_id'], 'exam_moderation_second_fk')->references(['tenant_id', 'id'])->on('exam_component_markings')->restrictOnDelete();
            $table->index(['tenant_id', 'component_id', 'decided_at'], 'exam_moderation_component_idx');
        });

        Schema::create('exam_component_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('component_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedInteger('version');
            $table->decimal('final_marks', 9, 2);
            $table->decimal('maximum_marks', 9, 2);
            $table->string('result_status', 20);
            $table->string('policy_version', 32);
            $table->unsignedBigInteger('moderation_decision_id')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'component_id', 'candidate_id', 'version'], 'exam_component_result_version_uq');
            $table->foreign(['tenant_id', 'component_id'], 'exam_component_result_component_fk')->references(['tenant_id', 'id'])->on('exam_components')->restrictOnDelete();
            $table->foreign(['tenant_id', 'candidate_id'], 'exam_component_result_candidate_fk')->references(['tenant_id', 'id'])->on('exam_candidates')->restrictOnDelete();
            $table->foreign(['tenant_id', 'moderation_decision_id'], 'exam_component_result_moderation_fk')->references(['tenant_id', 'id'])->on('exam_component_moderation_decisions')->restrictOnDelete();
        });

        Schema::create('exam_answer_scripts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('component_id')->nullable();
            $table->unsignedBigInteger('attempt_id')->nullable();
            $table->unsignedSmallInteger('revision')->default(1);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('private_path', 512)->unique();
            $table->string('scan_status', 16)->default('quarantined');
            $table->string('scanner', 80)->nullable();
            $table->string('signature_version', 120)->nullable();
            $table->string('result_code', 64)->nullable();
            $table->dateTime('scanned_at')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('retention_until')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'session_id', 'candidate_id', 'component_id', 'revision'], 'exam_script_revision_uq');
            $table->index(['tenant_id', 'scan_status', 'created_at'], 'exam_script_scan_idx');
            $table->foreign(['tenant_id', 'session_id'], 'exam_script_session_fk')->references(['tenant_id', 'id'])->on('exam_sessions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'candidate_id'], 'exam_script_candidate_fk')->references(['tenant_id', 'id'])->on('exam_candidates')->restrictOnDelete();
            $table->foreign(['tenant_id', 'component_id'], 'exam_script_component_fk')->references(['tenant_id', 'id'])->on('exam_components')->restrictOnDelete();
            $table->foreign(['tenant_id', 'attempt_id'], 'exam_script_attempt_fk')->references(['tenant_id', 'id'])->on('quiz_attempts')->restrictOnDelete();
        });

        $this->seedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_answer_scripts');
        Schema::dropIfExists('exam_component_results');
        Schema::dropIfExists('exam_component_moderation_decisions');
        Schema::dropIfExists('exam_component_mark_drafts');
        Schema::dropIfExists('exam_component_markings');
        Schema::dropIfExists('exam_component_allocation_history');
        Schema::dropIfExists('exam_component_allocations');
        Schema::dropIfExists('exam_component_examiners');
        Schema::dropIfExists('exam_component_rubrics');
        Schema::dropIfExists('exam_components');
    }

    private function seedPermissions(): void
    {
        $permissions = [
            'exams.components.manage' => 'Create and schedule practical and oral examination components.',
            'exams.mark' => 'View and mark scripts assigned to the current examiner.',
            'exams.mark.allocate' => 'Allocate component marking and reassign unsubmitted work.',
            'exams.moderate' => 'Review independent markings and submit moderation decisions.',
            'exams.scripts.upload' => 'Upload private examination scripts into scanner quarantine.',
            'exams.scripts.view' => 'View scripts within an assigned component marking scope.',
            'exams.student.view-own' => 'View own examination schedule and completion status without unreleased marks.',
            'exams.results.approve' => 'Approve singly marked examination component results.',
        ];
        $now = now();
        foreach ($permissions as $key => $description) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['description' => $description, 'scope' => 'tenant', 'created_at' => $now, 'updated_at' => $now]);
        }
        $ids = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id', 'key');
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $templates = [
                'tenant.owner' => array_keys($permissions), 'tenant.admin' => array_keys($permissions), 'academic.admin' => array_keys($permissions),
                'exam.controller' => ['exams.view', 'exams.manage', 'exams.candidates.manage', 'exams.components.manage', 'exams.mark.allocate', 'exams.scripts.upload', 'exams.scripts.view', 'exams.audit.view'],
                'examiner' => ['exams.view', 'exams.mark', 'exams.scripts.view'],
                'exam.moderator' => ['exams.view', 'exams.moderate'],
                'teacher' => ['exams.view', 'exams.mark', 'exams.scripts.view'],
                'student' => ['exams.student.view-own'],
            ];
            foreach ($templates as $roleKey => $permissionKeys) {
                DB::table('roles')->insertOrIgnore(['tenant_id' => $tenantId, 'key' => $roleKey, 'name' => ucfirst(str_replace('.', ' ', $roleKey)), 'scope' => 'tenant', 'description' => null, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
                $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('key', $roleKey)->value('id');
                foreach ($permissionKeys as $permissionKey) {
                    $permissionId = $ids[$permissionKey] ?? DB::table('permissions')->where('key', $permissionKey)->value('id');
                    if ($roleId && $permissionId) {
                        DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }
        }
    }
};
