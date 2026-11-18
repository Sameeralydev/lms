<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $key = 'academic.analytics.drilldown';
        $description = PermissionCatalog::tenantPermissions()[$key] ?? throw new RuntimeException('Analytics drill-down permission is missing from the catalog.');
        DB::transaction(function () use ($key, $description): void {
            DB::table('permissions')->upsert([['key' => $key, 'description' => $description, 'scope' => 'tenant', 'created_at' => now(), 'updated_at' => now()]], ['key'], ['description', 'scope', 'updated_at']);
            $permissionId = DB::table('permissions')->where('key', $key)->value('id');
            $roleIds = DB::table('roles')->where('scope', 'tenant')->where('is_system', true)->whereIn('key', ['tenant.owner', 'tenant.admin', 'academic.admin', 'sis.registrar', 'campus.admin'])->pluck('id');
            $rows = $roleIds->map(static fn ($roleId): array => ['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()])->all();
            if ($rows !== []) {
                DB::table('permission_role')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        // Permission grants are additive and are not revoked by rollback.
    }
};
