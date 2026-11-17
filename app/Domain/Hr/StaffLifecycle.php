<?php

namespace App\Domain\Hr;

use App\Domain\Audit\AuditRecorder;
use App\Models\HrStaffEmploymentHistory;
use App\Models\HrStaffRecord;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StaffLifecycle
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $attributes */
    public function hire(User $actor, TenantMembership $membership, array $attributes): HrStaffRecord
    {
        return DB::transaction(function () use ($actor, $membership, $attributes): HrStaffRecord {
            $record = HrStaffRecord::query()->create([
                'institution_id' => $membership->institution_id,
                'membership_id' => $membership->getKey(),
                'employee_number' => $attributes['employee_number'],
                'designation' => $attributes['designation'],
                'employment_type' => $attributes['employment_type'],
                'status' => 'active',
                'joined_on' => $attributes['joined_on'],
                'campus_id' => $attributes['campus_id'] ?? null,
                'academic_unit_id' => $attributes['academic_unit_id'] ?? null,
                'manager_id' => $attributes['manager_id'] ?? null,
                'personal_email' => $attributes['personal_email'] ?? null,
                'personal_phone' => $attributes['personal_phone'] ?? null,
                'emergency_contact_name' => $attributes['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $attributes['emergency_contact_phone'] ?? null,
            ]);

            HrStaffEmploymentHistory::query()->create([
                'staff_record_id' => $record->getKey(),
                'recorded_by' => $actor->getKey(),
                'event_type' => 'created',
                'to_status' => 'active',
                'to_campus_id' => $record->campus_id,
                'to_academic_unit_id' => $record->academic_unit_id,
                'to_designation' => $record->designation,
                'to_employment_type' => $record->employment_type,
                'to_manager_id' => $record->manager_id,
                'effective_on' => $record->joined_on,
            ]);
            $this->audit->record('hr.staff.created', $actor, (int) $record->tenant_id, $record, [
                'employee_number' => $record->employee_number,
                'membership_id' => $membership->getKey(),
            ]);

            return $record;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, HrStaffRecord $record, array $attributes): HrStaffRecord
    {
        return DB::transaction(function () use ($actor, $record, $attributes): HrStaffRecord {
            $record = HrStaffRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if (isset($attributes['manager_id'])) {
                $this->assertNoReportingCycle($record, (int) $attributes['manager_id']);
            }
            $before = $record->only(['campus_id', 'academic_unit_id', 'designation', 'manager_id', 'employment_type', 'personal_email', 'personal_phone', 'emergency_contact_name', 'emergency_contact_phone']);
            $changedFields = [];
            foreach ($attributes as $key => $value) {
                if ($record->getAttribute($key) != $value) {
                    $changedFields[] = $key;
                }
            }
            $record->fill($attributes)->save();
            $changed = $record->only(['campus_id', 'academic_unit_id', 'designation', 'manager_id', 'employment_type', 'personal_email', 'personal_phone', 'emergency_contact_name', 'emergency_contact_phone']) !== $before;
            if ($changed) {
                HrStaffEmploymentHistory::query()->create([
                    'staff_record_id' => $record->getKey(),
                    'recorded_by' => $actor->getKey(),
                    'event_type' => 'updated',
                    'from_campus_id' => $before['campus_id'],
                    'to_campus_id' => $record->campus_id,
                    'from_academic_unit_id' => $before['academic_unit_id'],
                    'to_academic_unit_id' => $record->academic_unit_id,
                    'from_designation' => $before['designation'],
                    'to_designation' => $record->designation,
                    'from_employment_type' => $before['employment_type'],
                    'to_employment_type' => $record->employment_type,
                    'from_manager_id' => $before['manager_id'],
                    'to_manager_id' => $record->manager_id,
                    'effective_on' => now()->toDateString(),
                ]);
                $this->audit->record('hr.staff.updated', $actor, (int) $record->tenant_id, $record, [
                    'employee_number' => $record->employee_number,
                    'changed_fields' => $changedFields,
                ]);
            }

            return $record->refresh();
        });
    }

    public function changeStatus(User $actor, HrStaffRecord $record, string $status, string $effectiveOn, ?string $reason): HrStaffRecord
    {
        return DB::transaction(function () use ($actor, $record, $status, $effectiveOn, $reason): HrStaffRecord {
            $record = HrStaffRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($record->status === $status) {
                throw ValidationException::withMessages(['status' => 'Choose a different employment status.']);
            }
            if ($record->joined_on->toDateString() > $effectiveOn) {
                throw ValidationException::withMessages(['effective_on' => 'The effective date cannot be before the employee’s joining date.']);
            }
            if ($record->status === 'separated' && $status !== 'active') {
                throw ValidationException::withMessages(['status' => 'Separated staff may only be reactivated.']);
            }

            $previous = $record->status;
            $record->forceFill([
                'status' => $status,
                'separated_on' => $status === 'separated' ? $effectiveOn : null,
            ])->save();
            HrStaffEmploymentHistory::query()->create([
                'staff_record_id' => $record->getKey(),
                'recorded_by' => $actor->getKey(),
                'event_type' => $status === 'active' && $previous === 'separated' ? 'reactivated' : 'status_changed',
                'from_status' => $previous,
                'to_status' => $status,
                'effective_on' => $effectiveOn,
                'reason' => $reason,
            ]);
            $this->audit->record('hr.staff.status_changed', $actor, (int) $record->tenant_id, $record, [
                'employee_number' => $record->employee_number,
                'from_status' => $previous,
                'to_status' => $status,
                'effective_on' => $effectiveOn,
            ]);

            return $record->refresh();
        });
    }

    private function assertNoReportingCycle(HrStaffRecord $record, int $managerId): void
    {
        $seen = [(int) $record->getKey() => true];
        $cursor = $managerId;
        for ($depth = 0; $cursor > 0 && $depth < 100; $depth++) {
            if (isset($seen[$cursor])) {
                throw ValidationException::withMessages(['manager_id' => 'This reporting assignment would create a reporting cycle.']);
            }
            $seen[$cursor] = true;
            $cursor = (int) (HrStaffRecord::query()->whereKey($cursor)->value('manager_id') ?? 0);
        }
        if ($cursor > 0) {
            throw ValidationException::withMessages(['manager_id' => 'The reporting chain is too deep to validate safely.']);
        }
    }
}
