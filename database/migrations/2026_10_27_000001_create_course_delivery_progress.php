<?php

use App\Domain\Learning\Phase07ProgressMigrationRecoveryPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('course_delivery_progress')) {
            Phase07ProgressMigrationRecoveryPolicy::assertRecoverable(
                Phase07ProgressMigrationRecoveryPolicy::inspect(),
                (int) DB::table('course_delivery_progress')->count(),
            );

            return;
        }

        Schema::create('course_delivery_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('lesson_id');
            $table->unsignedBigInteger('membership_id');
            $table->unsignedInteger('revision_version');
            $table->string('status', 16)->default('started');
            $table->unsignedInteger('resume_seconds')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'workspace_id', 'lesson_id', 'membership_id'], 'delivery_progress_member_uq');
            $table->index(['tenant_id', 'workspace_id', 'membership_id', 'status'], 'delivery_progress_course_idx');
            $table->foreign(['tenant_id', 'workspace_id'], 'delivery_progress_workspace_fk')
                ->references(['tenant_id', 'id'])->on('course_delivery_workspaces')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'workspace_id', 'lesson_id'], 'delivery_progress_lesson_fk')
                ->references(['tenant_id', 'workspace_id', 'id'])->on('course_delivery_lessons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_delivery_progress');
    }
};
