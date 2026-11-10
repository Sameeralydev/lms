<?php

namespace App\Domain\Tenancy;

use App\Domain\Academic\AcademicDefaults;
use App\Domain\Admissions\AdmissionsDefaults;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\PermissionCatalog;
use App\Models\Institution;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantInvitation;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TenantProvisioner
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $details */
    public function provision(array $details, User $actor, string $ownerEmail): TenantProvisioningResult
    {
        return DB::transaction(function () use ($details, $actor, $ownerEmail): TenantProvisioningResult {
            $baseSlug = substr(Str::slug((string) ($details['slug'] ?: $details['official_name'])) ?: 'institution-'.Str::lower(Str::random(8)), 0, 100);
            $slug = $baseSlug;
            $suffix = 2;
            while (Tenant::query()->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$suffix++;
            }

            $tenant = Tenant::query()->create([
                'public_id' => (string) Str::uuid(),
                'name' => $details['display_name'] ?: $details['official_name'],
                'slug' => $slug,
                'status' => 'provisioning',
                'created_by' => $actor->getKey(),
            ]);
            $this->context->enterProvisioning($tenant);

            try {
                $institution = Institution::query()->create([
                    'tenant_id' => $tenant->getKey(),
                    'official_name' => $details['official_name'],
                    'display_name' => $details['display_name'] ?: $details['official_name'],
                    'code' => $details['code'] ?: null,
                    'type' => $details['type'],
                    'contact_email' => $details['contact_email'] ?: null,
                    'contact_phone' => $details['contact_phone'] ?: null,
                    'website' => $details['website'] ?: null,
                    'country' => $details['country'] ?: null,
                    'region' => $details['region'] ?: null,
                    'city' => $details['city'] ?: null,
                    'address' => $details['address'] ?: null,
                    'timezone' => $details['timezone'] ?: 'UTC',
                    'locale' => $details['locale'] ?: 'en',
                    'status' => 'active',
                ]);

                AcademicDefaults::provision($tenant, $institution, (int) $actor->getKey());
                AdmissionsDefaults::provision($tenant, $institution, (int) $actor->getKey());

                PermissionCatalog::createTenantRoleTemplates((int) $tenant->getKey());
                $ownerRole = Role::query()->where('tenant_id', $tenant->getKey())->where('key', 'tenant.owner')->firstOrFail();
                $rawToken = Str::random(64);
                $ownerInvitation = TenantInvitation::query()->create([
                    'tenant_id' => $tenant->getKey(),
                    'institution_id' => $institution->getKey(),
                    'invited_by' => $actor->getKey(),
                    'email' => Str::lower(trim($ownerEmail)),
                    'role_key' => $ownerRole->key,
                    'token_hash' => hash('sha256', $rawToken),
                    'status' => 'pending',
                    'expires_at' => now()->addDays(7),
                ]);

                TenantBranding::query()->create([
                    'tenant_id' => $tenant->getKey(),
                    'display_name' => $institution->display_name,
                ]);
                foreach ([
                    'date_format' => ['value' => 'Y-m-d'],
                    'default_campus_id' => ['value' => null],
                ] as $key => $value) {
                    TenantSetting::query()->create(['tenant_id' => $tenant->getKey(), 'key' => $key, 'value' => $value]);
                }

                $tenant->forceFill(['status' => 'active'])->save();
                $this->audit->record('membership.invited', $actor, (int) $tenant->getKey(), $ownerInvitation, ['role_key' => $ownerRole->key]);
                $this->audit->record('tenant.provisioned', $actor, (int) $tenant->getKey(), $tenant, ['slug' => $tenant->slug, 'institution_id' => $institution->getKey()]);
            } finally {
                $this->context->clear();
            }

            return new TenantProvisioningResult($tenant, $ownerInvitation, $rawToken);
        });
    }
}
