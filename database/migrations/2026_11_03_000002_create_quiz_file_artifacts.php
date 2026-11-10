<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_file_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('question_version_id');
            $table->unsignedBigInteger('attempt_id')->nullable();
            $table->string('purpose', 32);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('private_path', 255)->unique();
            $table->string('scan_status', 24)->default('quarantined');
            $table->string('scanner', 120)->nullable();
            $table->string('signature_version', 120)->nullable();
            $table->string('result_code', 64)->nullable();
            $table->dateTime('scanned_at')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'question_version_id'], 'qfa_question_version_fk')
                ->references(['tenant_id', 'id'])->on('quiz_question_versions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'attempt_id'], 'qfa_attempt_fk')
                ->references(['tenant_id', 'id'])->on('quiz_attempts')->cascadeOnDelete();
            $table->index(['tenant_id', 'purpose', 'question_version_id', 'attempt_id', 'scan_status'], 'qfa_scope_status_idx');
            $table->index(['tenant_id', 'sha256'], 'qfa_tenant_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_file_artifacts');
    }
};
