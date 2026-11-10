<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            DB::table('permissions')->updateOrInsert(
                ['key' => 'sis.documents.manage'],
                ['description' => 'Upload and replace private student documents.', 'scope' => 'tenant', 'updated_at' => $now, 'created_at' => $now],
            );
            $permissionId = DB::table('permissions')->where('key', 'sis.documents.manage')->value('id');
            if ($permissionId === null) {
                return;
            }

            foreach (DB::table('roles')->whereIn('key', ['tenant.owner', 'tenant.admin', 'sis.registrar'])->pluck('id') as $roleId) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'sis.documents.manage')->delete();
    }
};
