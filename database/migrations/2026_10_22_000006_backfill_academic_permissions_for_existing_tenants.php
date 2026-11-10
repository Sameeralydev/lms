<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $permissions = [
        'academic.view' => 'View academic configuration and dashboard.',
        'academic.settings.manage' => 'Update the academic operating model and terminology.',
        'academic.calendar.manage' => 'Manage academic years and lifecycle.',
        'academic.periods.manage' => 'Manage teaching periods and dates.',
        'academic.structure.manage' => 'Manage academic units, levels, and qualifications.',
        'academic.programs.manage' => 'Manage academic program definitions.',
        'academic.classes.manage' => 'Manage class, section, and cohort structures.',
        'academic.catalog.manage' => 'Manage subject and course definitions.',
        'academic.credit.manage' => 'Manage versioned credit-hour policies.',
        'academic.regional.manage' => 'Manage optional jurisdiction policy profiles and evidence.',
        'academic.outcomes.manage' => 'Manage learning-outcome references.',
        'academic.quality.manage' => 'Manage educational quality objectives, reviews, and actions.',
        'academic.documents.manage' => 'Manage controlled academic documents.',
        'academic.policy.approve' => 'Approve academic policy, course, and quality versions.',
        'academic.audit.view' => 'View academic audit history.',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $permissionRows = [];
            foreach ($this->permissions as $key => $description) {
                $permissionRows[] = [
                    'description' => $description,
                    'scope' => 'tenant',
                    'updated_at' => $now,
                    'created_at' => $now,
                    'key' => $key,
                ];
            }
            DB::table('permissions')->upsert($permissionRows, ['key'], ['description', 'scope', 'updated_at']);

            $permissionIds = DB::table('permissions')->whereIn('key', array_keys($this->permissions))->pluck('id', 'key');
            $allAcademicIds = $permissionIds->values()->all();
            $readOnlyIds = $permissionIds->only(['academic.view', 'academic.audit.view'])->values()->all();

            foreach (DB::table('tenants')->pluck('id') as $tenantId) {
                foreach ([
                    'tenant.owner' => $allAcademicIds,
                    'tenant.admin' => $allAcademicIds,
                    'academic.admin' => $allAcademicIds,
                    'auditor' => $readOnlyIds,
                ] as $roleKey => $ids) {
                    $roleId = DB::table('roles')
                        ->where('tenant_id', $tenantId)
                        ->where('key', $roleKey)
                        ->where('scope', 'tenant')
                        ->where('is_system', true)
                        ->value('id');

                    if ($roleId === null) {
                        continue;
                    }

                    $rows = array_map(static fn (int $permissionId): array => [
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $ids);

                    if ($rows !== []) {
                        DB::table('permission_role')->insertOrIgnore($rows);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // This is an additive permission backfill. Removing grants on rollback could revoke intentional access.
    }
};
