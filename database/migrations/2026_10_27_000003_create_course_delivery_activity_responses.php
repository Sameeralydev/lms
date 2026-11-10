<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_delivery_blocks', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'lesson_id', 'id'], 'delivery_block_scope_id_uq');
        });

        Schema::table('course_delivery_progress', function (Blueprint $table): void {
            $table->timestamp('learner_confirmed_at')->nullable();
            $table->timestamp('instructor_confirmed_at')->nullable();
            $table->foreignId('instructor_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('course_delivery_activity_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('lesson_id');
            $table->unsignedBigInteger('membership_id');
            $table->unsignedInteger('revision_version');
            $table->unsignedBigInteger('block_id');
            $table->unsignedInteger('version_number');
            $table->string('state', 16);
            $table->longText('response_payload');
            $table->uuid('idempotency_key');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('retention_until')->nullable();
            $table->timestamps();

            $table->unique([
                'tenant_id', 'workspace_id', 'lesson_id', 'membership_id', 'revision_version', 'block_id', 'version_number',
            ], 'delivery_activity_version_uq');
            $table->unique(['tenant_id', 'membership_id', 'idempotency_key'], 'delivery_activity_idempotency_uq');
            $table->index(['tenant_id', 'workspace_id', 'membership_id', 'revision_version', 'state'], 'delivery_activity_learner_idx');

            $table->foreign(['tenant_id', 'workspace_id'], 'delivery_activity_workspace_fk')
                ->references(['tenant_id', 'id'])->on('course_delivery_workspaces')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'workspace_id', 'lesson_id'], 'delivery_activity_lesson_fk')
                ->references(['tenant_id', 'workspace_id', 'id'])->on('course_delivery_lessons')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'membership_id'], 'delivery_activity_membership_fk')
                ->references(['tenant_id', 'id'])->on('tenant_memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'lesson_id', 'revision_version'], 'delivery_activity_revision_fk')
                ->references(['tenant_id', 'lesson_id', 'version_number'])->on('course_delivery_lesson_revisions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'lesson_id', 'block_id'], 'delivery_activity_block_fk')
                ->references(['tenant_id', 'lesson_id', 'id'])->on('course_delivery_blocks')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_delivery_activity_responses');

        if (Schema::hasTable('course_delivery_progress')) {
            Schema::table('course_delivery_progress', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('instructor_confirmed_by');
                $table->dropColumn(['learner_confirmed_at', 'instructor_confirmed_at']);
            });
        }

        if (Schema::hasTable('course_delivery_blocks')) {
            Schema::table('course_delivery_blocks', function (Blueprint $table): void {
                $table->dropUnique('delivery_block_scope_id_uq');
            });
        }
    }
};
