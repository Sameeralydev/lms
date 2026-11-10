<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_result_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('session_id');
            $table->unsignedInteger('version');
            $table->decimal('written_weight', 5, 2)->default(0);
            $table->decimal('pass_percent', 5, 2)->nullable();
            $table->unsignedSmallInteger('recheck_window_days')->default(14);
            $table->dateTime('effective_at');
            $table->foreignId('configured_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'session_id', 'version'], 'exam_result_policy_version_uq');
            $table->unique(['tenant_id', 'id'], 'exam_result_policy_tenant_id_uq');
            $table->foreign(['tenant_id', 'session_id'], 'exam_result_policy_session_fk')->references(['tenant_id', 'id'])->on('exam_sessions')->restrictOnDelete();
            $table->index(['tenant_id', 'session_id', 'effective_at'], 'exam_result_policy_effective_idx');
        });

        Schema::create('exam_result_policy_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('policy_id');
            $table->unsignedBigInteger('component_id');
            $table->decimal('weight_percent', 5, 2);
            $table->boolean('required')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'policy_id', 'component_id'], 'exam_result_policy_component_uq');
            $table->foreign(['tenant_id', 'policy_id'], 'exam_result_policy_component_policy_fk')->references(['tenant_id', 'id'])->on('exam_result_policies')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'component_id'], 'exam_result_policy_component_component_fk')->references(['tenant_id', 'id'])->on('exam_components')->restrictOnDelete();
        });

        Schema::create('exam_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('policy_id');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 24)->default('marking_pending');
            $table->string('outcome', 16)->default('pending');
            $table->decimal('total_marks', 9, 2)->nullable();
            $table->decimal('maximum_marks', 9, 2)->default(100);
            $table->decimal('percentage', 5, 2)->nullable();
            $table->json('source_snapshot');
            $table->char('source_hash', 64);
            $table->string('policy_version', 40);
            $table->foreignId('compiled_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('released_at')->nullable();
            $table->dateTime('recheck_until')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'session_id', 'candidate_id'], 'exam_result_candidate_uq');
            $table->unique(['tenant_id', 'id'], 'exam_result_tenant_id_uq');
            $table->foreign(['tenant_id', 'session_id'], 'exam_result_session_fk')->references(['tenant_id', 'id'])->on('exam_sessions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'candidate_id'], 'exam_result_candidate_fk')->references(['tenant_id', 'id'])->on('exam_candidates')->restrictOnDelete();
            $table->foreign(['tenant_id', 'policy_id'], 'exam_result_policy_fk')->references(['tenant_id', 'id'])->on('exam_result_policies')->restrictOnDelete();
            $table->index(['tenant_id', 'session_id', 'status', 'outcome'], 'exam_result_status_idx');
            $table->index(['tenant_id', 'candidate_id', 'released_at'], 'exam_result_student_idx');
        });

        Schema::create('exam_result_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('result_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('change_reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at');
            $table->unique(['tenant_id', 'result_id', 'version'], 'exam_result_version_uq');
            $table->foreign(['tenant_id', 'result_id'], 'exam_result_version_result_fk')->references(['tenant_id', 'id'])->on('exam_results')->cascadeOnDelete();
        });

        Schema::create('exam_result_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('result_id');
            $table->unsignedInteger('result_version');
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 16);
            $table->json('checklist');
            $table->text('reason')->nullable();
            $table->dateTime('decided_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'result_id', 'result_version'], 'exam_result_approval_version_uq');
            $table->foreign(['tenant_id', 'result_id'], 'exam_result_approval_result_fk')->references(['tenant_id', 'id'])->on('exam_results')->cascadeOnDelete();
        });

        Schema::create('exam_recheck_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('result_id');
            $table->unsignedInteger('result_version');
            $table->foreignId('student_user_id')->constrained('users')->restrictOnDelete();
            $table->string('request_type', 24);
            $table->text('reason');
            $table->string('status', 24)->default('submitted');
            $table->foreignId('assigned_reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('review_findings')->nullable();
            $table->decimal('proposed_marks', 9, 2)->nullable();
            $table->string('decision', 16)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->unsignedBigInteger('final_result_version')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'exam_recheck_tenant_id_uq');
            $table->foreign(['tenant_id', 'result_id'], 'exam_recheck_result_fk')->references(['tenant_id', 'id'])->on('exam_results')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'created_at'], 'exam_recheck_queue_idx');
        });

        Schema::create('exam_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('candidate_id')->nullable();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->string('incident_type', 32);
            $table->string('severity', 16)->default('standard');
            $table->text('description');
            $table->string('status', 20)->default('reported');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('review_notes')->nullable();
            $table->string('decision', 24)->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'exam_incident_tenant_id_uq');
            $table->foreign(['tenant_id', 'session_id'], 'exam_incident_session_fk')->references(['tenant_id', 'id'])->on('exam_sessions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'candidate_id'], 'exam_incident_candidate_fk')->references(['tenant_id', 'id'])->on('exam_candidates')->restrictOnDelete();
            $table->index(['tenant_id', 'session_id', 'status', 'created_at'], 'exam_incident_queue_idx');
        });

        $permissions = [
            'exams.results.compile' => 'Compile server-calculated examination result snapshots.',
            'exams.results.release' => 'Release independently approved examination results.',
            'exams.recheck.request' => 'Request a recheck of an own released examination result.',
            'exams.recheck.review' => 'Review and decide assigned examination recheck requests.',
            'exams.policy.manage' => 'Configure examination result weights and recheck windows.',
            'exams.incidents.report' => 'Report an examination incident in an authorized session.',
            'exams.incidents.review' => 'Review reported examination incidents and record a decision.',
            'exams.analytics.view' => 'View scoped examination result and operations analytics.',
        ];
        $now = now();
        foreach ($permissions as $key => $description) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['description' => $description, 'scope' => 'tenant', 'created_at' => $now, 'updated_at' => $now]);
        }
        $ids = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id', 'key');
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $roles = DB::table('roles')->where('tenant_id', $tenantId)->pluck('id', 'key');
            $roleGrants = [
                'tenant.owner' => array_keys($permissions),
                'tenant.admin' => array_keys($permissions),
                'academic.admin' => ['exams.results.compile', 'exams.results.approve', 'exams.results.release', 'exams.recheck.review', 'exams.policy.manage', 'exams.incidents.review', 'exams.analytics.view'],
                'exam.controller' => ['exams.results.compile', 'exams.results.approve', 'exams.results.release', 'exams.recheck.review', 'exams.policy.manage', 'exams.incidents.review', 'exams.analytics.view'],
                'exam.moderator' => ['exams.recheck.review'],
                'exam.invigilator' => ['exams.incidents.report'],
                'examiner' => ['exams.incidents.report'],
                'student' => ['exams.recheck.request', 'exams.incidents.report'],
            ];
            foreach ($roleGrants as $roleKey => $grants) {
                if (! isset($roles[$roleKey])) {
                    continue;
                }
                foreach ($grants as $grant) {
                    if (isset($ids[$grant])) {
                        DB::table('permission_role')->insertOrIgnore(['role_id' => $roles[$roleKey], 'permission_id' => $ids[$grant], 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_incidents');
        Schema::dropIfExists('exam_recheck_requests');
        Schema::dropIfExists('exam_result_approvals');
        Schema::dropIfExists('exam_result_versions');
        Schema::dropIfExists('exam_results');
        Schema::dropIfExists('exam_result_policy_components');
        Schema::dropIfExists('exam_result_policies');
    }
};
