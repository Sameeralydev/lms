<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const ROLE_KEYS = ['tenant.owner', 'tenant.admin', 'academic.admin', 'sis.registrar', 'campus.admin'];

    public function up(): void
    {
        DB::transaction(function (): void {
            $key = 'academic.analytics.drilldown';
            $description = PermissionCatalog::tenantPermissions()[$key]
                ?? throw new RuntimeException('Academic analytics drill-down permission is missing from PermissionCatalog.');

            DB::table('permissions')->insertOrIgnore([
                'key' => $key,
                'description' => $description,
                'scope' => 'tenant',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $permissionId = DB::table('permissions')->where('key', $key)->value('id');
            $roleIds = DB::table('roles')
                ->where('scope', 'tenant')
                ->where('is_system', true)
                ->whereIn('key', self::ROLE_KEYS)
                ->pluck('id');

            $grants = $roleIds->map(static fn (int $roleId): array => [
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            if ($grants !== []) {
                DB::table('permission_role')->insertOrIgnore($grants);
            }
        });
    }

    public function down(): void
    {
        // This additive grant is intentionally retained on rollback to avoid revoking changed access.
    }
};
