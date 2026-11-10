<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_curriculum_framework_administrators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('framework_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('responsibility', 24);
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('academic_program_id')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('assignment_reason');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'framework_id', 'revoked_at'], 'curr_fw_admin_active_idx');
            $table->index(['tenant_id', 'user_id', 'revoked_at'], 'curr_fw_admin_user_idx');
            $table->foreign(['tenant_id', 'framework_id'], 'curr_fw_admin_frame_fk')->references(['tenant_id', 'id'])->on('academic_curriculum_frameworks')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'curr_fw_admin_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_program_id'], 'curr_fw_admin_program_fk')->references(['tenant_id', 'id'])->on('academic_programs')->restrictOnDelete();
        });

        Schema::create('academic_curriculum_checklist_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('framework_id')->nullable();
            $table->string('code', 48);
            $table->string('title', 160);
            $table->unsignedSmallInteger('version_number')->default(1);
            $table->string('status', 16)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code', 'version_number'], 'curr_checklist_code_ver_uq');
            $table->unique(['tenant_id', 'id'], 'curr_checklist_tenant_id_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'curr_checklist_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'framework_id'], 'curr_checklist_frame_fk')->references(['tenant_id', 'id'])->on('academic_curriculum_frameworks')->restrictOnDelete();
        });

        Schema::create('academic_curriculum_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('template_id');
            $table->string('category', 80);
            $table->string('prompt', 500);
            $table->boolean('is_required')->default(true);
            $table->boolean('requires_note')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'template_id', 'sort_order'], 'curr_check_item_order_uq');
            $table->foreign(['tenant_id', 'template_id'], 'curr_check_item_template_fk')->references(['tenant_id', 'id'])->on('academic_curriculum_checklist_templates')->restrictOnDelete();
        });

        Schema::create('academic_curriculum_governance_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('framework_id')->nullable();
            $table->unsignedBigInteger('competency_framework_id')->nullable();
            $table->unsignedBigInteger('checklist_template_id')->nullable();
            $table->unsignedSmallInteger('version_number');
            $table->string('scope', 16)->default('curriculum');
            $table->string('status', 16)->default('active')->index();
            $table->string('reviewer_eligibility', 24)->default('institution_wide');
            $table->string('approval_authority', 32)->default('curriculum_review');
            $table->string('publication_authority', 32)->default('curriculum_publish');
            $table->unsignedTinyInteger('minimum_reviewers')->default(1);
            $table->boolean('require_independent_reviewer')->default(true);
            $table->boolean('require_separate_publisher')->default(true);
            $table->boolean('require_changes_note')->default(true);
            $table->boolean('require_rejection_note')->default(true);
            $table->boolean('require_checklist_completion')->default(false);
            $table->boolean('allow_resubmission')->default(true);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'framework_id', 'version_number'], 'curr_gov_policy_ver_uq');
            $table->unique(['tenant_id', 'id'], 'curr_gov_policy_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'framework_id', 'status', 'effective_from'], 'curr_gov_policy_active_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'curr_gov_policy_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'framework_id'], 'curr_gov_policy_frame_fk')->references(['tenant_id', 'id'])->on('academic_curriculum_frameworks')->restrictOnDelete();
            $table->foreign(['tenant_id', 'competency_framework_id'], 'curr_gov_policy_comp_frame_fk')->references(['tenant_id', 'id'])->on('academic_competency_frameworks')->restrictOnDelete();
            $table->foreign(['tenant_id', 'checklist_template_id'], 'curr_gov_policy_check_fk')->references(['tenant_id', 'id'])->on('academic_curriculum_checklist_templates')->restrictOnDelete();
        });

        Schema::table('academic_curriculum_reviews', function (Blueprint $table): void {
            $table->json('checklist_snapshot')->nullable();
            $table->json('checklist_responses')->nullable();
            $table->unsignedBigInteger('policy_version_id')->nullable();
            $table->unsignedSmallInteger('review_round')->default(0);
            $table->foreign(['tenant_id', 'policy_version_id'], 'curr_review_policy_fk')->references(['tenant_id', 'id'])->on('academic_curriculum_governance_policies')->restrictOnDelete();
        });

        Schema::table('academic_curriculum_versions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('review_round')->default(0);
        });

        Schema::table('academic_competency_frameworks', function (Blueprint $table): void {
            $table->unsignedBigInteger('based_on_framework_id')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedSmallInteger('review_round')->default(0);
            $table->boolean('is_shared_template')->default(false)->index();
            $table->foreign(['tenant_id', 'based_on_framework_id'], 'comp_frame_base_fk')->references(['tenant_id', 'id'])->on('academic_competency_frameworks')->restrictOnDelete();
        });

        Schema::table('academic_competencies', function (Blueprint $table): void {
            $table->string('category', 80)->nullable();
            $table->json('mastery_levels')->nullable();
            $table->json('evidence_requirements')->nullable();
        });

        Schema::create('academic_competency_framework_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('framework_id');
            $table->foreignId('reviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('policy_version_id')->nullable();
            $table->string('decision', 24)->default('assigned');
            $table->unsignedSmallInteger('review_round')->default(0);
            $table->text('review_note')->nullable();
            $table->json('checklist_snapshot')->nullable();
            $table->json('checklist_responses')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'reviewer_user_id', 'decision'], 'comp_frame_review_user_idx');
            $table->foreign(['tenant_id', 'framework_id'], 'comp_frame_review_frame_fk')->references(['tenant_id', 'id'])->on('academic_competency_frameworks')->restrictOnDelete();
            $table->foreign(['tenant_id', 'policy_version_id'], 'comp_frame_review_policy_fk')->references(['tenant_id', 'id'])->on('academic_curriculum_governance_policies')->restrictOnDelete();
        });

        Schema::create('academic_program_competency_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_program_id');
            $table->unsignedBigInteger('competency_id');
            $table->string('expected_level', 80)->nullable();
            $table->json('evidence_requirements')->nullable();
            $table->boolean('is_required')->default(true);
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'academic_program_id', 'competency_id'], 'program_comp_req_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'program_comp_req_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_program_id'], 'program_comp_req_program_fk')->references(['tenant_id', 'id'])->on('academic_programs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'competency_id'], 'program_comp_req_comp_fk')->references(['tenant_id', 'id'])->on('academic_competencies')->restrictOnDelete();
        });

        Schema::create('academic_curriculum_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('code', 48);
            $table->unsignedSmallInteger('version_number')->default(1);
            $table->unsignedBigInteger('based_on_resource_id')->nullable();
            $table->unsignedBigInteger('curriculum_version_id')->nullable();
            $table->unsignedBigInteger('node_id')->nullable();
            $table->unsignedBigInteger('learning_outcome_id')->nullable();
            $table->unsignedBigInteger('academic_document_id')->nullable();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('resource_type', 32);
            $table->string('source_url', 2048)->nullable();
            $table->string('author_name', 180)->nullable();
            $table->string('publisher_name', 180)->nullable();
            $table->string('license_code', 80)->nullable();
            $table->text('attribution')->nullable();
            $table->string('language', 35)->nullable();
            $table->text('accessibility_notes')->nullable();
            $table->boolean('captions_available')->default(false);
            $table->boolean('transcript_available')->default(false);
            $table->json('alternate_formats')->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->boolean('is_required')->default(false);
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code', 'version_number'], 'curr_resource_code_ver_uq');
            $table->unique(['tenant_id', 'id'], 'curr_resource_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'resource_type'], 'curr_resource_search_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'curr_resource_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'based_on_resource_id'], 'curr_resource_base_fk')->references(['tenant_id', 'id'])->on('academic_curriculum_resources')->restrictOnDelete();
            $table->foreign(['tenant_id', 'curriculum_version_id'], 'curr_resource_version_fk')->references(['tenant_id', 'id'])->on('academic_curriculum_versions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'curriculum_version_id', 'node_id'], 'curr_resource_node_fk')->references(['tenant_id', 'curriculum_version_id', 'id'])->on('academic_curriculum_nodes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'learning_outcome_id'], 'curr_resource_outcome_fk')->references(['tenant_id', 'id'])->on('academic_learning_outcomes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_document_id'], 'curr_resource_document_fk')->references(['tenant_id', 'id'])->on('academic_documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_curriculum_resources');
        Schema::dropIfExists('academic_program_competency_requirements');
        Schema::dropIfExists('academic_competency_framework_reviews');
        Schema::table('academic_competencies', function (Blueprint $table): void {
            $table->dropColumn(['category', 'mastery_levels', 'evidence_requirements']);
        });
        Schema::table('academic_competency_frameworks', function (Blueprint $table): void {
            $table->dropForeign('comp_frame_base_fk');
            $table->dropColumn(['based_on_framework_id', 'owner_user_id', 'submitted_by', 'reviewer_user_id', 'published_by', 'submitted_at', 'published_at', 'lock_version', 'is_shared_template']);
        });
        Schema::table('academic_curriculum_reviews', function (Blueprint $table): void {
            $table->dropForeign('curr_review_policy_fk');
            $table->dropColumn(['checklist_snapshot', 'checklist_responses', 'policy_version_id', 'review_round']);
        });
        Schema::table('academic_curriculum_versions', function (Blueprint $table): void {
            $table->dropColumn('review_round');
        });
        Schema::dropIfExists('academic_curriculum_governance_policies');
        Schema::dropIfExists('academic_curriculum_checklist_items');
        Schema::dropIfExists('academic_curriculum_checklist_templates');
        Schema::dropIfExists('academic_curriculum_framework_administrators');
    }
};
