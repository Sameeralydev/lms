<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_date_sheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('cycle_id');
            $table->unsignedInteger('current_revision')->default(0);
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'cycle_id'], 'exam_date_sheet_cycle_uq');
            $table->unique(['tenant_id', 'id'], 'exam_date_sheet_tenant_id_uq');
            $table->foreign(['tenant_id', 'cycle_id'], 'exam_date_sheet_cycle_fk')->references(['tenant_id', 'id'])->on('exam_cycles')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'updated_at'], 'exam_date_sheet_queue_idx');
        });

        Schema::create('exam_date_sheet_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('date_sheet_id');
            $table->unsignedInteger('revision');
            $table->json('schedule_snapshot');
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('review_notes')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'date_sheet_id', 'revision'], 'exam_date_sheet_revision_uq');
            $table->unique(['tenant_id', 'id'], 'exam_date_sheet_revision_tenant_id_uq');
            $table->foreign(['tenant_id', 'date_sheet_id'], 'exam_date_sheet_revision_sheet_fk')->references(['tenant_id', 'id'])->on('exam_date_sheets')->cascadeOnDelete();
            $table->index(['tenant_id', 'date_sheet_id', 'status', 'revision'], 'exam_date_sheet_revision_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_date_sheet_revisions');
        Schema::dropIfExists('exam_date_sheets');
    }
};
