<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string,string> */
    private array $permissions = [
        'academic.curriculum.view' => 'View curriculum frameworks, plans, and design coverage.',
        'academic.curriculum.manage' => 'Create and edit draft curriculum frameworks, versions, hierarchy, and mappings.',
        'academic.curriculum.review' => 'Independently review submitted curriculum versions.',
        'academic.curriculum.publish' => 'Publish independently approved curriculum versions.',
        'academic.curriculum.templates.share' => 'Publish explicitly approved curriculum templates for adoption.',
        'academic.curriculum.audit.view' => 'View curriculum governance and revision history.',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $rows = [];
            foreach ($this->permissions as $key => $description) {
                $rows[] = ['key' => $key, 'description' => $description, 'scope' => 'tenant', 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('permissions')->upsert($rows, ['key'], ['description', 'scope', 'updated_at']);
            $ids = DB::table('permissions')->whereIn('key', array_keys($this->permissions))->pluck('id', 'key');
            $all = $ids->values()->all();
            $read = $ids->only(['academic.curriculum.view', 'academic.curriculum.audit.view'])->values()->all();

            foreach (DB::table('tenants')->pluck('id') as $tenantId) {
                foreach (['tenant.owner' => $all, 'tenant.admin' => $all, 'academic.admin' => $all, 'auditor' => $read] as $roleKey => $permissionIds) {
                    $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('scope', 'tenant')->where('is_system', true)->where('key', $roleKey)->value('id');
                    if ($roleId === null) {
                        continue;
                    }
                    DB::table('permission_role')->insertOrIgnore(array_map(static fn (int $permissionId): array => [
                        'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
                    ], $permissionIds));
                }
            }
        });
    }

    public function down(): void
    {
        // Permission backfills are additive; rollback must not revoke tenant-assigned capabilities.
    }
};
