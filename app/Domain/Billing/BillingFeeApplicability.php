<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicClass;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\AcademicSection;
use App\Models\BillingFeeApplicabilityRule;
use App\Models\BillingFeeStructure;
use App\Models\Campus;
use App\Models\SisStudent;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BillingFeeApplicability
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    /** @param array{fee_structure_id:int,campus_id:int|null,academic_program_id:int|null,academic_period_id:int|null,academic_course_id?:int|null,academic_class_id?:int|null,academic_section_id?:int|null,effective_from:string,effective_until:string|null} $data */
    public function createDraft(array $data, User $actor): BillingFeeApplicabilityRule
    {
        $data += ['academic_course_id' => null, 'academic_class_id' => null, 'academic_section_id' => null];
        $membership = $this->manager();

        return DB::transaction(function () use ($data, $actor, $membership): BillingFeeApplicabilityRule {
            $this->validateReferences($data, $membership);
            $rule = BillingFeeApplicabilityRule::query()->create([
                'tenant_id' => $membership->tenant_id, 'institution_id' => $membership->institution_id,
                ...$data, 'status' => 'draft', 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ]);
            $this->audit->record('billing.fee_applicability.created', $actor, (int) $membership->tenant_id, $rule, ['status' => 'draft']);

            return $rule;
        }, 3);
    }

    /** @param array{fee_structure_id:int,campus_id:int|null,academic_program_id:int|null,academic_period_id:int|null,academic_course_id?:int|null,academic_class_id?:int|null,academic_section_id?:int|null,effective_from:string,effective_until:string|null} $data */
    public function updateDraft(int $id, array $data, User $actor): BillingFeeApplicabilityRule
    {
        $data += ['academic_course_id' => null, 'academic_class_id' => null, 'academic_section_id' => null];
        $membership = $this->manager();

        return DB::transaction(function () use ($id, $data, $actor, $membership): BillingFeeApplicabilityRule {
            $rule = BillingFeeApplicabilityRule::query()->where('tenant_id', $membership->tenant_id)->lockForUpdate()->findOrFail($id);
            $this->assertScope($rule, $membership);
            if ($rule->status !== 'draft') {
                throw new ConflictHttpException('Only a draft rule can be edited. Create a new rule to preserve an active rule history.');
            }
            $this->validateReferences($data, $membership);
            $rule->fill([...$data, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record('billing.fee_applicability.draft_updated', $actor, (int) $membership->tenant_id, $rule, []);

            return $rule;
        }, 3);
    }

    public function transition(int $id, string $status, User $actor): BillingFeeApplicabilityRule
    {
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw ValidationException::withMessages(['status' => 'Unsupported applicability-rule state.']);
        }
        $membership = $this->manager();

        return DB::transaction(function () use ($id, $status, $actor, $membership): BillingFeeApplicabilityRule {
            $rule = BillingFeeApplicabilityRule::query()->where('tenant_id', $membership->tenant_id)->lockForUpdate()->findOrFail($id);
            $this->assertScope($rule, $membership);
            if ($status === 'active') {
                if (! in_array($rule->status, ['draft', 'inactive'], true)) {
                    throw new ConflictHttpException('Only a draft or inactive rule can be activated.');
                }
                $structure = BillingFeeStructure::query()->where('tenant_id', $membership->tenant_id)->lockForUpdate()->findOrFail($rule->fee_structure_id);
                if ($structure->status !== 'active' || (int) $structure->institution_id !== (int) $rule->institution_id
                    || ($structure->campus_id !== null && (int) $structure->campus_id !== (int) $rule->campus_id)) {
                    throw ValidationException::withMessages(['fee_structure_id' => 'Choose an active structure compatible with the rule institution and campus.']);
                }
                $structureFrom = Carbon::parse($structure->effective_from)->toDateString();
                $structureUntil = $structure->effective_until === null ? null : Carbon::parse($structure->effective_until)->toDateString();
                if ($structureFrom > Carbon::parse($rule->effective_from)->toDateString()
                    || ($structureUntil !== null && ($rule->effective_until === null || $structureUntil < Carbon::parse($rule->effective_until)->toDateString()))) {
                    throw ValidationException::withMessages(['fee_structure_id' => 'The fee structure must remain effective for the full rule validity range.']);
                }
                $conflict = BillingFeeApplicabilityRule::query()->where('tenant_id', $rule->tenant_id)->where('institution_id', $rule->institution_id)
                    ->where('status', 'active')->where('campus_id', $rule->campus_id)->where('academic_program_id', $rule->academic_program_id)
                    ->where('academic_period_id', $rule->academic_period_id)->where('academic_course_id', $rule->academic_course_id)
                    ->where('academic_class_id', $rule->academic_class_id)->where('academic_section_id', $rule->academic_section_id)->where('id', '<>', $rule->getKey())
                    ->whereDate('effective_from', '<=', $rule->effective_until ?? '9999-12-31')
                    ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $rule->effective_from))->lockForUpdate()->exists();
                if ($conflict) {
                    throw new ConflictHttpException('An active rule already covers this exact academic scope and an overlapping effective period.');
                }
            } elseif ($rule->status !== 'active') {
                throw new ConflictHttpException('Only an active rule can be deactivated.');
            }
            $rule->forceFill(['status' => $status, 'updated_by' => $actor->getKey()])->save();
            $this->audit->record('billing.fee_applicability.'.$status, $actor, (int) $membership->tenant_id, $rule, []);

            return $rule;
        }, 3);
    }

    /** @return array{rule:BillingFeeApplicabilityRule|null,ambiguous:bool} */
    public function resolve(int $institutionId, ?int $campusId, ?int $programId, ?int $periodId, string $onDate, ?int $courseId = null, ?int $classId = null, ?int $sectionId = null): array
    {
        $date = Carbon::parse($onDate)->toDateString();
        $rules = BillingFeeApplicabilityRule::query()->where('tenant_id', $this->context->tenant()->getKey())
            ->where('institution_id', $institutionId)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('campus_id')->orWhere('campus_id', $campusId))
            ->where(fn ($q) => $q->whereNull('academic_program_id')->orWhere('academic_program_id', $programId))
            ->where(fn ($q) => $q->whereNull('academic_period_id')->orWhere('academic_period_id', $periodId))
            ->where(fn ($q) => $q->whereNull('academic_course_id')->orWhere('academic_course_id', $courseId))
            ->where(fn ($q) => $q->whereNull('academic_class_id')->orWhere('academic_class_id', $classId))
            ->where(fn ($q) => $q->whereNull('academic_section_id')->orWhere('academic_section_id', $sectionId))
            ->whereDate('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
            ->with('feeStructure')->get()->filter(static function (BillingFeeApplicabilityRule $rule) use ($date): bool {
                $structure = $rule->feeStructure;

                return $structure !== null && $structure->status === 'active'
                    && Carbon::parse($structure->effective_from)->toDateString() <= $date
                    && ($structure->effective_until === null || Carbon::parse($structure->effective_until)->toDateString() >= $date);
            })->values();
        $rules = $rules->sortByDesc(static fn (BillingFeeApplicabilityRule $rule): int => (int) ($rule->campus_id !== null) + (int) ($rule->academic_program_id !== null) + (int) ($rule->academic_period_id !== null) + (int) ($rule->academic_course_id !== null) + (int) ($rule->academic_class_id !== null) + (int) ($rule->academic_section_id !== null))->values();
        if ($rules->isEmpty()) {
            return ['rule' => null, 'ambiguous' => false];
        }
        $specificity = (int) ($rules[0]->campus_id !== null) + (int) ($rules[0]->academic_program_id !== null) + (int) ($rules[0]->academic_period_id !== null) + (int) ($rules[0]->academic_course_id !== null) + (int) ($rules[0]->academic_class_id !== null) + (int) ($rules[0]->academic_section_id !== null);
        $best = $rules->filter(static fn (BillingFeeApplicabilityRule $rule): bool => ((int) ($rule->campus_id !== null) + (int) ($rule->academic_program_id !== null) + (int) ($rule->academic_period_id !== null) + (int) ($rule->academic_course_id !== null) + (int) ($rule->academic_class_id !== null) + (int) ($rule->academic_section_id !== null)) === $specificity);
        if ($best->count() !== 1) {
            return ['rule' => null, 'ambiguous' => true];
        }

        return ['rule' => $best->first(), 'ambiguous' => false];
    }

    /** @return array{eligible:bool,reason:string,fee_structure_id:int|null,rule_id:int|null} */
    public function previewStudent(int $studentId, int $academicPeriodId, string $onDate): array
    {
        $membership = $this->manager();
        $student = SisStudent::query()->where('tenant_id', $membership->tenant_id)->findOrFail($studentId);
        if ((int) $student->institution_id !== (int) $membership->institution_id || ($membership->campus_id !== null && (int) $student->home_campus_id !== (int) $membership->campus_id)) {
            abort(404);
        }
        $period = AcademicPeriod::query()->where('tenant_id', $membership->tenant_id)->findOrFail($academicPeriodId);
        $programId = DB::table('sis_student_placements')->where('tenant_id', $membership->tenant_id)->where('student_id', $student->getKey())
            ->where('academic_period_id', $academicPeriodId)->whereDate('effective_from', '<=', $onDate)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $onDate))
            ->whereNotNull('academic_program_id')->orderByDesc('effective_from')->value('academic_program_id');
        $resolved = $this->resolve((int) $student->institution_id, $student->home_campus_id, $programId === null ? null : (int) $programId, $academicPeriodId, $onDate);

        return ['eligible' => $resolved['rule'] !== null, 'reason' => $resolved['ambiguous'] ? 'ambiguous_rules' : ($resolved['rule'] === null ? 'no_matching_rule' : 'matched'),
            'fee_structure_id' => $resolved['rule']?->fee_structure_id, 'rule_id' => $resolved['rule']?->getKey()];
    }

    private function manager(): TenantMembership
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('billing.fees.manage'), 403);

        return $membership;
    }

    /** @param array{fee_structure_id:int,campus_id:int|null,academic_program_id:int|null,academic_period_id:int|null,academic_course_id:int|null,academic_class_id:int|null,academic_section_id:int|null,effective_from:string,effective_until:string|null} $data */
    private function validateReferences(array $data, TenantMembership $membership): void
    {
        $structure = BillingFeeStructure::query()->where('tenant_id', $membership->tenant_id)->findOrFail($data['fee_structure_id']);
        if ((int) $structure->institution_id !== (int) $membership->institution_id) {
            abort(404);
        }
        if ($data['campus_id'] !== null) {
            $campus = Campus::query()->where('tenant_id', $membership->tenant_id)->findOrFail($data['campus_id']);
            if ((int) $campus->institution_id !== (int) $membership->institution_id || ($membership->campus_id !== null && (int) $campus->getKey() !== (int) $membership->campus_id)) {
                abort(404);
            }
        } elseif ($membership->campus_id !== null) {
            abort(404);
        }
        if ($data['academic_program_id'] !== null && ! AcademicProgram::query()->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)->whereKey($data['academic_program_id'])->exists()) {
            abort(404);
        }
        if ($data['academic_course_id'] !== null && ! AcademicCourse::query()->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)->whereKey($data['academic_course_id'])->exists()) {
            abort(404);
        }
        $class = $data['academic_class_id'] === null ? null : AcademicClass::query()->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($q) => $q->where(fn ($scope) => $scope->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id)))
            ->find($data['academic_class_id']);
        if ($data['academic_class_id'] !== null && $class === null) {
            abort(404);
        }
        if ($data['academic_section_id'] !== null && ($data['academic_class_id'] === null || ! AcademicSection::query()->where('tenant_id', $membership->tenant_id)
            ->where('academic_class_id', $data['academic_class_id'])->whereKey($data['academic_section_id'])->exists())) {
            abort(404);
        }
        if ($class !== null && (($data['campus_id'] !== null && $class->campus_id !== null && (int) $class->campus_id !== $data['campus_id'])
            || ($data['academic_program_id'] !== null && $class->academic_program_id !== null && (int) $class->academic_program_id !== $data['academic_program_id']))) {
            throw ValidationException::withMessages(['academic_class_id' => 'The class does not match the selected campus or program scope.']);
        }
        if ($data['academic_course_id'] !== null && $class !== null) {
            $course = AcademicCourse::query()->where('tenant_id', $membership->tenant_id)->findOrFail($data['academic_course_id']);
            if ($course->academic_level_id !== null && $class->academic_level_id !== null && (int) $course->academic_level_id !== (int) $class->academic_level_id) {
                throw ValidationException::withMessages(['academic_course_id' => 'The course and class belong to different academic levels.']);
            }
        }
        if ($data['academic_period_id'] !== null && ! AcademicPeriod::query()->where('tenant_id', $membership->tenant_id)->whereKey($data['academic_period_id'])
            ->whereIn('academic_year_id', fn ($q) => $q->select('id')->from('academic_years')->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)
                ->when($membership->campus_id !== null, fn ($year) => $year->where(fn ($scope) => $scope->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id))))->exists()) {
            abort(404);
        }
        if ($data['effective_until'] !== null && $data['effective_until'] < $data['effective_from']) {
            throw ValidationException::withMessages(['effective_until' => 'End date must not precede the effective start date.']);
        }
    }

    private function assertScope(BillingFeeApplicabilityRule $rule, TenantMembership $membership): void
    {
        if ((int) $rule->institution_id !== (int) $membership->institution_id || ((int) $rule->tenant_id !== (int) $membership->tenant_id)) {
            abort(404);
        }
        if ($membership->campus_id !== null && ($rule->campus_id === null || (int) $rule->campus_id !== (int) $membership->campus_id)) {
            abort(404);
        }
    }
}
