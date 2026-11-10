<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $permissions = [
        'enrollment.registrar.view' => 'View institution course offerings, enrollment requests and rosters.',
        'enrollment.registrar.manage' => 'Manage enrollment policies, offerings, sections and registrar decisions.',
        'enrollment.instructor.roster.view' => 'View rosters for explicitly assigned course sections.',
        'enrollment.student.view' => 'View course registration options and the member’s own enrollment history.',
        'enrollment.student.register' => 'Register for eligible course sections and manage own registration.',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $rows = [];
            foreach ($this->permissions as $key => $description) {
                $rows[] = ['key' => $key, 'description' => $description, 'scope' => 'tenant', 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('permissions')->upsert($rows, ['key'], ['description', 'scope', 'updated_at']);
            $ids = DB::table('permissions')->whereIn('key', array_keys($this->permissions))->pluck('id', 'key');
            foreach (DB::table('tenants')->pluck('id') as $tenantId) {
                $grants = [
                    'tenant.owner' => array_keys($this->permissions),
                    'tenant.admin' => array_keys($this->permissions),
                    'academic.admin' => ['enrollment.registrar.view', 'enrollment.registrar.manage'],
                    'sis.registrar' => ['enrollment.registrar.view', 'enrollment.registrar.manage'],
                    'teacher' => ['enrollment.instructor.roster.view'],
                    'student' => ['enrollment.student.view', 'enrollment.student.register'],
                ];
                foreach ($grants as $roleKey => $permissionKeys) {
                    $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('key', $roleKey)->where('scope', 'tenant')->where('is_system', true)->value('id');
                    if ($roleId === null) {
                        continue;
                    }
                    $pivot = [];
                    foreach ($permissionKeys as $permissionKey) {
                        $permissionId = $ids->get($permissionKey);
                        if ($permissionId !== null) {
                            $pivot[] = ['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now];
                        }
                    }
                    if ($pivot !== []) {
                        DB::table('permission_role')->insertOrIgnore($pivot);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // This additive permission backfill is intentionally not reversed to avoid revoking grants changed after deployment.
    }
};
