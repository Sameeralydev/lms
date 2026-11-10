<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_credit_policies', function (Blueprint $table): void {
            $table->unsignedBigInteger('academic_program_id')->nullable()->after('institution_id');
            $table->foreign(['tenant_id', 'academic_program_id'], 'acad_credit_program_fk')->references(['tenant_id', 'id'])->on('academic_programs')->restrictOnDelete();
            $table->index(['tenant_id', 'institution_id', 'academic_program_id', 'status'], 'acad_credit_program_status_idx');
        });

        Schema::create('academic_subjects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_level_id')->nullable();
            $table->unsignedBigInteger('academic_unit_id')->nullable();
            $table->string('code', 48);
            $table->string('name', 160);
            $table->string('category', 48)->default('general');
            $table->text('description')->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code'], 'acad_subject_code_uq');
            $table->unique(['tenant_id', 'id'], 'acad_subject_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'name'], 'acad_subject_status_name_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'acad_subject_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_level_id'], 'acad_subject_level_fk')->references(['tenant_id', 'id'])->on('academic_levels')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_unit_id'], 'acad_subject_unit_fk')->references(['tenant_id', 'id'])->on('academic_units')->restrictOnDelete();
        });

        Schema::create('academic_courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_unit_id')->nullable();
            $table->unsignedBigInteger('academic_subject_id')->nullable();
            $table->unsignedBigInteger('academic_level_id')->nullable();
            $table->string('code', 48);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code'], 'acad_course_code_uq');
            $table->unique(['tenant_id', 'id'], 'acad_course_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'title'], 'acad_course_status_title_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'acad_course_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_unit_id'], 'acad_course_unit_fk')->references(['tenant_id', 'id'])->on('academic_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_subject_id'], 'acad_course_subject_fk')->references(['tenant_id', 'id'])->on('academic_subjects')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_level_id'], 'acad_course_level_fk')->references(['tenant_id', 'id'])->on('academic_levels')->restrictOnDelete();
        });

        Schema::create('academic_course_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('academic_course_id');
            $table->unsignedBigInteger('credit_policy_id')->nullable();
            $table->unsignedSmallInteger('version_number');
            $table->string('title', 180);
            $table->boolean('credit_bearing')->default(false);
            $table->decimal('theory_credits', 9, 3)->default(0);
            $table->decimal('laboratory_credits', 9, 3)->default(0);
            $table->decimal('total_credits', 9, 3)->default(0);
            $table->decimal('contact_hours', 9, 3)->nullable();
            $table->decimal('duration_amount', 7, 2)->nullable();
            $table->string('duration_unit', 20)->nullable();
            $table->json('delivery_modes');
            $table->text('description')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->boolean('is_current')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'academic_course_id', 'version_number'], 'acad_course_version_uq');
            $table->unique(['tenant_id', 'id'], 'acad_course_version_tenant_id_uq');
            $table->index(['tenant_id', 'academic_course_id', 'status', 'effective_from'], 'acad_course_version_status_idx');
            $table->foreign(['tenant_id', 'academic_course_id'], 'acad_course_version_course_fk')->references(['tenant_id', 'id'])->on('academic_courses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'credit_policy_id'], 'acad_course_version_policy_fk')->references(['tenant_id', 'id'])->on('academic_credit_policies')->restrictOnDelete();
        });

        Schema::create('academic_policy_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('code', 48);
            $table->string('name', 160);
            $table->char('country', 2)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('institution_type', 40)->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code'], 'acad_policy_profile_code_uq');
            $table->unique(['tenant_id', 'id'], 'acad_policy_profile_tenant_id_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'acad_policy_profile_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('academic_policy_profile_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('academic_policy_profile_id');
            $table->unsignedSmallInteger('version_number');
            $table->string('academic_framework', 160)->nullable();
            $table->string('authority_name', 160)->nullable();
            $table->string('authority_reference', 255)->nullable();
            $table->string('calendar_model', 40)->nullable();
            $table->string('grading_terminology', 80)->nullable();
            $table->string('qualification_terminology', 80)->nullable();
            $table->boolean('credit_hours_applicable')->default(false);
            $table->json('compulsory_structure')->nullable();
            $table->json('document_requirements')->nullable();
            $table->json('configuration')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('locale', 16)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('review_status', 24)->default('draft')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'academic_policy_profile_id', 'version_number'], 'acad_policy_profile_version_uq');
            $table->unique(['tenant_id', 'id'], 'acad_policy_version_tenant_id_uq');
            $table->foreign(['tenant_id', 'academic_policy_profile_id'], 'acad_policy_version_profile_fk')->references(['tenant_id', 'id'])->on('academic_policy_profiles')->restrictOnDelete();
        });

        Schema::create('academic_policy_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('policy_profile_version_id');
            $table->string('source_title', 200);
            $table->string('source_authority', 160);
            $table->string('source_url', 500)->nullable();
            $table->date('source_published_on')->nullable();
            $table->date('retrieved_on');
            $table->string('private_file_path', 500)->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->text('mapped_claim')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'acad_policy_evidence_tenant_id_uq');
            $table->unique(['tenant_id', 'policy_profile_version_id', 'id'], 'acad_policy_evidence_version_id_uq');
            $table->foreign(['tenant_id', 'policy_profile_version_id'], 'acad_policy_evidence_version_fk')->references(['tenant_id', 'id'])->on('academic_policy_profile_versions')->cascadeOnDelete();
        });

        Schema::create('academic_policy_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('policy_profile_version_id');
            $table->unsignedBigInteger('evidence_id')->nullable();
            $table->string('requirement_reference', 100)->nullable();
            $table->string('policy_area', 64);
            $table->text('requirement_summary');
            $table->string('software_capability', 160)->nullable();
            $table->string('mapping_status', 24)->default('unmapped')->index();
            $table->text('gap_note')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'policy_profile_version_id'], 'acad_policy_mapping_version_fk')->references(['tenant_id', 'id'])->on('academic_policy_profile_versions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'policy_profile_version_id', 'evidence_id'], 'acad_policy_mapping_evidence_fk')->references(['tenant_id', 'policy_profile_version_id', 'id'])->on('academic_policy_evidence')->restrictOnDelete();
        });

        Schema::create('academic_policy_gaps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('policy_profile_version_id');
            $table->string('gap_key', 48);
            $table->string('severity', 24)->default('informational');
            $table->text('description');
            $table->string('status', 24)->default('open')->index();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'policy_profile_version_id', 'gap_key'], 'acad_policy_gap_key_uq');
            $table->foreign(['tenant_id', 'policy_profile_version_id'], 'acad_policy_gap_version_fk')->references(['tenant_id', 'id'])->on('academic_policy_profile_versions')->cascadeOnDelete();
        });

        Schema::create('academic_learning_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_level_id')->nullable();
            $table->unsignedBigInteger('parent_outcome_id')->nullable();
            $table->string('public_id', 64)->unique();
            $table->string('code', 64);
            $table->unsignedSmallInteger('version_number')->default(1);
            $table->string('name', 180);
            $table->text('description');
            $table->string('competency_category', 80)->nullable();
            $table->string('framework_reference', 180)->nullable();
            $table->string('framework_version', 80)->nullable();
            $table->string('source_organization', 160)->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code', 'version_number'], 'acad_outcome_code_version_uq');
            $table->unique(['tenant_id', 'id'], 'acad_outcome_tenant_id_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'acad_outcome_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_level_id'], 'acad_outcome_level_fk')->references(['tenant_id', 'id'])->on('academic_levels')->restrictOnDelete();
            $table->foreign(['tenant_id', 'parent_outcome_id'], 'acad_outcome_parent_fk')->references(['tenant_id', 'id'])->on('academic_learning_outcomes')->restrictOnDelete();
        });

        Schema::create('academic_program_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('academic_program_id');
            $table->unsignedBigInteger('learning_outcome_id');
            $table->timestamps();
            $table->unique(['tenant_id', 'academic_program_id', 'learning_outcome_id'], 'acad_program_outcome_uq');
            $table->foreign(['tenant_id', 'academic_program_id'], 'acad_program_outcome_prog_fk')->references(['tenant_id', 'id'])->on('academic_programs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'learning_outcome_id'], 'acad_program_outcome_outcome_fk')->references(['tenant_id', 'id'])->on('academic_learning_outcomes')->restrictOnDelete();
        });

        Schema::create('academic_course_version_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('academic_course_version_id');
            $table->unsignedBigInteger('learning_outcome_id');
            $table->timestamps();
            $table->unique(['tenant_id', 'academic_course_version_id', 'learning_outcome_id'], 'acad_course_version_outcome_uq');
            $table->foreign(['tenant_id', 'academic_course_version_id'], 'acad_course_version_outcome_course_fk')->references(['tenant_id', 'id'])->on('academic_course_versions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'learning_outcome_id'], 'acad_course_version_outcome_outcome_fk')->references(['tenant_id', 'id'])->on('academic_learning_outcomes')->restrictOnDelete();
        });

        Schema::table('academic_configurations', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_policy_profile_version_id')->nullable();
            $table->foreign(['tenant_id', 'active_policy_profile_version_id'], 'acad_config_policy_version_fk')->references(['tenant_id', 'id'])->on('academic_policy_profile_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('academic_configurations', function (Blueprint $table): void {
            $table->dropForeign('acad_config_policy_version_fk');
            $table->dropColumn('active_policy_profile_version_id');
        });
        Schema::dropIfExists('academic_course_version_outcomes');
        Schema::dropIfExists('academic_program_outcomes');
        Schema::dropIfExists('academic_learning_outcomes');
        Schema::dropIfExists('academic_policy_gaps');
        Schema::dropIfExists('academic_policy_mappings');
        Schema::dropIfExists('academic_policy_evidence');
        Schema::dropIfExists('academic_policy_profile_versions');
        Schema::dropIfExists('academic_policy_profiles');
        Schema::dropIfExists('academic_course_versions');
        Schema::dropIfExists('academic_courses');
        Schema::dropIfExists('academic_subjects');
        Schema::table('academic_credit_policies', function (Blueprint $table): void {
            $table->dropForeign('acad_credit_program_fk');
            $table->dropIndex('acad_credit_program_status_idx');
            $table->dropColumn('academic_program_id');
        });
    }
};
