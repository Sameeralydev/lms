<?php

namespace App\Domain\Grading;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicGpaPolicy;
use App\Models\AcademicGpaSnapshot;
use App\Models\SisStudent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class GpaCalculationWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AcademicAccess $academicAccess, private readonly AuditRecorder $audit) {}

    /** @param array{name:string,policy_key:string,repeat_rule:string,exclude_pass_fail:bool,bands:list<array{band_key:string,label:string,minimum_gpa:string}>} $data */
    public function createPolicy(array $data, User $actor): AcademicGpaPolicy
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.records.gpa.manage'), 403);
        if (! in_array($data['repeat_rule'], ['highest', 'latest', 'all'], true) || $data['bands'] === [] || ! $data['exclude_pass_fail']) {
            throw ValidationException::withMessages(['policy' => 'Choose a repeat rule and at least one valid academic-standing band.']);
        }
        $seenThresholds = [];
        foreach ($data['bands'] as $band) {
            if (! preg_match('/^\d{1,2}(?:\.\d{1,3})?$/', $band['minimum_gpa']) || (float) $band['minimum_gpa'] > 20) {
                throw ValidationException::withMessages(['bands' => 'Standing thresholds must be non-negative GPAs with at most three decimal places.']);
            }
            if (in_array($band['minimum_gpa'], $seenThresholds, true)) {
                throw ValidationException::withMessages(['bands' => 'Standing thresholds must be unique.']);
            }
            $seenThresholds[] = $band['minimum_gpa'];
        }

        return DB::transaction(function () use ($data, $actor, $membership): AcademicGpaPolicy {
            $version = (int) AcademicGpaPolicy::query()->where('institution_id', $membership->institution_id)->where('policy_key', $data['policy_key'])->lockForUpdate()->max('version') + 1;
            $policy = AcademicGpaPolicy::query()->create(['institution_id' => $membership->institution_id, 'policy_key' => $data['policy_key'], 'version' => $version, 'name' => $data['name'], 'repeat_rule' => $data['repeat_rule'], 'exclude_pass_fail' => $data['exclude_pass_fail'], 'status' => 'draft', 'created_by' => $actor->getKey()]);
            foreach ($data['bands'] as $index => $band) {
                $policy->standingBands()->create([...$band, 'sort_order' => $index + 1]);
            }
            $this->audit->record('academic.gpa_policy.created', $actor, (int) $membership->tenant_id, $policy, ['policy_key' => $policy->policy_key, 'version' => $version]);

            return $policy->load('standingBands');
        });
    }

    public function activatePolicy(int $policyId, User $actor): AcademicGpaPolicy
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.records.gpa.manage'), 403);

        return DB::transaction(function () use ($policyId, $actor, $membership): AcademicGpaPolicy {
            $policy = AcademicGpaPolicy::query()->with('standingBands')->lockForUpdate()->findOrFail($policyId);
            abort_unless((int) $policy->institution_id === (int) $membership->institution_id, 404);
            if ($policy->status !== 'draft' || $policy->standingBands->isEmpty()) {
                throw new ConflictHttpException('Only a complete draft GPA policy can be activated.');
            }
            $policy->forceFill(['status' => 'active'])->save();
            $this->audit->record('academic.gpa_policy.activated', $actor, (int) $membership->tenant_id, $policy, ['version' => (int) $policy->version]);

            return $policy->refresh()->load('standingBands');
        });
    }

    public function calculate(int $studentId, int $policyId, ?int $periodId, User $actor): AcademicGpaSnapshot
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.records.gpa.manage'), 403);

        return DB::transaction(function () use ($studentId, $policyId, $periodId, $actor, $membership): AcademicGpaSnapshot {
            $student = SisStudent::query()->lockForUpdate()->findOrFail($studentId);
            abort_unless((int) $student->institution_id === (int) $membership->institution_id, 404);
            $this->academicAccess->assertCampus($student->home_campus_id === null ? null : (int) $student->home_campus_id);
            $policy = AcademicGpaPolicy::query()->with('standingBands')->where('status', 'active')->findOrFail($policyId);
            abort_unless((int) $policy->institution_id === (int) $membership->institution_id, 404);
            if ($periodId !== null) {
                abort_unless(DB::table('academic_periods as p')->join('academic_years as y', function ($join): void {
                    $join->on('y.id', '=', 'p.academic_year_id')->on('y.tenant_id', '=', 'p.tenant_id');
                })->where('p.tenant_id', $membership->tenant_id)->where('p.id', $periodId)->where('y.institution_id', $membership->institution_id)->exists(), 404);
            }
            $this->academicAccess->assertCampus($membership->campus_id === null ? null : (int) $membership->campus_id);
            $source = DB::table('academic_grade_publication_entries as e')
                ->join('course_enrollments as ce', function ($join): void {
                    $join->on('ce.id', '=', 'e.enrollment_id')->on('ce.tenant_id', '=', 'e.tenant_id');
                })
                ->join('academic_gradebooks as b', function ($join): void {
                    $join->on('b.id', '=', 'e.gradebook_id')->on('b.tenant_id', '=', 'e.tenant_id');
                })
                ->join('course_sections as s', function ($join): void {
                    $join->on('s.id', '=', 'b.section_id')->on('s.tenant_id', '=', 'b.tenant_id');
                })
                ->join('course_offerings as o', function ($join): void {
                    $join->on('o.id', '=', 's.offering_id')->on('o.tenant_id', '=', 's.tenant_id');
                })
                ->where('e.tenant_id', $membership->tenant_id)->where('ce.student_id', $studentId)->where('ce.status', 'confirmed')
                ->where('b.institution_id', $membership->institution_id)
                ->when($periodId !== null, fn ($query) => $query->where('o.academic_period_id', $periodId))
                ->when($membership->campus_id !== null, fn ($query) => $query->where('s.campus_id', $membership->campus_id))
                ->whereNotNull('e.grade_points')->orderBy('o.academic_period_id')->orderBy('o.academic_course_id')->orderBy('e.publication_id')->orderBy('e.id')
                ->get(['e.id', 'e.publication_id', 'e.gradebook_id', 'e.grade_points', 'o.academic_period_id', 'o.academic_course_id', 'ce.credits_snapshot']);
            if ($periodId !== null) {
                $source = $source->where('academic_period_id', $periodId)->values();
            }
            $valid = $source->filter(fn ($row): bool => $row->credits_snapshot !== null && $this->decimalMilli((string) $row->credits_snapshot) > 0)->values();
            if ($policy->repeat_rule !== 'all') {
                $valid = $valid->groupBy('academic_course_id')->map(function ($attempts) use ($policy) {
                    return $policy->repeat_rule === 'latest'
                        ? $attempts->last()
                        : $attempts->sort(fn ($a, $b) => ($this->decimalMilli((string) $b->grade_points) <=> $this->decimalMilli((string) $a->grade_points)) ?: ((int) $b->publication_id <=> (int) $a->publication_id))->first();
                })->values();
            }
            $qualityMilli = 0;
            $creditMilli = 0;
            $sources = [];
            foreach ($valid as $row) {
                $pointsMilli = $this->decimalMilli((string) $row->grade_points);
                $creditsMilli = $this->decimalMilli((string) $row->credits_snapshot);
                $qualityMilli += intdiv(($pointsMilli * $creditsMilli) + 500, 1000);
                $creditMilli += $creditsMilli;
                $sources[] = [(int) $row->id, (int) $row->publication_id, $pointsMilli, $creditsMilli];
            }
            if ($creditMilli <= 0) {
                throw ValidationException::withMessages(['results' => 'No published graded credits are available for this calculation.']);
            }
            $gpaMilli = intdiv(($qualityMilli * 1000) + intdiv($creditMilli, 2), $creditMilli);
            $gpa = $this->formatMilli($gpaMilli);
            $standing = $policy->standingBands->first(fn ($band): bool => $this->decimalMilli((string) $band->minimum_gpa) <= $gpaMilli)?->label;
            $scopeKey = $periodId === null ? 'cumulative' : 'period-'.$periodId;
            $sourceHash = hash('sha256', json_encode(['policy' => $policy->getKey(), 'repeat_rule' => $policy->repeat_rule, 'sources' => $sources], JSON_THROW_ON_ERROR));
            $latest = AcademicGpaSnapshot::query()->where('student_id', $studentId)->where('policy_id', $policyId)->where('scope_key', $scopeKey)->orderByDesc('snapshot_number')->lockForUpdate()->first();
            if ($latest?->source_hash === $sourceHash) {
                return $latest;
            }
            $snapshot = AcademicGpaSnapshot::query()->create([
                'institution_id' => $membership->institution_id, 'student_id' => $studentId, 'policy_id' => $policyId,
                'academic_period_id' => $periodId, 'calculation_type' => $periodId === null ? 'cumulative' : 'term', 'scope_key' => $scopeKey,
                'snapshot_number' => $latest === null ? 1 : (int) $latest->snapshot_number + 1, 'gpa' => $gpa,
                'quality_points' => $this->formatMilli($qualityMilli), 'attempted_credits' => $this->formatMilli($creditMilli),
                'academic_standing' => $standing, 'source_hash' => $sourceHash, 'supersedes_id' => $latest?->getKey(),
                'calculated_by' => $actor->getKey(), 'calculated_at' => now(),
            ]);
            $this->audit->record($latest ? 'academic.gpa_snapshot.recalculated' : 'academic.gpa_snapshot.calculated', $actor, (int) $membership->tenant_id, $snapshot, ['student_id' => $studentId, 'policy_id' => $policyId, 'snapshot_number' => (int) $snapshot->snapshot_number]);

            return $snapshot;
        });
    }

    /** @return list<array{id:int,policy:string,calculation_type:string,academic_period_id:int|null,gpa:string,quality_points:string,attempted_credits:string,academic_standing:string|null,snapshot_number:int,calculated_at:string}> */
    public function studentProgress(User $user): array
    {
        $membership = $this->context->membership();
        $student = SisStudent::query()->where('institution_id', $membership->institution_id)->where('user_id', $user->getKey())->firstOrFail();
        abort_unless((int) $student->tenant_id === (int) $membership->tenant_id, 404);

        return AcademicGpaSnapshot::query()->with('policy:id,name,version')->where('student_id', $student->getKey())->orderByDesc('calculated_at')->get()
            ->map(fn (AcademicGpaSnapshot $snapshot): array => ['id' => (int) $snapshot->getKey(), 'policy' => $snapshot->policy->name, 'calculation_type' => $snapshot->calculation_type, 'academic_period_id' => $snapshot->academic_period_id === null ? null : (int) $snapshot->academic_period_id, 'gpa' => (string) $snapshot->gpa, 'quality_points' => (string) $snapshot->quality_points, 'attempted_credits' => (string) $snapshot->attempted_credits, 'academic_standing' => $snapshot->academic_standing, 'snapshot_number' => (int) $snapshot->snapshot_number, 'calculated_at' => Carbon::parse($snapshot->calculated_at)->toIso8601String()])->all();
    }

    private function decimalMilli(string $value): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $value, $matches)) {
            throw ValidationException::withMessages(['results' => 'A published grade or credit value is not a valid three-decimal number.']);
        }

        return ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
    }

    private function formatMilli(int $value): string
    {
        return intdiv($value, 1000).'.'.str_pad((string) ($value % 1000), 3, '0', STR_PAD_LEFT);
    }
}
