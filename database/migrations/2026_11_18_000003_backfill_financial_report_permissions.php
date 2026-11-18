<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = ['billing.reports.view', 'billing.reports.export', 'billing.reports.drilldown'];

    public function up(): void
    {
        DB::transaction(function (): void {
            $catalog = PermissionCatalog::tenantPermissions();
            $now = now();
            $rows = [];
            foreach (self::PERMISSIONS as $key) {
                $rows[] = [
                    'key' => $key,
                    'description' => $catalog[$key] ?? throw new RuntimeException("Financial report permission {$key} is missing from PermissionCatalog."),
                    'scope' => 'tenant',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('permissions')->upsert($rows, ['key'], ['description', 'scope', 'updated_at']);
            $ids = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id', 'key');

            foreach (DB::table('roles')->where('scope', 'tenant')->where('is_system', true)->get(['id', 'key']) as $role) {
                $keys = match ($role->key) {
                    'tenant.owner', 'tenant.admin' => self::PERMISSIONS,
                    'finance.manager' => self::PERMISSIONS,
                    default => [],
                };
                foreach ($keys as $key) {
                    DB::table('permission_role')->insertOrIgnore([
                        'role_id' => $role->id,
                        'permission_id' => $ids[$key],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Grants are intentionally additive and are not revoked automatically on rollback.
    }
};
