<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const ROLE_GRANTS = [
        'tenant.owner' => ['academic.records.issue', 'academic.records.review', 'academic.records.view-all', 'academic.records.gpa.manage'],
        'tenant.admin' => ['academic.records.issue', 'academic.records.review', 'academic.records.view-all', 'academic.records.gpa.manage'],
        'academic.admin' => ['academic.records.issue', 'academic.records.review', 'academic.records.view-all', 'academic.records.gpa.manage'],
        'sis.registrar' => ['academic.records.issue', 'academic.records.review', 'academic.records.view-all', 'academic.records.gpa.manage'],
        'student' => ['academic.records.view-own'],
        'parent.guardian' => ['academic.records.guardian.view'],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $catalog = PermissionCatalog::tenantPermissions();
            $keys = array_values(array_unique(array_merge(...array_values(self::ROLE_GRANTS))));
            $rows = [];
            foreach ($keys as $key) {
                if (! isset($catalog[$key])) {
                    throw new RuntimeException("The academic-record permission {$key} is missing from PermissionCatalog.");
                }
                $rows[] = ['key' => $key, 'description' => $catalog[$key], 'scope' => 'tenant', 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('permissions')->upsert($rows, ['key'], ['description', 'scope', 'updated_at']);
            $permissionIds = DB::table('permissions')->whereIn('key', $keys)->pluck('id', 'key');
            foreach (DB::table('roles')->where('scope', 'tenant')->get(['id', 'tenant_id', 'key']) as $role) {
                foreach (self::ROLE_GRANTS[$role->key] ?? [] as $key) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionIds[$key], 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        // Existing tenant grants are additive; rollback does not revoke active role capabilities.
    }
};
