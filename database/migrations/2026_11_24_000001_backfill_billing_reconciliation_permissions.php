<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEYS = ['billing.reconciliation.manage', 'billing.reconciliation.review'];

    public function up(): void
    {
        DB::transaction(function (): void {
            $catalog = PermissionCatalog::tenantPermissions();
            $rows = [];
            foreach (self::KEYS as $key) {
                if (! isset($catalog[$key])) {
                    throw new RuntimeException("Permission {$key} is missing from PermissionCatalog.");
                }
                $rows[] = ['key' => $key, 'description' => $catalog[$key], 'scope' => 'tenant', 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('permissions')->upsert($rows, ['key'], ['description', 'scope', 'updated_at']);
            $permissionIds = DB::table('permissions')->whereIn('key', self::KEYS)->pluck('id', 'key');
            $roleKeys = ['tenant.owner', 'tenant.admin', 'finance.manager'];
            foreach (DB::table('roles')->where('scope', 'tenant')->whereIn('key', $roleKeys)->get(['id']) as $role) {
                foreach ($permissionIds as $permissionId) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        // Grants are additive to preserve deployed role history.
    }
};
