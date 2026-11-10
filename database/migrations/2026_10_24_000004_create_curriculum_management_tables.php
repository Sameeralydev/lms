<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_curriculum_frameworks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('code', 48);
            $table->string('title', 180);
            $table->string('education_stage', 80)->nullable();
            $table->text('description')->nullable();
            $table->json('terminology')->nullable();
            $table->json('required_learning_areas')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code'], 'curr_framework_code_uq');
            $table->unique(['tenant_id', 'id'], 'curr_framework_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'title'], 'curr_framework_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'curr_framework_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('academic_curriculum_framework_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('framework_id');
            $table->unsignedBigInteger('learning_outcome_id');
            $table->boolean('required')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'framework_id', 'learning_outcome_id'], 'curr_framework_outcome_uq');
            $table->foreign(['tenant_id', 'framework_id'], 'curr_framework_outcome_frame_fk')->references(['tenant_id', 'id'])->on('academic_curriculum_frameworks')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'learning_outcome_id'], 'curr_framework_outcome_out_fk')->references(['tenant_id', 'id'])->on('academic_learning_outcomes')->restrictOnDelete();
        });

        Schema::create('academic_curricula', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_program_id');
            $table->unsignedBigInteger('framework_id')->nullable();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->string('code', 48);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->string('sharing_status', 16)->default('private')->index();
            $table->unsignedSmallInteger('current_version_number')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code'], 'curriculum_code_uq');
            $table->unique(['tenant_id', 'id'], 'curriculum_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'title'], 'curriculum_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'curriculum_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_program_id'], 'curriculum_program_fk')->references(['tenant_id', 'id'])->on('academic_programs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'framework_id'], 'curriculum_framework_fk')->references(['tenant_id', 'id'])->on('academic_curriculum_frameworks')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_year_id'], 'curriculum_year_fk')->references(['tenant_id', 'id'])->on('academic_years')->restrictOnDelete();
        });

        Schema::create('academic_curriculum_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('curriculum_id');
            $table->unsignedBigInteger('based_on_version_id')->nullable();
            $table->unsignedSmallInteger('version_number');
            $table->string('status', 24)->default('draft')->index();
            $table->string('title', 180);
            $table->text('revision_note')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'curriculum_id', 'version_number'], 'curriculum_version_number_uq');
            $table->unique(['tenant_id', 'id'], 'curriculum_version_tenant_id_uq');
            $table->unique(['tenant_id', 'curriculum_id', 'id'], 'curriculum_version_curr_id_uq');
            $table->foreign(['tenant_id', 'curriculum_id'], 'curriculum_version_curr_fk')->references(['tenant_id', 'id'])->on('academic_curricula')->restrictOnDelete();
            $table->foreign(['tenant_id', 'curriculum_id', 'based_on_version_id'], 'curriculum_version_base_fk')->references(['tenant_id', 'curriculum_id', 'id'])->on('academic_curriculum_versions')->restrictOnDelete();
        });

        Schema::create('academic_curriculum_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('curriculum_version_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('academic_course_id')->nullable();
            $table->unsignedBigInteger('academic_course_version_id')->nullable();
            $table->unsignedBigInteger('academic_period_id')->nullable();
            $table->string('node_type', 24);
            $table->string('code', 64)->nullable();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->string('workload_unit', 24)->default('minutes');
            $table->string('instructional_strategy', 120)->nullable();
            $table->text('activity_specification')->nullable();
            $table->text('assessment_blueprint')->nullable();
            $table->date('planned_starts_on')->nullable();
            $table->date('planned_ends_on')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'curriculum_version_id', 'id'], 'curriculum_node_version_id_uq');
            $table->unique(['tenant_id', 'curriculum_version_id', 'parent_id', 'sort_order'], 'curriculum_node_order_uq');
            $table->index(['tenant_id', 'curriculum_version_id', 'node_type', 'sort_order'], 'curriculum_node_type_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'curriculum_node_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'curriculum_version_id'], 'curriculum_node_ver_fk')->references(['tenant_id', 'id'])->on('academic_curriculum_versions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'curriculum_version_id', 'parent_id'], 'curriculum_node_parent_fk')->references(['tenant_id', 'curriculum_version_id', 'id'])->on('academic_curriculum_nodes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_course_id'], 'curriculum_node_course_fk')->references(['tenant_id', 'id'])->on('academic_courses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_course_version_id'], 'curriculum_node_course_ver_fk')->references(['tenant_id', 'id'])->on('academic_course_versions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_period_id'], 'curriculum_node_period_fk')->references(['tenant_id', 'id'])->on('academic_periods')->restrictOnDelete();
        });

        Schema::create('academic_curriculum_node_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('curriculum_version_id');
            $table->unsignedBigInteger('node_id');
            $table->unsignedBigInteger('learning_outcome_id');
            $table->string('mapping_strength', 16)->default('introduced');
            $table->timestamps();
            $table->unique(['tenant_id', 'node_id', 'learning_outcome_id'], 'curriculum_node_outcome_uq');
            $table->foreign(['tenant_id', 'curriculum_version_id', 'node_id'], 'curriculum_node_outcome_node_fk')->references(['tenant_id', 'curriculum_version_id', 'id'])->on('academic_curriculum_nodes')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'learning_outcome_id'], 'curriculum_node_outcome_out_fk')->references(['tenant_id', 'id'])->on('academic_learning_outcomes')->restrictOnDelete();
        });

        Schema::create('academic_curriculum_prerequisites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('curriculum_version_id');
            $table->unsignedBigInteger('node_id');
            $table->unsignedBigInteger('prerequisite_node_id');
            $table->string('dependency_type', 16)->default('required');
            $table->timestamps();
            $table->unique(['tenant_id', 'node_id', 'prerequisite_node_id'], 'curriculum_prereq_edge_uq');
            $table->foreign(['tenant_id', 'curriculum_version_id', 'node_id'], 'curriculum_prereq_node_fk')->references(['tenant_id', 'curriculum_version_id', 'id'])->on('academic_curriculum_nodes')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'curriculum_version_id', 'prerequisite_node_id'], 'curriculum_prereq_ref_fk')->references(['tenant_id', 'curriculum_version_id', 'id'])->on('academic_curriculum_nodes')->restrictOnDelete();
        });

        Schema::create('academic_competency_frameworks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('code', 48);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('version_number')->default(1);
            $table->string('status', 24)->default('draft')->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code', 'version_number'], 'competency_framework_ver_uq');
            $table->unique(['tenant_id', 'id'], 'competency_framework_tenant_id_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'competency_framework_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('academic_competencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('framework_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 64);
            $table->string('title', 180);
            $table->text('descriptor');
            $table->text('mastery_criteria')->nullable();
            $table->string('level_label', 80)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'framework_id', 'code'], 'competency_code_uq');
            $table->unique(['tenant_id', 'framework_id', 'id'], 'competency_framework_id_uq');
            $table->unique(['tenant_id', 'id'], 'competency_tenant_id_uq');
            $table->foreign(['tenant_id', 'framework_id'], 'competency_framework_fk')->references(['tenant_id', 'id'])->on('academic_competency_frameworks')->restrictOnDelete();
            $table->foreign(['tenant_id', 'framework_id', 'parent_id'], 'competency_parent_fk')->references(['tenant_id', 'framework_id', 'id'])->on('academic_competencies')->restrictOnDelete();
        });

        Schema::create('academic_curriculum_node_competencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('curriculum_version_id');
            $table->unsignedBigInteger('node_id');
            $table->unsignedBigInteger('competency_id');
            $table->string('expected_level', 80)->nullable();
            $table->string('mapping_strength', 16)->default('introduced');
            $table->timestamps();
            $table->unique(['tenant_id', 'node_id', 'competency_id'], 'curriculum_node_comp_uq');
            $table->foreign(['tenant_id', 'curriculum_version_id', 'node_id'], 'curriculum_node_comp_node_fk')->references(['tenant_id', 'curriculum_version_id', 'id'])->on('academic_curriculum_nodes')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'competency_id'], 'curriculum_node_comp_comp_fk')->references(['tenant_id', 'id'])->on('academic_competencies')->restrictOnDelete();
        });

        Schema::create('academic_curriculum_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('curriculum_version_id');
            $table->foreignId('reviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 24)->default('assigned');
            $table->text('review_note')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'reviewer_user_id', 'decision'], 'curriculum_review_user_idx');
            $table->foreign(['tenant_id', 'curriculum_version_id'], 'curriculum_review_version_fk')->references(['tenant_id', 'id'])->on('academic_curriculum_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_curriculum_reviews');
        Schema::dropIfExists('academic_curriculum_node_competencies');
        Schema::dropIfExists('academic_competencies');
        Schema::dropIfExists('academic_competency_frameworks');
        Schema::dropIfExists('academic_curriculum_prerequisites');
        Schema::dropIfExists('academic_curriculum_node_outcomes');
        Schema::dropIfExists('academic_curriculum_nodes');
        Schema::dropIfExists('academic_curriculum_versions');
        Schema::dropIfExists('academic_curricula');
        Schema::dropIfExists('academic_curriculum_framework_outcomes');
        Schema::dropIfExists('academic_curriculum_frameworks');
    }
};
