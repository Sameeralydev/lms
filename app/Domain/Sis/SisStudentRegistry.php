<?php

namespace App\Domain\Sis;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicClass;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\AcademicSection;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Institution;
use App\Models\SisRegistrySetting;
use App\Models\SisStudent;
use App\Models\SisStudentLifecycleEvent;
use App\Models\SisStudentPlacement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SisStudentRegistry
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AcademicAccess $academicAccess,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, ?int $importBatchId = null, ?int $importRowNumber = null): SisStudent
    {
        if (($importBatchId === null) !== ($importRowNumber === null)) {
            throw new \InvalidArgumentException('Student import provenance requires both a batch and source row.');
        }

        return DB::transaction(function () use ($data, $actor, $importBatchId, $importRowNumber): SisStudent {
            $institution = Institution::query()->lockForUpdate()->firstOrFail();
            $campusId = isset($data['home_campus_id']) ? (int) $data['home_campus_id'] : null;
            $this->assertCampus($campusId, (int) $institution->getKey());
            $settings = SisRegistrySetting::query()->firstOrCreate(
                ['institution_id' => $institution->getKey()],
                ['student_label' => 'Student', 'number_prefix' => 'STU', 'number_width' => 6, 'next_sequence' => 1],
            );
            $settings = SisRegistrySetting::query()->whereKey($settings->getKey())->lockForUpdate()->firstOrFail();
            $number = $settings->number_prefix.str_pad((string) $settings->next_sequence, $settings->number_width, '0', STR_PAD_LEFT);
            $settings->increment('next_sequence');

            $student = SisStudent::query()->create([
                'institution_id' => $institution->getKey(), 'home_campus_id' => $campusId,
                'student_number' => $number, 'registration_reference' => $data['registration_reference'] ?? $number,
                'import_batch_id' => $importBatchId, 'import_row_number' => $importRowNumber,
                'legal_first_name' => $data['legal_first_name'], 'legal_middle_name' => $data['legal_middle_name'] ?? null,
                'legal_last_name' => $data['legal_last_name'], 'preferred_name' => $data['preferred_name'] ?? null,
                'localized_names' => $data['localized_names'] ?? null, 'date_of_birth' => $data['date_of_birth'] ?? null,
                'admission_date' => $data['admission_date'], 'status' => $data['status'] ?? 'admitted',
                'student_type' => $data['student_type'] ?? 'standard',
                'government_identifier' => $data['government_identifier'] ?? null,
                'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
                'emergency_contact_relationship' => $data['emergency_contact_relationship'] ?? null,
                'previous_institution_name' => $data['previous_institution_name'] ?? null,
                'previous_institution_reference' => $data['previous_institution_reference'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'admission_handoff_id' => $data['admission_handoff_id'] ?? null,
                'admission_application_id' => $data['admission_application_id'] ?? null,
                'admission_offer_id' => $data['admission_offer_id'] ?? null,
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ]);
            SisStudentLifecycleEvent::query()->create([
                'student_id' => $student->getKey(), 'event_type' => 'registered', 'to_status' => $student->status,
                'effective_at' => Carbon::parse($student->admission_date), 'reason_code' => 'sis.manual_registration',
                'actor_user_id' => $actor->getKey(), 'safe_metadata' => ['student_number' => $student->student_number],
            ]);
            $this->audit->record('sis.student.registered', $actor, (int) $this->context->tenant()->getKey(), $student, ['student_number' => $student->student_number]);

            return $student;
        }, 3);
    }

    /** @param array<string, mixed> $destination */
    public function place(SisStudent $student, array $destination, string $effectiveFrom, string $reasonCode, ?string $reason, User $actor, string $type = 'placement', bool $preview = false): ?SisStudentPlacement
    {
        return DB::transaction(function () use ($student, $destination, $effectiveFrom, $reasonCode, $reason, $actor, $type, $preview): ?SisStudentPlacement {
            $lockedStudent = SisStudent::query()->lockForUpdate()->findOrFail($student->getKey());
            $institutionId = (int) $lockedStudent->institution_id;
            $campusId = isset($destination['campus_id']) ? (int) $destination['campus_id'] : null;
            $this->assertCampus($campusId, $institutionId);
            $class = isset($destination['academic_class_id']) ? AcademicClass::query()->lockForUpdate()->findOrFail($destination['academic_class_id']) : null;
            $section = isset($destination['academic_section_id']) ? AcademicSection::query()->findOrFail($destination['academic_section_id']) : null;
            $year = isset($destination['academic_year_id']) ? AcademicYear::query()->findOrFail($destination['academic_year_id']) : null;
            $period = isset($destination['academic_period_id']) ? AcademicPeriod::query()->findOrFail($destination['academic_period_id']) : null;
            $program = isset($destination['academic_program_id']) ? AcademicProgram::query()->findOrFail($destination['academic_program_id']) : null;

            if ($class && $class->status !== 'active') {
                throw ValidationException::withMessages(['academic_class_id' => 'The selected class is not active.']);
            }
            if ($class && $campusId === null && $class->campus_id !== null) {
                $campusId = (int) $class->campus_id;
                $this->assertCampus($campusId, $institutionId);
            }
            if ($class && ((int) $class->institution_id !== $institutionId || ($campusId !== null && $class->campus_id !== null && (int) $class->campus_id !== $campusId))) {
                throw ValidationException::withMessages(['academic_class_id' => 'The class must belong to this institution and selected campus.']);
            }
            if ($class && ! $year && $class->academic_year_id !== null) {
                $year = AcademicYear::query()->findOrFail($class->academic_year_id);
            }
            if ($class && ! $program && $class->academic_program_id !== null) {
                $program = AcademicProgram::query()->findOrFail($class->academic_program_id);
            }
            if ($section && ($section->status !== 'active' || ! $class || (int) $section->academic_class_id !== (int) $class->getKey())) {
                throw ValidationException::withMessages(['academic_section_id' => 'The selected section must belong to the selected class.']);
            }
            if ($year && ($year->status !== 'active' || (int) $year->institution_id !== $institutionId || ($campusId !== null && $year->campus_id !== null && (int) $year->campus_id !== $campusId))) {
                throw ValidationException::withMessages(['academic_year_id' => 'The selected academic year belongs to another institution or campus.']);
            }
            if ($class && $class->academic_year_id !== null && $year && (int) $class->academic_year_id !== (int) $year->getKey()) {
                throw ValidationException::withMessages(['academic_year_id' => 'The selected class does not belong to this academic year.']);
            }
            if ($period && ($period->status !== 'active' || ! $year || (int) $period->academic_year_id !== (int) $year->getKey())) {
                throw ValidationException::withMessages(['academic_period_id' => 'The selected period must belong to the selected academic year.']);
            }
            if ($program && ($program->status !== 'active' || (int) $program->institution_id !== $institutionId)) {
                throw ValidationException::withMessages(['academic_program_id' => 'The selected program belongs to another institution.']);
            }
            if ($program && $class && $class->academic_program_id !== null && (int) $class->academic_program_id !== (int) $program->getKey()) {
                throw ValidationException::withMessages(['academic_program_id' => 'The selected class does not belong to this program.']);
            }
            if ($program && $campusId !== null && ! $program->campuses()->whereKey($campusId)->exists()) {
                throw ValidationException::withMessages(['academic_program_id' => 'The selected program is not offered at this campus.']);
            }
            if (in_array($lockedStudent->status, ['withdrawn', 'graduated', 'alumni', 'archived', 'transferred'], true)) {
                throw ValidationException::withMessages(['student' => 'This student lifecycle state does not allow a new academic placement.']);
            }
            $effective = Carbon::parse($effectiveFrom)->startOfDay();
            if ($class && (($class->starts_on && Carbon::parse($class->starts_on)->gt($effective)) || ($class->ends_on && Carbon::parse($class->ends_on)->lt($effective)))) {
                throw ValidationException::withMessages(['effective_from' => 'The placement date must fall within the class dates.']);
            }
            $current = SisStudentPlacement::query()->where('student_id', $lockedStudent->getKey())->whereNull('effective_until')->lockForUpdate()->first();
            if ($current && (int) $current->campus_id === (int) ($campusId ?? 0)
                && (int) $current->academic_year_id === (int) ($year?->getKey() ?? 0)
                && (int) $current->academic_period_id === (int) ($period?->getKey() ?? 0)
                && (int) $current->academic_program_id === (int) ($program?->getKey() ?? 0)
                && (int) $current->academic_class_id === (int) ($class?->getKey() ?? 0)
                && (int) $current->academic_section_id === (int) ($section?->getKey() ?? 0)
                && Carbon::parse($current->effective_from)->toDateString() === $effective->toDateString()) {
                return $current;
            }
            if ($section && $section->structural_capacity !== null) {
                $occupied = SisStudentPlacement::query()->where('academic_section_id', $section->getKey())->where('student_id', '<>', $lockedStudent->getKey())->whereNull('effective_until')->where('status', 'active')->count();
                if ($occupied >= $section->structural_capacity) {
                    throw ValidationException::withMessages(['academic_section_id' => 'The selected section has reached its configured structural capacity.']);
                }
            }
            if ($class && $class->capacity_policy === 'hard' && $class->structural_capacity !== null) {
                $occupied = SisStudentPlacement::query()->where('academic_class_id', $class->getKey())->where('student_id', '<>', $lockedStudent->getKey())->whereNull('effective_until')->where('status', 'active')->count();
                if ($occupied >= $class->structural_capacity) {
                    throw ValidationException::withMessages(['academic_class_id' => 'The selected class has reached its configured capacity.']);
                }
            }

            if ($current && Carbon::parse($current->effective_from)->gte($effective)) {
                throw ValidationException::withMessages(['effective_from' => 'A new placement cannot precede the current placement. Use a controlled correction workflow.']);
            }
            if ($preview) {
                return null;
            }

            if ($current) {
                $current->forceFill(['effective_until' => $effective->copy()->subDay(), 'status' => 'completed'])->save();
            }

            $placement = SisStudentPlacement::query()->create([
                'student_id' => $lockedStudent->getKey(), 'institution_id' => $institutionId,
                'campus_id' => $campusId, 'academic_year_id' => $year?->getKey(), 'academic_period_id' => $period?->getKey(),
                'academic_program_id' => $program?->getKey(), 'academic_level_id' => $class ? $class->academic_level_id : $program?->academic_level_id,
                'academic_class_id' => $class?->getKey(), 'academic_section_id' => $section?->getKey(),
                'placement_type' => $type, 'status' => 'active', 'effective_from' => $effective,
                'reason_code' => $reasonCode, 'reason' => $reason, 'created_by' => $actor->getKey(),
            ]);
            if ($campusId !== null) {
                $lockedStudent->forceFill(['home_campus_id' => $campusId, 'updated_by' => $actor->getKey()])->save();
            }
            SisStudentLifecycleEvent::query()->create([
                'student_id' => $lockedStudent->getKey(), 'event_type' => $type, 'effective_at' => $effective,
                'reason_code' => $reasonCode, 'reason' => $reason, 'actor_user_id' => $actor->getKey(),
                'safe_metadata' => ['placement_id' => $placement->getKey(), 'class_id' => $class?->getKey(), 'section_id' => $section?->getKey()],
            ]);
            $this->audit->record('sis.student.'.$type, $actor, (int) $this->context->tenant()->getKey(), $placement, ['student_id' => $lockedStudent->getKey(), 'placement_id' => $placement->getKey()]);

            return $placement;
        }, 3);
    }

    private function assertCampus(?int $campusId, int $institutionId): void
    {
        $this->academicAccess->assertCampus($campusId);
        if ($campusId !== null) {
            $campus = Campus::query()->whereKey($campusId)->firstOrFail();
            if ((int) $campus->institution_id !== $institutionId) {
                throw ValidationException::withMessages(['home_campus_id' => 'The selected campus belongs to another institution.']);
            }
        }
    }
}
