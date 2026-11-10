<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('key', 'academic.view')->value('id');
        if ($permissionId === null) {
            throw new RuntimeException('The academic.view permission must exist before teacher workspace access is granted.');
        }
        foreach (DB::table('roles')->where('scope', 'tenant')->where('key', 'teacher')->get(['id', 'tenant_id']) as $role) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // This grants baseline academic workspace visibility required by the teacher role.
    }
};
