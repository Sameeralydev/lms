<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSION = 'academic.records.gpa.manage';

    public function up(): void
    {
        DB::transaction(function (): void {
            $catalog = PermissionCatalog::tenantPermissions();
            DB::table('permissions')->updateOrInsert(['key' => self::PERMISSION], ['description' => $catalog[self::PERMISSION], 'scope' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
            $permissionId = DB::table('permissions')->where('key', self::PERMISSION)->value('id');
            if ($permissionId === null) {
                throw new RuntimeException('The GPA management permission was not created.');
            }
            $grants = [];
            foreach (DB::table('roles')->where('scope', 'tenant')->whereIn('key', ['tenant.owner', 'tenant.admin', 'academic.admin', 'sis.registrar'])->get(['id', 'key']) as $role) {
                $grants[] = ['role_id' => $role->id, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()];
            }
            if ($grants !== []) {
                DB::table('permission_role')->insertOrIgnore($grants);
            }
        });
    }

    public function down(): void
    {
        // Additive grants are retained during rollback to avoid revoking live tenant roles.
    }
};
