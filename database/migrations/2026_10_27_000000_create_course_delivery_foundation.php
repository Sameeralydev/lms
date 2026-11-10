<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_delivery_workspaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('academic_program_id')->nullable();
            $table->unsignedBigInteger('academic_course_id');
            $table->unsignedBigInteger('academic_course_version_id');
            $table->unsignedBigInteger('academic_curriculum_version_id')->nullable();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->string('public_id', 36)->unique();
            $table->string('code', 64);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('delivery_mode', 24)->default('self_paced');
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'delivery_workspace_tenant_id_uq');
            $table->unique(['tenant_id', 'institution_id', 'code'], 'delivery_workspace_code_uq');
            $table->index(['tenant_id', 'status', 'updated_at'], 'delivery_workspace_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'delivery_workspace_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'delivery_workspace_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_program_id'], 'delivery_workspace_program_fk')->references(['tenant_id', 'id'])->on('academic_programs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_course_id'], 'delivery_workspace_course_fk')->references(['tenant_id', 'id'])->on('academic_courses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_course_version_id'], 'delivery_workspace_course_version_fk')->references(['tenant_id', 'id'])->on('academic_course_versions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_curriculum_version_id'], 'delivery_workspace_curriculum_fk')->references(['tenant_id', 'id'])->on('academic_curriculum_versions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_year_id'], 'delivery_workspace_year_fk')->references(['tenant_id', 'id'])->on('academic_years')->restrictOnDelete();
        });

        Schema::create('course_delivery_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('workspace_id');
            $table->foreignId('membership_id')->constrained('tenant_memberships')->restrictOnDelete();
            $table->string('assignment_role', 24);
            $table->unsignedInteger('sequence')->default(1);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->text('responsibility')->nullable();
            $table->text('change_reason')->nullable();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'workspace_id', 'membership_id', 'assignment_role', 'sequence'], 'delivery_assignment_version_uq');
            $table->index(['tenant_id', 'workspace_id', 'status', 'assignment_role'], 'delivery_assignment_scope_idx');
            $table->foreign(['tenant_id', 'workspace_id'], 'delivery_assignment_workspace_fk')->references(['tenant_id', 'id'])->on('course_delivery_workspaces')->cascadeOnDelete();
        });

        Schema::create('course_delivery_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('workspace_id');
            $table->string('title', 180);
            $table->text('summary')->nullable();
            $table->unsignedInteger('sort_order');
            $table->string('status', 24)->default('draft');
            $table->timestamps();
            $table->unique(['tenant_id', 'workspace_id', 'id'], 'delivery_module_scope_id_uq');
            $table->unique(['tenant_id', 'workspace_id', 'sort_order'], 'delivery_module_order_uq');
            $table->index(['tenant_id', 'workspace_id', 'status', 'sort_order'], 'delivery_module_list_idx');
            $table->foreign(['tenant_id', 'workspace_id'], 'delivery_module_workspace_fk')->references(['tenant_id', 'id'])->on('course_delivery_workspaces')->cascadeOnDelete();
        });

        Schema::create('course_delivery_lessons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('module_id');
            $table->string('title', 180);
            $table->text('summary')->nullable();
            $table->text('learning_objectives')->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->unsignedInteger('sort_order');
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('draft_author_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_decision', 24)->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('published_version_number')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'delivery_lesson_tenant_id_uq');
            $table->unique(['tenant_id', 'workspace_id', 'id'], 'delivery_lesson_scope_id_uq');
            $table->unique(['tenant_id', 'workspace_id', 'module_id', 'sort_order'], 'delivery_lesson_order_uq');
            $table->index(['tenant_id', 'workspace_id', 'status', 'module_id'], 'delivery_lesson_status_idx');
            $table->foreign(['tenant_id', 'workspace_id'], 'delivery_lesson_workspace_fk')->references(['tenant_id', 'id'])->on('course_delivery_workspaces')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'workspace_id', 'module_id'], 'delivery_lesson_module_fk')->references(['tenant_id', 'workspace_id', 'id'])->on('course_delivery_modules')->cascadeOnDelete();
        });

        Schema::create('course_delivery_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('lesson_id');
            $table->unsignedInteger('sort_order');
            $table->string('block_type', 24);
            $table->json('content');
            $table->timestamps();
            $table->unique(['tenant_id', 'lesson_id', 'sort_order'], 'delivery_block_order_uq');
            $table->index(['tenant_id', 'lesson_id'], 'delivery_block_lesson_idx');
            $table->foreign(['tenant_id', 'lesson_id'], 'delivery_block_lesson_fk')->references(['tenant_id', 'id'])->on('course_delivery_lessons')->cascadeOnDelete();
        });

        Schema::create('course_delivery_lesson_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('lesson_id');
            $table->unsignedInteger('version_number');
            $table->json('lesson_snapshot');
            $table->char('content_checksum', 64);
            $table->foreignId('authored_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'lesson_id', 'version_number'], 'delivery_revision_version_uq');
            $table->index(['tenant_id', 'lesson_id', 'published_at'], 'delivery_revision_history_idx');
            $table->foreign(['tenant_id', 'lesson_id'], 'delivery_revision_lesson_fk')->references(['tenant_id', 'id'])->on('course_delivery_lessons')->restrictOnDelete();
        });

        DB::transaction(function (): void {
            $permissions = [
                'learning.delivery.view' => 'View course delivery workspaces and published learning content.',
                'learning.delivery.manage' => 'Manage course delivery workspaces and draft learning content.',
                'learning.delivery.teach' => 'Teach and author content in explicitly assigned course workspaces.',
                'learning.delivery.review' => 'Independently review submitted course lessons.',
                'learning.delivery.publish' => 'Publish independently reviewed course lessons.',
                'learning.delivery.learn' => 'Access published lessons explicitly available to the member.',
            ];
            $now = now();
            DB::table('permissions')->upsert(array_map(static fn (string $key, string $description): array => [
                'key' => $key, 'description' => $description, 'scope' => 'tenant', 'created_at' => $now, 'updated_at' => $now,
            ], array_keys($permissions), array_values($permissions)), ['key'], ['description', 'scope', 'updated_at']);

            $ids = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id', 'key');
            $groups = [
                'tenant.owner' => array_keys($permissions),
                'tenant.admin' => array_keys($permissions),
                'academic.admin' => ['learning.delivery.view', 'learning.delivery.manage', 'learning.delivery.review', 'learning.delivery.publish'],
                'teacher' => ['learning.delivery.view', 'learning.delivery.teach'],
                'student' => ['learning.delivery.view', 'learning.delivery.learn'],
                'auditor' => ['learning.delivery.view'],
            ];
            foreach (DB::table('tenants')->pluck('id') as $tenantId) {
                foreach ($groups as $roleKey => $permissionKeys) {
                    $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('key', $roleKey)->where('scope', 'tenant')->where('is_system', true)->value('id');
                    if ($roleId === null) {
                        continue;
                    }
                    $rows = [];
                    foreach ($permissionKeys as $permissionKey) {
                        if ($ids->has($permissionKey)) {
                            $rows[] = ['role_id' => $roleId, 'permission_id' => $ids[$permissionKey], 'created_at' => $now, 'updated_at' => $now];
                        }
                    }
                    if ($rows !== []) {
                        DB::table('permission_role')->insertOrIgnore($rows);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_delivery_lesson_revisions');
        Schema::dropIfExists('course_delivery_blocks');
        Schema::dropIfExists('course_delivery_lessons');
        Schema::dropIfExists('course_delivery_modules');
        Schema::dropIfExists('course_delivery_assignments');
        Schema::dropIfExists('course_delivery_workspaces');
        // Do not remove permission definitions or grants on rollback; another tenant may use them.
    }
};
