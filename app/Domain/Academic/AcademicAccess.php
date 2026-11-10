<?php

namespace App\Domain\Academic;

use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicClass;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\AcademicUnit;
use App\Models\AcademicYear;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AcademicAccess
{
    public function __construct(private readonly TenantContext $context) {}

    public function campusId(): ?int
    {
        $campusId = $this->context->membership()->campus_id;

        return $campusId === null ? null : (int) $campusId;
    }

    public function isCampusScoped(): bool
    {
        return $this->campusId() !== null;
    }

    public function assertInstitutionWide(): void
    {
        if ($this->isCampusScoped()) {
            throw new NotFoundHttpException;
        }
    }

    public function assertCampus(?int $campusId): void
    {
        $assignedCampusId = $this->campusId();
        if ($assignedCampusId !== null && ($campusId === null || $campusId !== $assignedCampusId)) {
            throw new NotFoundHttpException;
        }
    }

    /** @param list<int|string> $campusIds */
    public function assertCampusAssignments(array $campusIds): void
    {
        $assignedCampusId = $this->campusId();
        $normalized = array_values(array_unique(array_map('intval', $campusIds)));
        if ($assignedCampusId !== null && $normalized !== [$assignedCampusId]) {
            throw new NotFoundHttpException;
        }
    }

    /** @param Builder<AcademicYear> $query
     * @return Builder<AcademicYear>
     */
    public function scopeYears(Builder $query): Builder
    {
        return $query->when($this->isCampusScoped(), fn (Builder $years) => $years->where('campus_id', $this->campusId()));
    }

    /** @param Builder<AcademicUnit> $query
     * @return Builder<AcademicUnit>
     */
    public function scopeUnits(Builder $query): Builder
    {
        return $query->when($this->isCampusScoped(), fn (Builder $units) => $units->whereHas('campuses', fn (Builder $campuses) => $campuses->whereKey($this->campusId())));
    }

    /** @param Builder<AcademicProgram> $query
     * @return Builder<AcademicProgram>
     */
    public function scopePrograms(Builder $query): Builder
    {
        return $query->when($this->isCampusScoped(), fn (Builder $programs) => $programs->whereHas('campuses', fn (Builder $campuses) => $campuses->whereKey($this->campusId())));
    }

    /** @param Builder<AcademicClass> $query
     * @return Builder<AcademicClass>
     */
    public function scopeClasses(Builder $query): Builder
    {
        return $query->when($this->isCampusScoped(), fn (Builder $classes) => $classes->where('campus_id', $this->campusId()));
    }

    public function assertYear(AcademicYear $year): void
    {
        $this->assertCampus($year->campus_id === null ? null : (int) $year->campus_id);
    }

    public function assertPeriod(AcademicPeriod $period): void
    {
        $this->assertYear($period->academicYear()->firstOrFail());
    }

    public function assertUnit(AcademicUnit $unit): void
    {
        $campusId = $this->campusId();
        if ($campusId !== null && ! $unit->campuses()->whereKey($campusId)->exists()) {
            throw new NotFoundHttpException;
        }
    }

    public function assertWritableUnit(AcademicUnit $unit): void
    {
        $this->assertUnit($unit);
        $campusId = $this->campusId();
        if ($campusId !== null && $unit->campuses()->count() !== 1) {
            throw new NotFoundHttpException;
        }
    }

    public function assertProgram(AcademicProgram $program): void
    {
        $campusId = $this->campusId();
        if ($campusId !== null && ! $program->campuses()->whereKey($campusId)->exists()) {
            throw new NotFoundHttpException;
        }
    }

    public function assertWritableProgram(AcademicProgram $program): void
    {
        $this->assertProgram($program);
        $campusId = $this->campusId();
        if ($campusId !== null && $program->campuses()->count() !== 1) {
            throw new NotFoundHttpException;
        }
    }

    public function assertClass(AcademicClass $class): void
    {
        $this->assertCampus($class->campus_id === null ? null : (int) $class->campus_id);
    }
}
