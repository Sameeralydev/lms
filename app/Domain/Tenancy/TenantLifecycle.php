<?php

namespace App\Domain\Tenancy;

use App\Domain\Audit\AuditRecorder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TenantLifecycle
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function transition(Tenant $tenant, string $target, User $actor, ?string $reason = null): Tenant
    {
        return DB::transaction(function () use ($tenant, $target, $actor, $reason): Tenant {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->getKey());
            $from = $locked->status;
            $allowed = ['active' => ['suspended', 'archived'], 'suspended' => ['active', 'archived'], 'archived' => []];
            if (! in_array($target, $allowed[$from] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'This tenant status transition is not allowed.']);
            }

            $locked->forceFill(['status' => $target, 'suspension_reason' => $target === 'suspended' ? $reason : null])->save();
            $this->audit->record('tenant.status_changed', $actor, (int) $locked->getKey(), $locked, ['from' => $from, 'to' => $target]);

            return $locked;
        });
    }
}
