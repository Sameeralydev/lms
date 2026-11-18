<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = ['billing.refunds.view', 'billing.refunds.request', 'billing.refunds.approve', 'billing.refunds.execute'];

    public function up(): void
    {
        DB::transaction(function (): void {
            $catalog = PermissionCatalog::tenantPermissions();
            $rows = [];
            foreach (self::PERMISSIONS as $key) {
                $rows[] = ['key' => $key, 'description' => $catalog[$key], 'scope' => 'tenant', 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('permissions')->upsert($rows, ['key'], ['description', 'scope', 'updated_at']);
            $ids = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id', 'key');
            $roleKeys = ['tenant.owner', 'tenant.admin', 'finance.manager'];
            foreach (DB::table('roles')->where('scope', 'tenant')->where('is_system', true)->whereIn('key', $roleKeys)->get(['id']) as $role) {
                foreach ($ids as $permissionId) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        // Permissions are retained as stable audit history and are not revoked on rollback.
    }
};
