<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_grade_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('academic_period_id');
            $table->string('policy_key', 64);
            $table->string('name', 160);
            $table->string('scale_type', 24)->default('percentage');
            $table->string('status', 20)->default('draft');
            $table->string('rounding_mode', 16)->default('half_up');
            $table->unsignedTinyInteger('precision')->default(2);
            $table->unsignedSmallInteger('version')->default(1);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'grade_policy_tenant_id_uq');
            $table->unique(['tenant_id', 'institution_id', 'academic_period_id', 'policy_key', 'version'], 'grade_policy_version_uq');
            $table->index(['tenant_id', 'institution_id', 'academic_period_id', 'status'], 'grade_policy_lookup_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'grade_policy_institution_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'grade_policy_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_period_id'], 'grade_policy_period_fk')->references(['tenant_id', 'id'])->on('academic_periods')->restrictOnDelete();
        });

        Schema::create('academic_grade_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('policy_id');
            $table->string('category_key', 48);
            $table->string('name', 120);
            $table->unsignedSmallInteger('weight_basis_points');
            $table->boolean('required')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'grade_category_tenant_id_uq');
            $table->unique(['tenant_id', 'policy_id', 'category_key'], 'grade_category_key_uq');
            $table->index(['tenant_id', 'policy_id'], 'grade_category_policy_idx');
            $table->foreign(['tenant_id', 'policy_id'], 'grade_category_policy_fk')->references(['tenant_id', 'id'])->on('academic_grade_policies')->restrictOnDelete();
        });

        Schema::create('academic_gradebooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('section_id');
            $table->unsignedBigInteger('academic_period_id');
            $table->unsignedBigInteger('policy_id');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'gradebook_tenant_id_uq');
            $table->unique(['tenant_id', 'section_id', 'academic_period_id'], 'gradebook_section_period_uq');
            $table->index(['tenant_id', 'institution_id', 'status'], 'gradebook_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'gradebook_institution_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'section_id'], 'gradebook_section_fk')->references(['tenant_id', 'id'])->on('course_sections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_period_id'], 'gradebook_period_fk')->references(['tenant_id', 'id'])->on('academic_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'policy_id'], 'gradebook_policy_fk')->references(['tenant_id', 'id'])->on('academic_grade_policies')->restrictOnDelete();
        });

        Schema::create('academic_gradebook_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('gradebook_id');
            $table->unsignedBigInteger('category_id');
            $table->string('item_key', 64);
            $table->string('title', 180);
            $table->decimal('maximum_points', 9, 2);
            $table->string('source_type', 24)->default('manual');
            $table->string('source_key', 160)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'gradebook_item_tenant_id_uq');
            $table->unique(['tenant_id', 'gradebook_id', 'item_key'], 'gradebook_item_key_uq');
            $table->unique(['tenant_id', 'gradebook_id', 'source_type', 'source_key'], 'gradebook_item_source_uq');
            $table->index(['tenant_id', 'gradebook_id', 'category_id', 'status'], 'gradebook_item_category_idx');
            $table->foreign(['tenant_id', 'gradebook_id'], 'gradebook_item_book_fk')->references(['tenant_id', 'id'])->on('academic_gradebooks')->restrictOnDelete();
            $table->foreign(['tenant_id', 'category_id'], 'gradebook_item_category_fk')->references(['tenant_id', 'id'])->on('academic_grade_categories')->restrictOnDelete();
        });

        Schema::create('academic_grade_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('gradebook_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->decimal('earned_points', 9, 2)->nullable();
            $table->string('entry_status', 20)->default('missing');
            $table->text('feedback')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'grade_entry_tenant_id_uq');
            $table->unique(['tenant_id', 'item_id', 'enrollment_id'], 'grade_entry_item_student_uq');
            $table->index(['tenant_id', 'gradebook_id', 'enrollment_id'], 'grade_entry_book_student_idx');
            $table->foreign(['tenant_id', 'gradebook_id'], 'grade_entry_book_fk')->references(['tenant_id', 'id'])->on('academic_gradebooks')->restrictOnDelete();
            $table->foreign(['tenant_id', 'item_id'], 'grade_entry_item_fk')->references(['tenant_id', 'id'])->on('academic_gradebook_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'enrollment_id'], 'grade_entry_enrollment_fk')->references(['tenant_id', 'id'])->on('course_enrollments')->restrictOnDelete();
        });

        Schema::create('academic_grade_entry_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('entry_id');
            $table->unsignedInteger('revision_number');
            $table->decimal('previous_points', 9, 2)->nullable();
            $table->decimal('new_points', 9, 2)->nullable();
            $table->string('previous_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->string('reason', 500)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'entry_id', 'revision_number'], 'grade_entry_revision_number_uq');
            $table->foreign(['tenant_id', 'entry_id'], 'grade_entry_revision_entry_fk')->references(['tenant_id', 'id'])->on('academic_grade_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_grade_entry_revisions');
        Schema::dropIfExists('academic_grade_entries');
        Schema::dropIfExists('academic_gradebook_items');
        Schema::dropIfExists('academic_gradebooks');
        Schema::dropIfExists('academic_grade_categories');
        Schema::dropIfExists('academic_grade_policies');
    }
};
