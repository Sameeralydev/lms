<?php

namespace App\Domain\Sis;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Models\AcademicClass;
use App\Models\AcademicSection;
use App\Models\Institution;
use App\Models\SisStudent;
use App\Models\SisStudentPlacement;
use App\Models\SisStudentPromotionHold;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SisBulkPromotionWorkflow
{
    public function __construct(
        private readonly AcademicAccess $academicAccess,
        private readonly AuditRecorder $audit,
        private readonly SisStudentRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{count:int}
     */
    public function preview(array $payload, User $actor): array
    {
        return DB::transaction(function () use ($payload, $actor): array {
            $students = $this->eligibleStudents($payload['student_ids']);
            $destination = $this->destination($payload);
            foreach ($students as $student) {
                $current = SisStudentPlacement::query()->where('student_id', $student->getKey())->whereNull('effective_until')->first();
                if (! $current) {
                    throw ValidationException::withMessages(['student_ids' => "{$student->student_number} has no current placement and cannot be promoted in bulk."]);
                }
                if ((int) $current->academic_class_id === (int) $destination['academic_class_id']) {
                    throw ValidationException::withMessages(['student_ids' => "{$student->student_number} is already in the destination class."]);
                }
                $this->registry->place(
                    $student, $destination, $payload['effective_from'], $payload['reason_code'], $payload['reason'] ?? null,
                    $actor, 'promotion', true,
                );
            }

            $this->assertSelectedCapacity($students, $destination);

            return ['count' => $students->count()];
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    public function execute(array $payload, User $actor): int
    {
        return DB::transaction(function () use ($payload, $actor): int {
            $preview = $this->preview($payload, $actor);
            $students = $this->eligibleStudents($payload['student_ids']);
            $destination = $this->destination($payload);
            foreach ($students as $student) {
                $this->registry->place(
                    $student, $destination, $payload['effective_from'], $payload['reason_code'], $payload['reason'] ?? null,
                    $actor, 'promotion', false,
                );
            }

            $this->audit->record('sis.students.bulk_promotion_completed', $actor, (int) $students->first()->tenant_id, null, [
                'count' => $preview['count'],
                'destination_class_id' => $destination['academic_class_id'], 'effective_from' => $payload['effective_from'],
            ]);

            return $preview['count'];
        }, 3);
    }

    /**
     * @param  list<int>  $studentIds
     * @return Collection<int, SisStudent>
     */
    private function eligibleStudents(array $studentIds): Collection
    {
        $institutionId = (int) Institution::query()->value('id');
        $students = SisStudent::query()->where('institution_id', $institutionId)->whereIn('id', $studentIds)->orderBy('id')->lockForUpdate()->get();
        if ($students->count() !== count($studentIds)) {
            abort(404);
        }
        foreach ($students as $student) {
            $this->academicAccess->assertCampus($student->home_campus_id === null ? null : (int) $student->home_campus_id);
            if (! in_array($student->status, ['admitted', 'active'], true)) {
                throw ValidationException::withMessages(['student_ids' => "{$student->student_number} is not eligible for promotion in its current lifecycle state."]);
            }
            if (SisStudentPromotionHold::query()->where('student_id', $student->getKey())->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['student_ids' => "{$student->student_number} has an active progression hold and cannot be promoted."]);
            }
        }

        return $students;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{campus_id: int|null, academic_year_id: int|null, academic_period_id: int|null, academic_program_id: int|null, academic_class_id: int, academic_section_id: int|null}
     */
    private function destination(array $payload): array
    {
        return [
            'campus_id' => isset($payload['campus_id']) && $payload['campus_id'] !== '' ? (int) $payload['campus_id'] : null,
            'academic_year_id' => isset($payload['academic_year_id']) && $payload['academic_year_id'] !== '' ? (int) $payload['academic_year_id'] : null,
            'academic_period_id' => isset($payload['academic_period_id']) && $payload['academic_period_id'] !== '' ? (int) $payload['academic_period_id'] : null,
            'academic_program_id' => isset($payload['academic_program_id']) && $payload['academic_program_id'] !== '' ? (int) $payload['academic_program_id'] : null,
            'academic_class_id' => (int) $payload['academic_class_id'],
            'academic_section_id' => isset($payload['academic_section_id']) && $payload['academic_section_id'] !== '' ? (int) $payload['academic_section_id'] : null,
        ];
    }

    /**
     * @param  Collection<int, SisStudent>  $students
     * @param  array{campus_id: int|null, academic_year_id: int|null, academic_period_id: int|null, academic_program_id: int|null, academic_class_id: int, academic_section_id: int|null}  $destination
     */
    private function assertSelectedCapacity(Collection $students, array $destination): void
    {
        $class = AcademicClass::query()->lockForUpdate()->findOrFail($destination['academic_class_id']);
        $incomingClassCount = $students->filter(function (SisStudent $student) use ($destination): bool {
            $currentClassId = SisStudentPlacement::query()->where('student_id', $student->getKey())->whereNull('effective_until')->value('academic_class_id');

            return (int) $currentClassId !== (int) $destination['academic_class_id'];
        })->count();
        $classOccupancy = SisStudentPlacement::query()->where('academic_class_id', $class->getKey())
            ->whereNull('effective_until')->where('status', 'active')->distinct()->count('student_id');
        if ($class->capacity_policy === 'hard' && $class->structural_capacity !== null
            && $classOccupancy + $incomingClassCount > (int) $class->structural_capacity) {
            throw ValidationException::withMessages(['academic_class_id' => 'The selected students exceed the destination class capacity. No promotion was confirmed.']);
        }

        if ($destination['academic_section_id'] === null) {
            return;
        }
        $section = AcademicSection::query()->findOrFail($destination['academic_section_id']);
        if ($section->structural_capacity === null) {
            return;
        }
        $incomingSectionCount = $students->filter(function (SisStudent $student) use ($destination): bool {
            $currentSectionId = SisStudentPlacement::query()->where('student_id', $student->getKey())->whereNull('effective_until')->value('academic_section_id');

            return (int) $currentSectionId !== (int) $destination['academic_section_id'];
        })->count();
        $sectionOccupancy = SisStudentPlacement::query()->where('academic_section_id', $section->getKey())
            ->whereNull('effective_until')->where('status', 'active')->distinct()->count('student_id');
        if ($sectionOccupancy + $incomingSectionCount > (int) $section->structural_capacity) {
            throw ValidationException::withMessages(['academic_section_id' => 'The selected students exceed the destination section capacity. No promotion was confirmed.']);
        }
    }
}
