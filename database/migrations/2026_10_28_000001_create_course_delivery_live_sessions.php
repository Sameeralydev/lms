<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_delivery_live_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('lesson_id')->nullable();
            $table->unsignedBigInteger('instructor_membership_id');
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('delivery_type', 20);
            $table->string('timezone', 64);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('meeting_url', 2048)->nullable();
            $table->string('venue', 240)->nullable();
            $table->text('preparation_materials')->nullable();
            $table->text('post_session_resources')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'workspace_id', 'starts_at', 'status'], 'delivery_session_calendar_idx');
            $table->index(['tenant_id', 'instructor_membership_id', 'starts_at'], 'delivery_session_instructor_idx');
            $table->foreign(['tenant_id', 'workspace_id'], 'delivery_session_workspace_fk')
                ->references(['tenant_id', 'id'])->on('course_delivery_workspaces')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'workspace_id', 'lesson_id'], 'delivery_session_lesson_fk')
                ->references(['tenant_id', 'workspace_id', 'id'])->on('course_delivery_lessons')->restrictOnDelete();
            $table->foreign(['tenant_id', 'instructor_membership_id'], 'delivery_session_instructor_fk')
                ->references(['tenant_id', 'id'])->on('tenant_memberships')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_delivery_live_sessions');
    }
};
