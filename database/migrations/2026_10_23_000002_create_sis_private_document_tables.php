<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sis_private_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->string('category', 48);
            $table->string('scan_status', 24)->default('pending');
            $table->unsignedInteger('current_version')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'sis_private_doc_tenant_id_uq');
            $table->index(['tenant_id', 'student_id', 'category', 'updated_at'], 'sis_private_doc_student_idx');
            $table->foreign(['tenant_id', 'student_id'], 'sis_private_doc_student_fk')
                ->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
        });

        Schema::create('sis_private_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('version_number');
            $table->string('private_path', 500);
            $table->string('original_name', 180);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'sis_private_ver_tenant_id_uq');
            $table->unique(['tenant_id', 'document_id', 'version_number'], 'sis_private_ver_number_uq');
            $table->index(['tenant_id', 'document_id', 'created_at'], 'sis_private_ver_doc_idx');
            $table->foreign(['tenant_id', 'document_id'], 'sis_private_ver_doc_fk')
                ->references(['tenant_id', 'id'])->on('sis_private_documents')->cascadeOnDelete();
        });

        Schema::create('sis_private_document_scan_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 24)->default('pending');
            $table->string('result_code', 80)->nullable();
            $table->string('scanner', 80)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'document_version_id', 'attempt_number'], 'sis_private_scan_attempt_uq');
            $table->foreign(['tenant_id', 'document_version_id'], 'sis_private_scan_version_fk')
                ->references(['tenant_id', 'id'])->on('sis_private_document_versions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sis_private_document_scan_events');
        Schema::dropIfExists('sis_private_document_versions');
        Schema::dropIfExists('sis_private_documents');
    }
};
