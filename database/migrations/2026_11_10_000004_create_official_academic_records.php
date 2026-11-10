<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_record_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('record_type', 24);
            $table->string('template_key', 64);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('name', 160);
            $table->json('configuration');
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'academic_record_template_tenant_id_uq');
            $table->unique(['tenant_id', 'institution_id', 'record_type', 'template_key', 'version'], 'academic_record_template_version_uq');
            $table->index(['tenant_id', 'institution_id', 'record_type', 'status'], 'academic_record_template_lookup_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'academic_record_template_institution_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('academic_record_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('academic_period_id')->nullable();
            $table->string('record_type', 24);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('issue_number');
            $table->char('verification_code', 36)->unique('academic_record_verification_code_uq');
            $table->json('record_snapshot');
            $table->char('source_hash', 64);
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'academic_record_issue_tenant_id_uq');
            $table->unique(['tenant_id', 'student_id', 'record_type', 'issue_number'], 'academic_record_issue_version_uq');
            $table->index(['tenant_id', 'institution_id', 'student_id', 'record_type', 'status'], 'academic_record_issue_lookup_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'academic_record_issue_institution_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'academic_record_issue_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'template_id'], 'academic_record_issue_template_fk')->references(['tenant_id', 'id'])->on('academic_record_templates')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_period_id'], 'academic_record_issue_period_fk')->references(['tenant_id', 'id'])->on('academic_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'supersedes_id'], 'academic_record_issue_supersedes_fk')->references(['tenant_id', 'id'])->on('academic_record_issues')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_record_issues');
        Schema::dropIfExists('academic_record_templates');
    }
};
