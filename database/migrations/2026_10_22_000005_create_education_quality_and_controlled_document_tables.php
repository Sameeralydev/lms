<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_quality_objectives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_unit_id')->nullable();
            $table->string('code', 48);
            $table->string('name', 180);
            $table->text('description');
            $table->string('quality_area', 80)->nullable();
            $table->text('metric_definition');
            $table->string('metric_unit', 48)->nullable();
            $table->decimal('target_value', 14, 4)->nullable();
            $table->unsignedSmallInteger('review_every_months')->default(12);
            $table->date('next_review_on')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code'], 'edu_quality_objective_code_uq');
            $table->unique(['tenant_id', 'id'], 'edu_quality_objective_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'next_review_on'], 'edu_quality_objective_due_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'edu_quality_objective_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_unit_id'], 'edu_quality_objective_unit_fk')->references(['tenant_id', 'id'])->on('academic_units')->restrictOnDelete();
        });

        Schema::create('education_quality_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quality_objective_id');
            $table->unsignedSmallInteger('review_number');
            $table->date('due_on')->nullable();
            $table->decimal('measured_value', 14, 4)->nullable();
            $table->text('summary')->nullable();
            $table->string('decision', 32)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'quality_objective_id', 'review_number'], 'edu_quality_review_number_uq');
            $table->unique(['tenant_id', 'id'], 'edu_quality_review_tenant_id_uq');
            $table->unique(['tenant_id', 'quality_objective_id', 'id'], 'edu_quality_review_obj_id_uq');
            $table->index(['tenant_id', 'decision', 'due_on'], 'edu_quality_review_due_idx');
            $table->foreign(['tenant_id', 'quality_objective_id'], 'edu_quality_review_objective_fk')->references(['tenant_id', 'id'])->on('education_quality_objectives')->restrictOnDelete();
        });

        Schema::create('education_quality_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quality_objective_id');
            $table->unsignedBigInteger('quality_review_id')->nullable();
            $table->string('title', 180);
            $table->string('private_file_path', 500)->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->string('reference_url', 500)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'quality_objective_id'], 'edu_quality_evidence_obj_idx');
            $table->foreign(['tenant_id', 'quality_objective_id'], 'edu_quality_evidence_objective_fk')->references(['tenant_id', 'id'])->on('education_quality_objectives')->restrictOnDelete();
            $table->foreign(['tenant_id', 'quality_objective_id', 'quality_review_id'], 'edu_quality_evidence_review_fk')->references(['tenant_id', 'quality_objective_id', 'id'])->on('education_quality_reviews')->restrictOnDelete();
        });

        Schema::create('education_corrective_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quality_objective_id');
            $table->unsignedBigInteger('quality_review_id')->nullable();
            $table->string('code', 48);
            $table->string('title', 180);
            $table->text('description');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->text('resolution_note')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'quality_objective_id', 'code'], 'edu_corrective_action_code_uq');
            $table->foreign(['tenant_id', 'quality_objective_id'], 'edu_corrective_action_objective_fk')->references(['tenant_id', 'id'])->on('education_quality_objectives')->restrictOnDelete();
            $table->foreign(['tenant_id', 'quality_objective_id', 'quality_review_id'], 'edu_corrective_action_review_fk')->references(['tenant_id', 'quality_objective_id', 'id'])->on('education_quality_reviews')->restrictOnDelete();
        });

        Schema::create('academic_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('document_type', 48);
            $table->string('code', 48);
            $table->unsignedSmallInteger('version_number');
            $table->string('title', 200);
            $table->text('summary')->nullable();
            $table->string('private_file_path', 500);
            $table->char('file_sha256', 64);
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code', 'version_number'], 'acad_document_version_uq');
            $table->unique(['tenant_id', 'id'], 'acad_document_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'document_type'], 'acad_document_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'acad_document_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('academic_document_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('academic_document_id');
            $table->string('decision', 32);
            $table->text('comment')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'academic_document_id', 'occurred_at'], 'acad_document_approval_hist_idx');
            $table->foreign(['tenant_id', 'academic_document_id'], 'acad_document_approval_doc_fk')->references(['tenant_id', 'id'])->on('academic_documents')->restrictOnDelete();
        });

        $permissions = [
            'academic.view' => 'View academic configuration and dashboard.',
            'academic.settings.manage' => 'Update operating model and institution academic terminology.',
            'academic.calendar.manage' => 'Manage academic years and lifecycle.',
            'academic.periods.manage' => 'Manage teaching periods and dates.',
            'academic.structure.manage' => 'Manage academic units, levels, and qualifications.',
            'academic.programs.manage' => 'Manage academic program definitions.',
            'academic.classes.manage' => 'Manage grade/class, section, and cohort structures.',
            'academic.catalog.manage' => 'Manage subject and course definitions.',
            'academic.credit.manage' => 'Manage versioned credit-hour policies.',
            'academic.regional.manage' => 'Manage opt-in jurisdiction policy profiles and evidence.',
            'academic.outcomes.manage' => 'Manage learning-outcome references.',
            'academic.quality.manage' => 'Manage educational quality objectives, reviews, and corrective actions.',
            'academic.documents.manage' => 'Manage controlled academic documents.',
            'academic.policy.approve' => 'Approve academic policy, published course, and quality review versions.',
            'academic.audit.view' => 'View academic audit history.',
        ];

        foreach ($permissions as $key => $description) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                ['scope' => 'tenant', 'description' => $description, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        $roles = DB::table('roles')->whereNotNull('tenant_id')->whereIn('key', ['tenant.owner', 'tenant.admin', 'academic.admin', 'auditor'])->get(['id', 'tenant_id', 'key']);
        foreach ($roles as $role) {
            $keys = match ($role->key) {
                'tenant.owner', 'tenant.admin', 'academic.admin' => array_keys($permissions),
                'auditor' => ['academic.view', 'academic.audit.view'],
                default => [],
            };
            $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
            foreach ($ids as $permissionId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $role->id, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->where('key', 'like', 'academic.%')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->where('key', 'like', 'academic.%')->delete();
        Schema::dropIfExists('academic_document_approvals');
        Schema::dropIfExists('academic_documents');
        Schema::dropIfExists('education_corrective_actions');
        Schema::dropIfExists('education_quality_evidence');
        Schema::dropIfExists('education_quality_reviews');
        Schema::dropIfExists('education_quality_objectives');
    }
};
