<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_seating_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('session_id');
            $table->unsignedInteger('current_revision')->default(0);
            $table->unsignedInteger('published_revision')->default(0);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'exam_seat_plan_tenant_id_uq');
            $table->unique(['tenant_id', 'session_id'], 'exam_seat_plan_session_uq');
            $table->foreign(['tenant_id', 'session_id'], 'exam_seat_plan_session_fk')->references(['tenant_id', 'id'])->on('exam_sessions')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'updated_at'], 'exam_seat_plan_status_idx');
        });

        Schema::create('exam_seating_plan_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('plan_id');
            $table->unsignedInteger('revision');
            $table->json('assignment_snapshot');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('review_notes')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'exam_seat_rev_tenant_id_uq');
            $table->unique(['tenant_id', 'plan_id', 'revision'], 'exam_seat_rev_number_uq');
            $table->foreign(['tenant_id', 'plan_id'], 'exam_seat_rev_plan_fk')->references(['tenant_id', 'id'])->on('exam_seating_plans')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'created_at'], 'exam_seat_rev_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_seating_plan_revisions');
        Schema::dropIfExists('exam_seating_plans');
    }
};
