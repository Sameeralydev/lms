<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_grade_source_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('gradebook_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->string('source_type', 24);
            $table->unsignedBigInteger('source_record_id');
            $table->string('source_version', 64);
            $table->char('source_fingerprint', 64);
            $table->decimal('imported_points', 9, 2);
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('imported_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'grade_source_import_tenant_id_uq');
            $table->unique(['tenant_id', 'item_id', 'enrollment_id'], 'grade_source_item_enrollment_uq');
            $table->index(['tenant_id', 'source_type', 'source_record_id'], 'grade_source_record_idx');
            $table->foreign(['tenant_id', 'gradebook_id'], 'grade_source_book_fk')->references(['tenant_id', 'id'])->on('academic_gradebooks')->restrictOnDelete();
            $table->foreign(['tenant_id', 'item_id'], 'grade_source_item_fk')->references(['tenant_id', 'id'])->on('academic_gradebook_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'enrollment_id'], 'grade_source_enrollment_fk')->references(['tenant_id', 'id'])->on('course_enrollments')->restrictOnDelete();
        });

        Schema::create('academic_grade_source_import_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('import_id');
            $table->unsignedInteger('revision_number');
            $table->unsignedBigInteger('source_record_id');
            $table->string('source_version', 64);
            $table->char('source_fingerprint', 64);
            $table->decimal('previous_points', 9, 2)->nullable();
            $table->decimal('imported_points', 9, 2);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('imported_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'import_id', 'revision_number'], 'grade_source_revision_number_uq');
            $table->foreign(['tenant_id', 'import_id'], 'grade_source_revision_import_fk')->references(['tenant_id', 'id'])->on('academic_grade_source_imports')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_grade_source_import_revisions');
        Schema::dropIfExists('academic_grade_source_imports');
    }
};
