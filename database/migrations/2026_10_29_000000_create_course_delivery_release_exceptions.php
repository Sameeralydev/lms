<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_delivery_release_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('lesson_id');
            $table->unsignedBigInteger('membership_id');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'workspace_id', 'lesson_id', 'membership_id', 'status', 'expires_at'], 'delivery_release_exception_lookup_idx');
            $table->foreign(['tenant_id', 'workspace_id'], 'delivery_release_exception_workspace_fk')
                ->references(['tenant_id', 'id'])->on('course_delivery_workspaces')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'workspace_id', 'lesson_id'], 'delivery_release_exception_lesson_fk')
                ->references(['tenant_id', 'workspace_id', 'id'])->on('course_delivery_lessons')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'membership_id'], 'delivery_release_exception_membership_fk')
                ->references(['tenant_id', 'id'])->on('tenant_memberships')->restrictOnDelete();
        });

        DB::transaction(function (): void {
            $now = now();
            DB::table('permissions')->upsert([[
                'key' => 'learning.delivery.release-exceptions.approve',
                'description' => 'Independently approve time-limited learner release exceptions.',
                'scope' => 'tenant', 'created_at' => $now, 'updated_at' => $now,
            ]], ['key'], ['description', 'scope', 'updated_at']);
            $permissionId = DB::table('permissions')->where('key', 'learning.delivery.release-exceptions.approve')->value('id');
            foreach (DB::table('roles')->whereIn('key', ['tenant.owner', 'tenant.admin'])->where('scope', 'tenant')->where('is_system', true)->pluck('id') as $roleId) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_delivery_release_exceptions');
        // Permission and role grants are intentionally retained on rollback.
    }
};
