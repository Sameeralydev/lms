<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'academic.grades.policy.manage',
        'academic.grades.book.view',
        'academic.grades.book.manage',
        'academic.grades.book.manage-all',
        'academic.grades.submit',
        'academic.grades.review',
        'academic.grades.publish',
        'academic.grades.view-own',
    ];

    /** @var array<string, list<string>> */
    private const ROLE_GRANTS = [
        'tenant.owner' => self::PERMISSIONS,
        'tenant.admin' => self::PERMISSIONS,
        'academic.admin' => self::PERMISSIONS,
        'campus.admin' => ['academic.grades.policy.manage', 'academic.grades.book.view', 'academic.grades.book.manage-all', 'academic.grades.review', 'academic.grades.publish'],
        'teacher' => ['academic.grades.book.view', 'academic.grades.book.manage', 'academic.grades.submit'],
        'sis.registrar' => ['academic.grades.book.view', 'academic.grades.review', 'academic.grades.publish'],
        'student' => ['academic.grades.view-own'],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $catalog = PermissionCatalog::tenantPermissions();
            $now = now();
            $rows = [];
            foreach (self::PERMISSIONS as $key) {
                if (! isset($catalog[$key])) {
                    throw new RuntimeException("The grading permission {$key} is missing from the permission catalog.");
                }
                $rows[] = ['key' => $key, 'description' => $catalog[$key], 'scope' => 'tenant', 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('permissions')->upsert($rows, ['key'], ['description', 'scope', 'updated_at']);
            $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id', 'key');

            foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
                foreach (self::ROLE_GRANTS as $roleKey => $permissionKeys) {
                    $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('scope', 'tenant')->where('key', $roleKey)->value('id');
                    if ($roleId === null) {
                        continue;
                    }
                    $grants = [];
                    foreach ($permissionKeys as $key) {
                        $permissionId = $permissionIds[$key] ?? null;
                        if ($permissionId !== null) {
                            $grants[] = ['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now];
                        }
                    }
                    if ($grants !== []) {
                        DB::table('permission_role')->insertOrIgnore($grants);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Permission grants are additive; rollback does not revoke institutional capabilities.
    }
};
