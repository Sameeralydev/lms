<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_papers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('session_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('current_version')->default(0);
            $table->string('current_set_code', 8)->nullable();
            $table->string('status', 20)->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'exam_paper_tenant_id_uq');
            $table->unique(['tenant_id', 'session_id'], 'exam_paper_session_uq');
            $table->foreign(['tenant_id', 'session_id'], 'exam_paper_session_fk')->references(['tenant_id', 'id'])->on('exam_sessions')->restrictOnDelete();
            $table->index(['tenant_id', 'status'], 'exam_paper_status_idx');
        });

        Schema::create('exam_paper_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('paper_id');
            $table->unsignedInteger('version_number');
            $table->string('set_code', 8)->default('A');
            $table->string('title', 180);
            $table->longText('instructions')->nullable();
            $table->longText('blueprint_snapshot');
            $table->longText('question_snapshot');
            $table->decimal('total_marks', 9, 2);
            $table->unsignedSmallInteger('duration_minutes');
            $table->char('content_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'paper_id', 'set_code', 'version_number'], 'exam_paper_ver_seq_uq');
            $table->unique(['tenant_id', 'id'], 'exam_paper_ver_tenant_id_uq');
            $table->foreign(['tenant_id', 'paper_id'], 'exam_paper_ver_paper_fk')->references(['tenant_id', 'id'])->on('exam_papers')->restrictOnDelete();
            $table->index(['tenant_id', 'paper_id', 'status'], 'exam_paper_ver_status_idx');
        });

        Schema::create('exam_paper_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('paper_version_id');
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 16);
            $table->text('notes')->nullable();
            $table->dateTime('decided_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'paper_version_id', 'reviewer_id'], 'exam_paper_review_once_uq');
            $table->foreign(['tenant_id', 'paper_version_id'], 'exam_paper_review_ver_fk')->references(['tenant_id', 'id'])->on('exam_paper_versions')->restrictOnDelete();
            $table->index(['tenant_id', 'decision', 'decided_at'], 'exam_paper_review_decision_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_paper_reviews');
        Schema::dropIfExists('exam_paper_versions');
        Schema::dropIfExists('exam_papers');
    }
};
