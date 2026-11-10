<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_report_card_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('academic_period_id');
            $table->unsignedBigInteger('section_id');
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->text('comment');
            $table->string('status', 16)->default('submitted');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'institution_id', 'student_id', 'academic_period_id', 'section_id', 'teacher_id', 'version'], 'academic_report_comment_version_uq');
            $table->index(['tenant_id', 'institution_id', 'student_id', 'academic_period_id', 'status'], 'academic_report_comment_lookup_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'academic_report_comment_institution_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'academic_report_comment_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_period_id'], 'academic_report_comment_period_fk')->references(['tenant_id', 'id'])->on('academic_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'section_id'], 'academic_report_comment_section_fk')->references(['tenant_id', 'id'])->on('course_sections')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_report_card_comments');
    }
};
