<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_grade_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('gradebook_id');
            $table->unsignedInteger('publication_number');
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('published_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'grade_pub_tenant_id_uq');
            $table->unique(['tenant_id', 'gradebook_id', 'publication_number'], 'grade_pub_book_version_uq');
            $table->foreign(['tenant_id', 'gradebook_id'], 'grade_pub_book_fk')->references(['tenant_id', 'id'])->on('academic_gradebooks')->restrictOnDelete();
        });

        Schema::create('academic_grade_publication_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('publication_id');
            $table->unsignedBigInteger('gradebook_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedInteger('percentage_basis_points');
            $table->decimal('percentage', 7, 2);
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'grade_pub_entry_tenant_id_uq');
            $table->unique(['tenant_id', 'publication_id', 'enrollment_id'], 'grade_pub_entry_student_uq');
            $table->index(['tenant_id', 'enrollment_id', 'gradebook_id'], 'grade_pub_entry_learner_idx');
            $table->foreign(['tenant_id', 'publication_id'], 'grade_pub_entry_publication_fk')->references(['tenant_id', 'id'])->on('academic_grade_publications')->restrictOnDelete();
            $table->foreign(['tenant_id', 'gradebook_id'], 'grade_pub_entry_book_fk')->references(['tenant_id', 'id'])->on('academic_gradebooks')->restrictOnDelete();
            $table->foreign(['tenant_id', 'enrollment_id'], 'grade_pub_entry_enrollment_fk')->references(['tenant_id', 'id'])->on('course_enrollments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_grade_publication_entries');
        Schema::dropIfExists('academic_grade_publications');
    }
};
