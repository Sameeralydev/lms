<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('operating_model', 32);
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 16)->default('en');
            $table->json('enabled_features');
            $table->json('terminology');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id'], 'acad_config_inst_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'acad_config_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('academic_years', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->string('name', 140);
            $table->string('code', 48);
            $table->text('description')->nullable();
            $table->string('academic_model', 32)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 16)->default('en');
            $table->string('status', 24)->default('draft')->index();
            $table->boolean('is_current')->default(false);
            $table->string('publication_status', 24)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code'], 'acad_year_code_uq');
            $table->unique(['tenant_id', 'id'], 'acad_year_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'starts_on'], 'acad_year_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'acad_year_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'acad_year_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        Schema::create('academic_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('academic_year_id');
            $table->string('name', 140);
            $table->string('code', 48);
            $table->string('period_type', 32);
            $table->unsignedSmallInteger('sequence');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('teaching_starts_on')->nullable();
            $table->date('teaching_ends_on')->nullable();
            $table->date('assessment_starts_on')->nullable();
            $table->date('assessment_ends_on')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->boolean('is_current')->default(false);
            $table->string('publication_status', 24)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'academic_year_id', 'code'], 'acad_period_code_uq');
            $table->unique(['tenant_id', 'id'], 'acad_period_tenant_id_uq');
            $table->index(['tenant_id', 'academic_year_id', 'status', 'starts_on'], 'acad_period_year_status_idx');
            $table->foreign(['tenant_id', 'academic_year_id'], 'acad_period_year_fk')->references(['tenant_id', 'id'])->on('academic_years')->restrictOnDelete();
        });

        Schema::create('academic_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('parent_unit_id')->nullable();
            $table->string('unit_type', 32);
            $table->string('name', 160);
            $table->string('code', 48);
            $table->text('description')->nullable();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code'], 'acad_unit_code_uq');
            $table->unique(['tenant_id', 'id'], 'acad_unit_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'parent_unit_id'], 'acad_unit_parent_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'acad_unit_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'parent_unit_id'], 'acad_unit_parent_fk')->references(['tenant_id', 'id'])->on('academic_units')->restrictOnDelete();
        });

        Schema::create('academic_unit_campuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('academic_unit_id');
            $table->unsignedBigInteger('campus_id');
            $table->timestamps();
            $table->unique(['tenant_id', 'academic_unit_id', 'campus_id'], 'acad_unit_campus_uq');
            $table->foreign(['tenant_id', 'academic_unit_id'], 'acad_unit_campus_unit_fk')->references(['tenant_id', 'id'])->on('academic_units')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'acad_unit_campus_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        Schema::create('academic_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('parent_level_id')->nullable();
            $table->string('name', 120);
            $table->string('code', 48);
            $table->string('category', 40)->default('custom');
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code'], 'acad_level_code_uq');
            $table->unique(['tenant_id', 'id'], 'acad_level_tenant_id_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'acad_level_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'parent_level_id'], 'acad_level_parent_fk')->references(['tenant_id', 'id'])->on('academic_levels')->restrictOnDelete();
        });

        Schema::create('academic_qualification_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('name', 140);
            $table->string('code', 48);
            $table->string('award_type', 60)->nullable();
            $table->string('framework_reference', 180)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code'], 'acad_qualification_code_uq');
            $table->unique(['tenant_id', 'id'], 'acad_qualification_tenant_id_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'acad_qualification_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('academic_credit_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('policy_key', 48);
            $table->string('name', 140);
            $table->unsignedSmallInteger('version');
            $table->boolean('credit_hours_enabled')->default(true);
            $table->boolean('separate_theory_lab')->default(false);
            $table->decimal('allowed_increment', 7, 3)->nullable();
            $table->decimal('maximum_credits', 9, 3)->nullable();
            $table->decimal('theory_contact_hours_per_credit', 8, 3)->nullable();
            $table->decimal('lab_contact_hours_per_credit', 8, 3)->nullable();
            $table->string('definition', 500)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'policy_key', 'version'], 'acad_credit_policy_ver_uq');
            $table->unique(['tenant_id', 'id'], 'acad_credit_policy_tenant_id_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'acad_credit_policy_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('academic_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_level_id');
            $table->unsignedBigInteger('qualification_type_id')->nullable();
            $table->unsignedBigInteger('academic_unit_id')->nullable();
            $table->unsignedBigInteger('credit_policy_id')->nullable();
            $table->string('title', 180);
            $table->string('code', 48);
            $table->decimal('duration_amount', 7, 2)->nullable();
            $table->string('duration_unit', 20)->nullable();
            $table->string('period_model', 32)->nullable();
            $table->boolean('credit_bearing')->default(false);
            $table->decimal('required_credits', 9, 3)->nullable();
            $table->string('delivery_mode', 32)->default('on_campus');
            $table->string('award_type', 60)->nullable();
            $table->text('description')->nullable();
            $table->text('entry_requirements_reference')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'code'], 'acad_program_code_uq');
            $table->unique(['tenant_id', 'id'], 'acad_program_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'title'], 'acad_program_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'acad_program_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_level_id'], 'acad_program_level_fk')->references(['tenant_id', 'id'])->on('academic_levels')->restrictOnDelete();
            $table->foreign(['tenant_id', 'qualification_type_id'], 'acad_program_qual_fk')->references(['tenant_id', 'id'])->on('academic_qualification_types')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_unit_id'], 'acad_program_unit_fk')->references(['tenant_id', 'id'])->on('academic_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'credit_policy_id'], 'acad_program_credit_fk')->references(['tenant_id', 'id'])->on('academic_credit_policies')->restrictOnDelete();
        });

        Schema::create('academic_program_campuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('academic_program_id');
            $table->unsignedBigInteger('campus_id');
            $table->timestamps();
            $table->unique(['tenant_id', 'academic_program_id', 'campus_id'], 'acad_program_campus_uq');
            $table->foreign(['tenant_id', 'academic_program_id'], 'acad_program_campus_prog_fk')->references(['tenant_id', 'id'])->on('academic_programs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'acad_program_campus_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        Schema::create('academic_classes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_level_id')->nullable();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('academic_program_id')->nullable();
            $table->string('group_type', 24);
            $table->string('code', 48);
            $table->string('name', 140);
            $table->unsignedInteger('structural_capacity')->nullable();
            $table->string('capacity_policy', 24)->default('advisory');
            $table->string('schedule_label', 40)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->foreignId('coordinator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'group_type', 'code'], 'acad_class_code_uq');
            $table->unique(['tenant_id', 'id'], 'acad_class_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'group_type', 'status'], 'acad_class_type_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'acad_class_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_level_id'], 'acad_class_level_fk')->references(['tenant_id', 'id'])->on('academic_levels')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_year_id'], 'acad_class_year_fk')->references(['tenant_id', 'id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'acad_class_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_program_id'], 'acad_class_program_fk')->references(['tenant_id', 'id'])->on('academic_programs')->restrictOnDelete();
        });

        Schema::create('academic_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('academic_class_id');
            $table->string('code', 48);
            $table->string('name', 140);
            $table->unsignedInteger('structural_capacity')->nullable();
            $table->foreignId('coordinator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'academic_class_id', 'code'], 'acad_section_code_uq');
            $table->foreign(['tenant_id', 'academic_class_id'], 'acad_section_class_fk')->references(['tenant_id', 'id'])->on('academic_classes')->restrictOnDelete();
        });

        // Add missing institution academic configuration without rewriting any institution data.
        $now = now();
        foreach (DB::table('institutions')->select(['id', 'tenant_id', 'type', 'timezone', 'locale'])->get() as $institution) {
            $model = match ($institution->type) {
                'school' => 'k12',
                'college', 'university' => 'higher_education',
                'academy' => 'academy',
                'corporate' => 'corporate',
                default => 'hybrid',
            };
            $terms = match ($model) {
                'k12' => ['level' => 'Grade', 'group' => 'Class', 'subject' => 'Subject', 'period' => 'Term'],
                'higher_education' => ['level' => 'Academic level', 'group' => 'Cohort', 'subject' => 'Course', 'period' => 'Semester'],
                'academy' => ['level' => 'Level', 'group' => 'Batch', 'subject' => 'Course', 'period' => 'Training period'],
                'corporate' => ['level' => 'Learning level', 'group' => 'Training cycle', 'subject' => 'Course', 'period' => 'Training period'],
                default => ['level' => 'Academic level', 'group' => 'Learning group', 'subject' => 'Subject', 'period' => 'Academic period'],
            };
            DB::table('academic_configurations')->insertOrIgnore([
                'tenant_id' => $institution->tenant_id,
                'institution_id' => $institution->id,
                'operating_model' => $model,
                'timezone' => $institution->timezone,
                'locale' => $institution->locale,
                'enabled_features' => json_encode(['calendar', 'organization', 'programs', 'catalog', 'quality'], JSON_THROW_ON_ERROR),
                'terminology' => json_encode($terms, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_sections');
        Schema::dropIfExists('academic_classes');
        Schema::dropIfExists('academic_program_campuses');
        Schema::dropIfExists('academic_programs');
        Schema::dropIfExists('academic_credit_policies');
        Schema::dropIfExists('academic_qualification_types');
        Schema::dropIfExists('academic_levels');
        Schema::dropIfExists('academic_unit_campuses');
        Schema::dropIfExists('academic_units');
        Schema::dropIfExists('academic_periods');
        Schema::dropIfExists('academic_years');
        Schema::dropIfExists('academic_configurations');
    }
};
