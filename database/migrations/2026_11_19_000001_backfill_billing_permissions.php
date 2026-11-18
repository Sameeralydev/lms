<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = ['billing.view', 'billing.fees.manage', 'billing.invoices.view', 'billing.invoices.issue', 'billing.invoices.cancel', 'billing.reports.view', 'billing.concessions.manage', 'billing.adjustments.manage', 'billing.portal.view-own'];

    public function up(): void
    {
        DB::transaction(function (): void {
            $catalog = PermissionCatalog::tenantPermissions();
            $rows = [];
            foreach (self::PERMISSIONS as $key) {
                if (! isset($catalog[$key])) {
                    throw new RuntimeException("Billing permission {$key} is missing from PermissionCatalog.");
                }
                $rows[] = ['key' => $key, 'description' => $catalog[$key], 'scope' => 'tenant', 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('permissions')->upsert($rows, ['key'], ['description', 'scope', 'updated_at']);
            $ids = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id', 'key');
            $grants = [
                'tenant.owner' => self::PERMISSIONS,
                'tenant.admin' => array_values(array_diff(self::PERMISSIONS, ['billing.portal.view-own'])),
                'finance.manager' => ['tenant.view', ...self::PERMISSIONS],
                'student' => ['billing.portal.view-own'],
                'parent.guardian' => ['billing.portal.view-own'],
            ];
            foreach (DB::table('roles')->where('scope', 'tenant')->where('is_system', true)->get(['id', 'key']) as $role) {
                foreach ($grants[$role->key] ?? [] as $key) {
                    $permissionId = $ids[$key] ?? DB::table('permissions')->where('key', $key)->value('id');
                    if ($permissionId !== null) {
                        DB::table('permission_role')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Additive permission/grant backfills are not reversed after deployment.
    }
};
