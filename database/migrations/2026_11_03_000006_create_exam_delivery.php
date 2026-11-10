<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_session_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('quiz_id');
            $table->unsignedBigInteger('quiz_version_id');
            $table->unsignedBigInteger('paper_version_id');
            $table->foreignId('configured_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('configured_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'session_id'], 'exam_delivery_session_uq');
            $table->unique(['tenant_id', 'id'], 'exam_delivery_tenant_id_uq');
            $table->foreign(['tenant_id', 'session_id'], 'exam_delivery_session_fk')->references(['tenant_id', 'id'])->on('exam_sessions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'quiz_id'], 'exam_delivery_quiz_fk')->references(['tenant_id', 'id'])->on('quizzes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'quiz_version_id'], 'exam_delivery_quiz_version_fk')->references(['tenant_id', 'id'])->on('quiz_versions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'paper_version_id'], 'exam_delivery_paper_version_fk')->references(['tenant_id', 'id'])->on('exam_paper_versions')->restrictOnDelete();
            $table->index(['tenant_id', 'quiz_id'], 'exam_delivery_quiz_idx');
        });

        Schema::create('exam_attempt_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('attempt_id');
            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->dateTime('submitted_at')->nullable();
            $table->string('receipt_reference', 48)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'session_id', 'candidate_id'], 'exam_binding_candidate_uq');
            $table->unique(['tenant_id', 'attempt_id'], 'exam_binding_attempt_uq');
            $table->unique(['tenant_id', 'receipt_reference'], 'exam_binding_receipt_uq');
            $table->foreign(['tenant_id', 'session_id'], 'exam_binding_session_fk')->references(['tenant_id', 'id'])->on('exam_sessions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'candidate_id'], 'exam_binding_candidate_fk')->references(['tenant_id', 'id'])->on('exam_candidates')->restrictOnDelete();
            $table->foreign(['tenant_id', 'attempt_id'], 'exam_binding_attempt_fk')->references(['tenant_id', 'id'])->on('quiz_attempts')->restrictOnDelete();
            $table->index(['tenant_id', 'session_id', 'expires_at'], 'exam_binding_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempt_bindings');
        Schema::dropIfExists('exam_session_deliveries');
    }
};
