<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = ['academic.analytics.view', 'academic.analytics.export'];

    /** @var array<string, list<string>> */
    private const ROLE_GRANTS = [
        'tenant.owner' => self::PERMISSIONS,
        'tenant.admin' => self::PERMISSIONS,
        'academic.admin' => self::PERMISSIONS,
        'sis.registrar' => self::PERMISSIONS,
        'campus.admin' => self::PERMISSIONS,
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $catalog = PermissionCatalog::tenantPermissions();
            $rows = [];
            foreach (self::PERMISSIONS as $key) {
                if (! isset($catalog[$key])) {
                    throw new RuntimeException("The academic analytics permission {$key} is missing from PermissionCatalog.");
                }
                $rows[] = ['key' => $key, 'description' => $catalog[$key], 'scope' => 'tenant', 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('permissions')->upsert($rows, ['key'], ['description', 'scope', 'updated_at']);
            $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id', 'key');

            foreach (DB::table('roles')->where('scope', 'tenant')->where('is_system', true)->get(['id', 'key']) as $role) {
                $grants = [];
                foreach (self::ROLE_GRANTS[$role->key] ?? [] as $key) {
                    $permissionId = $permissionIds[$key] ?? null;
                    if ($permissionId !== null) {
                        $grants[] = ['role_id' => $role->id, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()];
                    }
                }
                if ($grants !== []) {
                    DB::table('permission_role')->insertOrIgnore($grants);
                }
            }
        });
    }

    public function down(): void
    {
        // This additive permission backfill is intentionally not reversed to avoid revoking grants changed after deployment.
    }
};
