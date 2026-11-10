<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $permissions = [
        'admissions.view' => 'View admissions workspace and operational records.',
        'admissions.settings.manage' => 'Configure institution admissions settings and policy.',
        'admissions.intakes.manage' => 'Create and manage admissions campaigns and intakes.',
        'admissions.forms.manage' => 'Build and publish versioned admissions forms.',
        'admissions.inquiries.manage' => 'Review and qualify admissions inquiries.',
        'admissions.applications.view' => 'View applications within assigned tenant and campus scope.',
        'admissions.applications.review' => 'Review assigned admissions applications.',
        'admissions.documents.review' => 'Review and verify submitted admissions documents.',
        'admissions.assessments.manage' => 'Schedule and record admissions assessments.',
        'admissions.eligibility.manage' => 'Evaluate applicant eligibility and record human review.',
        'admissions.merit.manage' => 'Calculate and manage versioned admissions merit lists.',
        'admissions.decisions.recommend' => 'Recommend admissions decisions for independent approval.',
        'admissions.decisions.approve' => 'Approve admissions decisions.',
        'admissions.offers.manage' => 'Issue and manage admissions offers.',
        'admissions.waitlist.manage' => 'Manage waitlist ordering and promotion.',
        'admissions.fees.manage' => 'Manage application fee obligations and authorized verification.',
        'admissions.handoff.manage' => 'Create and process accepted-applicant SIS handoffs.',
        'admissions.audit.view' => 'View admissions audit history.',
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
            $allIds = $ids->values()->all();
            $readOnlyIds = $ids->only(['admissions.view', 'admissions.audit.view'])->values()->all();
            $operatorIds = $ids->reject(fn ($id, $key): bool => in_array($key, ['admissions.settings.manage', 'admissions.decisions.approve', 'admissions.audit.view'], true))->values()->all();

            foreach (DB::table('tenants')->pluck('id') as $tenantId) {
                foreach ([
                    'tenant.owner' => $allIds,
                    'tenant.admin' => $allIds,
                    'admissions.officer' => $operatorIds,
                    'auditor' => $readOnlyIds,
                ] as $roleKey => $permissionIds) {
                    $roleId = DB::table('roles')
                        ->where('tenant_id', $tenantId)
                        ->where('key', $roleKey)
                        ->where('scope', 'tenant')
                        ->where('is_system', true)
                        ->value('id');
                    if ($roleId === null) {
                        continue;
                    }
                    $grants = array_map(static fn (int $permissionId): array => [
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $permissionIds);
                    if ($grants !== []) {
                        DB::table('permission_role')->insertOrIgnore($grants);
                    }
                }
            }

            foreach (DB::table('institutions')->select(['id', 'tenant_id', 'timezone', 'locale'])->get() as $institution) {
                DB::table('admissions_settings')->insertOrIgnore([
                    'tenant_id' => $institution->tenant_id,
                    'institution_id' => $institution->id,
                    'enabled' => false,
                    'inquiries_enabled' => false,
                    'public_applications_enabled' => false,
                    'registration_mode' => 'existing_account',
                    'timezone' => $institution->timezone ?: 'UTC',
                    'locale' => $institution->locale ?: 'en',
                    'policy' => json_encode([], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Additive permission grants are intentionally retained on rollback.
        // Admissions data is not automatically deleted on application rollback.
    }
};
