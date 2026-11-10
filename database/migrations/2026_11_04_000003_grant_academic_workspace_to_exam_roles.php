<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROLE_KEYS = ['exam.controller', 'exam.invigilator', 'exam.moderator', 'examiner'];

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('key', 'academic.view')->value('id');
        if ($permissionId === null) {
            throw new RuntimeException('The academic.view permission is required before examination workspace access can be granted.');
        }

        $now = now();
        DB::table('roles')->where('scope', 'tenant')->whereIn('key', self::ROLE_KEYS)->orderBy('id')->chunkById(100, function ($roles) use ($permissionId, $now): void {
            foreach ($roles as $role) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('key', 'academic.view')->value('id');
        if ($permissionId === null) {
            return;
        }

        $roleIds = DB::table('roles')->where('scope', 'tenant')->whereIn('key', self::ROLE_KEYS)->pluck('id');
        DB::table('permission_role')->where('permission_id', $permissionId)->whereIn('role_id', $roleIds)->delete();
    }
};
