<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $permissions = [
        'sis.view' => 'View student registry and authorized profiles.',
        'sis.students.create' => 'Register students and process approved admissions handoffs.',
        'sis.students.update' => 'Update permitted student profile information.',
        'sis.students.status' => 'Change student lifecycle status with a recorded reason.',
        'sis.placement.manage' => 'Place, promote and transfer students within the institution.',
        'sis.guardians.manage' => 'Manage student guardian relationships and authority.',
        'sis.guardians.verify' => 'Verify institution-managed guardian relationships.',
        'sis.family.manage' => 'Manage verified institution-specific family associations.',
        'sis.custom-fields.manage' => 'Configure institution-specific student fields.',
        'sis.custom-fields.sensitive' => 'Configure and view sensitive custom student attributes.',
        'sis.documents.view' => 'View authorized, scanned student document references.',
        'sis.documents.manage' => 'Upload and replace private student documents.',
        'sis.import' => 'Preview and confirm student registry CSV imports.',
        'sis.export' => 'Export the permitted student directory fields.',
        'sis.audit.view' => 'View student lifecycle and registry history.',
        'sis.settings.manage' => 'Configure institution student labels and registry numbering.',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            foreach ($this->permissions as $key => $description) {
                DB::table('permissions')->updateOrInsert(['key' => $key], ['description' => $description, 'scope' => 'tenant', 'updated_at' => $now, 'created_at' => $now]);
            }
            $permissionIds = DB::table('permissions')->whereIn('key', array_keys($this->permissions))->pluck('id', 'key');

            foreach (DB::table('tenants')->pluck('id') as $tenantId) {
                $tenantId = (int) $tenantId;
                foreach (['tenant.owner', 'tenant.admin'] as $roleKey) {
                    $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('key', $roleKey)->value('id');
                    if ($roleId === null) {
                        continue;
                    }
                    foreach ($permissionIds as $permissionId) {
                        DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
                    }
                }

                $registrarId = DB::table('roles')->where('tenant_id', $tenantId)->where('key', 'sis.registrar')->value('id');
                if ($registrarId === null) {
                    $registrarId = DB::table('roles')->insertGetId([
                        'tenant_id' => $tenantId, 'key' => 'sis.registrar', 'name' => 'Student Registrar', 'scope' => 'tenant',
                        'description' => 'Manage currently implemented student registry workflows.', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                foreach ($permissionIds->except(['sis.custom-fields.sensitive', 'sis.settings.manage']) as $permissionId) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $registrarId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
                }
            }

            foreach (DB::table('academic_configurations')->select(['id', 'enabled_features'])->get() as $configuration) {
                $features = json_decode((string) $configuration->enabled_features, true);
                if (! is_array($features)) {
                    $features = [];
                } elseif (array_is_list($features)) {
                    $features = array_fill_keys(array_filter($features, 'is_string'), true);
                }
                if (! array_key_exists('student_information', $features)) {
                    $features['student_information'] = true;
                    DB::table('academic_configurations')->where('id', $configuration->id)->update(['enabled_features' => json_encode($features), 'updated_at' => $now]);
                }
            }
        });
    }

    public function down(): void
    {
        // Forward-only: permission templates and feature flags may have been changed after rollout.
    }
};
