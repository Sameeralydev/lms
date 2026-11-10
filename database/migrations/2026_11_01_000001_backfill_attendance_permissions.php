<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = array_filter(PermissionCatalog::tenantPermissions(), static fn (string $key): bool => str_starts_with($key, 'attendance.'), ARRAY_FILTER_USE_KEY);
        $now = now();
        foreach ($permissions as $key => $description) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['description' => $description, 'scope' => 'tenant', 'updated_at' => $now, 'created_at' => $now]);
        }

        $permissionIds = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id', 'key');
        $roleKeys = [
            'tenant.owner' => array_keys($permissions),
            'tenant.admin' => array_keys($permissions),
            'academic.admin' => ['attendance.view', 'attendance.policy.manage', 'attendance.register.view', 'attendance.register.mark', 'attendance.register.submit', 'attendance.register.override', 'attendance.justification.review', 'attendance.correction.review', 'attendance.report.view', 'attendance.export'],
            'teacher' => ['attendance.view', 'attendance.register.view', 'attendance.register.mark', 'attendance.register.submit', 'attendance.correction.create'],
            'student' => ['attendance.student.view', 'attendance.justification.create'],
            'parent.guardian' => ['attendance.guardian.view'],
            'sis.registrar' => ['attendance.view', 'attendance.policy.manage', 'attendance.register.view', 'attendance.register.mark', 'attendance.register.submit', 'attendance.register.override', 'attendance.justification.review', 'attendance.correction.review', 'attendance.report.view', 'attendance.export'],
            'auditor' => ['attendance.view', 'attendance.register.view', 'attendance.report.view'],
        ];
        foreach ($roleKeys as $roleKey => $keys) {
            $rows = DB::table('roles')->where('key', $roleKey)->whereNotNull('tenant_id')->pluck('id');
            foreach ($rows as $roleId) {
                $grants = [];
                foreach ($keys as $key) {
                    if (isset($permissionIds[$key])) {
                        $grants[] = ['role_id' => $roleId, 'permission_id' => $permissionIds[$key], 'created_at' => $now, 'updated_at' => $now];
                    }
                }
                if ($grants !== []) {
                    DB::table('permission_role')->insertOrIgnore($grants);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('key', 'like', 'attendance.%')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
