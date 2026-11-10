<?php

namespace App\Domain\Academic;

use App\Domain\Audit\AuditRecorder;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicCalendar
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function transitionYear(AcademicYear $year, string $target, User $actor): void
    {
        DB::transaction(function () use ($year, $target, $actor): void {
            Institution::query()->lockForUpdate()->findOrFail($year->institution_id);
            $locked = AcademicYear::query()->lockForUpdate()->findOrFail($year->getKey());
            $allowed = ['draft' => ['active', 'archived'], 'active' => ['completed'], 'completed' => ['archived'], 'archived' => []];
            if (! in_array($target, $allowed[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'This academic year status transition is not allowed.']);
            }
            if ($target === 'active') {
                $overlap = AcademicYear::query()->where('institution_id', $locked->institution_id)->where('status', 'active')
                    ->where('starts_on', '<=', $locked->ends_on)->where('ends_on', '>=', $locked->starts_on)
                    ->when($locked->campus_id !== null, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('campus_id')->orWhere('campus_id', $locked->campus_id)))
                    ->where('id', '!=', $locked->getKey())->exists();
                if ($overlap) {
                    throw ValidationException::withMessages(['starts_on' => 'An active academic year overlaps this date range for the selected institution or campus.']);
                }
            }
            $locked->forceFill(['status' => $target, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record('academic.year.status_changed', $actor, (int) $locked->tenant_id, $locked, ['status' => $target]);
        });
    }

    public function setCurrentYear(AcademicYear $year, User $actor): void
    {
        if ($year->status !== 'active') {
            throw ValidationException::withMessages(['year' => 'Only an active academic year can be designated current.']);
        }
        DB::transaction(function () use ($year, $actor): void {
            Institution::query()->lockForUpdate()->findOrFail($year->institution_id);
            AcademicYear::query()->where('institution_id', $year->institution_id)->where('campus_id', $year->campus_id)->lockForUpdate()->get();
            AcademicYear::query()->where('institution_id', $year->institution_id)->where('campus_id', $year->campus_id)->update(['is_current' => false]);
            $year->forceFill(['is_current' => true, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record('academic.year.current_changed', $actor, (int) $year->tenant_id, $year, ['campus_id' => $year->campus_id]);
        });
    }

    public function transitionPeriod(AcademicPeriod $period, string $target, User $actor): void
    {
        DB::transaction(function () use ($period, $target, $actor): void {
            $locked = AcademicPeriod::query()->lockForUpdate()->findOrFail($period->getKey());
            $allowed = ['draft' => ['active', 'archived'], 'active' => ['completed'], 'completed' => ['archived'], 'archived' => []];
            if (! in_array($target, $allowed[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'This period status transition is not allowed.']);
            }
            if ($target === 'active' && $locked->academicYear()->value('status') !== 'active') {
                throw ValidationException::withMessages(['status' => 'A period can be activated only after its academic year is active.']);
            }
            $locked->forceFill(['status' => $target, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record('academic.period.status_changed', $actor, (int) $locked->tenant_id, $locked, ['status' => $target]);
        });
    }

    public function setCurrentPeriod(AcademicPeriod $period, User $actor): void
    {
        if ($period->status !== 'active') {
            throw ValidationException::withMessages(['period' => 'Only an active period can be designated current.']);
        }
        if ($period->academicYear()->value('status') !== 'active') {
            throw ValidationException::withMessages(['period' => 'A current period must belong to an active academic year.']);
        }
        DB::transaction(function () use ($period, $actor): void {
            AcademicPeriod::query()->where('academic_year_id', $period->academic_year_id)->lockForUpdate()->get();
            AcademicPeriod::query()->where('academic_year_id', $period->academic_year_id)->update(['is_current' => false]);
            $period->forceFill(['is_current' => true, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record('academic.period.current_changed', $actor, (int) $period->tenant_id, $period);
        });
    }
}
