<?php

namespace App\Domain\Grading;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicGpaSnapshot;
use App\Models\AcademicRecordIssue;
use App\Models\AcademicRecordTemplate;
use App\Models\AcademicReportCardComment;
use App\Models\SisStudent;
use App\Models\SisStudentGuardian;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class OfficialAcademicRecordWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AcademicAccess $academicAccess, private readonly AuditRecorder $audit) {}

    /** @param array{name:string,template_key:string,record_type:string,configuration:array<string,mixed>} $data */
    public function createTemplate(array $data, User $actor): AcademicRecordTemplate
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.records.issue'), 403);
        if (! in_array($data['record_type'], ['report_card', 'transcript', 'certificate'], true)) {
            throw ValidationException::withMessages(['record_type' => 'Choose a supported academic record type.']);
        }

        return DB::transaction(function () use ($data, $actor, $membership): AcademicRecordTemplate {
            $version = (int) AcademicRecordTemplate::query()->where('institution_id', $membership->institution_id)->where('record_type', $data['record_type'])->where('template_key', $data['template_key'])->lockForUpdate()->max('version') + 1;
            $template = AcademicRecordTemplate::query()->create(['institution_id' => $membership->institution_id, 'record_type' => $data['record_type'], 'template_key' => $data['template_key'], 'version' => $version, 'name' => $data['name'], 'configuration' => $data['configuration'], 'status' => 'draft', 'created_by' => $actor->getKey()]);
            $this->audit->record('academic.record_template.created', $actor, (int) $membership->tenant_id, $template, ['record_type' => $template->record_type, 'version' => $version]);

            return $template;
        });
    }

    public function activateTemplate(int $templateId, User $actor): AcademicRecordTemplate
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.records.issue'), 403);

        return DB::transaction(function () use ($templateId, $actor, $membership): AcademicRecordTemplate {
            $template = AcademicRecordTemplate::query()->lockForUpdate()->findOrFail($templateId);
            abort_unless((int) $template->institution_id === (int) $membership->institution_id, 404);
            if ($template->status !== 'draft') {
                throw new ConflictHttpException('Only a draft template can be activated. Create a new version to make changes.');
            }
            $template->forceFill(['status' => 'active'])->save();
            $this->audit->record('academic.record_template.activated', $actor, (int) $membership->tenant_id, $template, ['version' => (int) $template->version]);

            return $template->refresh();
        });
    }

    /** @return list<array{id:int,student:string,period:string,section:string,status:string,comment:string}> */
    public function teacherCommentWorkspace(User $actor): array
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.grades.book.manage'), 403);

        return AcademicReportCardComment::query()->with(['student', 'section.offering.period'])
            ->where('teacher_id', $actor->getKey())->where('institution_id', $membership->institution_id)
            ->orderByDesc('updated_at')->limit(200)->get()
            ->map(fn (AcademicReportCardComment $comment): array => [
                'id' => (int) $comment->getKey(), 'student' => (string) $comment->student->student_number,
                'period' => (string) $comment->section->offering->period->name,
                'section' => (string) $comment->section->section_code, 'status' => $comment->status,
                'comment' => $comment->comment,
            ])->all();
    }

    public function submitTeacherComment(int $studentId, int $periodId, int $sectionId, string $body, User $actor): AcademicReportCardComment
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.grades.book.manage'), 403);
        abort_unless(mb_strlen(trim($body)) >= 10 && mb_strlen(trim($body)) <= 2000, 422);

        return DB::transaction(function () use ($studentId, $periodId, $sectionId, $body, $actor, $membership): AcademicReportCardComment {
            $section = DB::table('course_sections as s')->join('course_offerings as o', function ($join): void {
                $join->on('o.id', '=', 's.offering_id')->on('o.tenant_id', '=', 's.tenant_id');
            })->where('s.tenant_id', $membership->tenant_id)->where('s.id', $sectionId)
                ->where('o.academic_period_id', $periodId)->when($membership->campus_id !== null, fn ($query) => $query->where('s.campus_id', $membership->campus_id))
                ->first(['s.id', 'o.academic_period_id']);
            abort_unless($section !== null, 404);
            abort_unless(DB::table('course_section_instructors')->where('tenant_id', $membership->tenant_id)->where('section_id', $sectionId)->where('user_id', $actor->getKey())->exists(), 403);
            $student = SisStudent::query()->where('id', $studentId)->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)->firstOrFail();
            abort_unless(DB::table('course_enrollments')->where('tenant_id', $membership->tenant_id)->where('student_id', $studentId)->where('section_id', $sectionId)->where('status', 'confirmed')->exists(), 404);
            abort_unless(DB::table('academic_periods as p')->join('academic_years as y', function ($join): void {
                $join->on('y.id', '=', 'p.academic_year_id')->on('y.tenant_id', '=', 'p.tenant_id');
            })->where('p.tenant_id', $membership->tenant_id)->where('p.id', $periodId)->where('y.institution_id', $membership->institution_id)->exists(), 404);

            $version = (int) AcademicReportCardComment::query()->where('student_id', $studentId)->where('academic_period_id', $periodId)->where('section_id', $sectionId)->where('teacher_id', $actor->getKey())->lockForUpdate()->max('version') + 1;
            $comment = AcademicReportCardComment::query()->create([
                'tenant_id' => $membership->tenant_id, 'institution_id' => $membership->institution_id,
                'student_id' => $studentId, 'academic_period_id' => $periodId, 'section_id' => $sectionId,
                'teacher_id' => $actor->getKey(), 'version' => $version, 'comment' => trim($body), 'status' => 'submitted',
            ]);
            $this->audit->record('academic.report_card_comment.submitted', $actor, (int) $membership->tenant_id, $comment, ['student_id' => $studentId, 'academic_period_id' => $periodId, 'section_id' => $sectionId, 'version' => $version]);
            $this->queueAcademicEvent((int) $membership->tenant_id, 'academic.report_card_comment.submitted', (int) $comment->getKey(), 'comment_id');

            return $comment;
        });
    }

    public function decideTeacherComment(int $commentId, bool $approve, string $reason, User $actor): AcademicReportCardComment
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.records.review'), 403);

        return DB::transaction(function () use ($commentId, $approve, $reason, $actor, $membership): AcademicReportCardComment {
            $comment = AcademicReportCardComment::query()->with('section')->lockForUpdate()->findOrFail($commentId);
            abort_unless((int) $comment->institution_id === (int) $membership->institution_id, 404);
            $this->academicAccess->assertCampus($comment->section->campus_id === null ? null : (int) $comment->section->campus_id);
            abort_unless((int) $comment->teacher_id !== (int) $actor->getKey(), 403);
            if ($comment->status !== 'submitted') {
                throw new ConflictHttpException('Only a submitted teacher comment can be reviewed.');
            }
            $comment->forceFill(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_reason' => trim($reason)])->save();
            $this->audit->record('academic.report_card_comment.'.($approve ? 'approved' : 'rejected'), $actor, (int) $membership->tenant_id, $comment, ['student_id' => (int) $comment->student_id, 'version' => (int) $comment->version, 'reason' => trim($reason)]);
            $this->queueAcademicEvent((int) $membership->tenant_id, 'academic.report_card_comment.decided', (int) $comment->getKey(), 'comment_id');

            return $comment->refresh();
        });
    }

    public function prepareIssue(int $studentId, int $templateId, ?int $periodId, ?string $teacherComment, User $actor): AcademicRecordIssue
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.records.issue'), 403);
        if ($teacherComment !== null && trim($teacherComment) !== '') {
            throw ValidationException::withMessages(['teacher_comment' => 'Teacher comments must be submitted by an assigned instructor and independently approved.']);
        }

        return DB::transaction(function () use ($studentId, $templateId, $periodId, $actor, $membership): AcademicRecordIssue {
            $student = SisStudent::query()->lockForUpdate()->findOrFail($studentId);
            abort_unless((int) $student->institution_id === (int) $membership->institution_id, 404);
            $this->academicAccess->assertCampus($student->home_campus_id === null ? null : (int) $student->home_campus_id);
            $template = AcademicRecordTemplate::query()->where('status', 'active')->findOrFail($templateId);
            abort_unless((int) $template->institution_id === (int) $membership->institution_id, 404);
            if (($template->record_type === 'report_card') !== ($periodId !== null)) {
                throw ValidationException::withMessages(['academic_period_id' => 'Report cards require one academic period; transcripts and certificates do not.']);
            }
            if ($periodId !== null) {
                abort_unless(DB::table('academic_periods as p')->join('academic_years as y', function ($join): void {
                    $join->on('y.id', '=', 'p.academic_year_id')->on('y.tenant_id', '=', 'p.tenant_id');
                })->where('p.tenant_id', $membership->tenant_id)->where('p.id', $periodId)->where('y.institution_id', $membership->institution_id)->exists(), 404);
            }
            if ($template->record_type === 'certificate') {
                abort_unless($student->status === 'active' && DB::table('course_enrollments')->where('tenant_id', $membership->tenant_id)->where('student_id', $studentId)->where('status', 'confirmed')->exists(), 409, 'A certificate requires an active student with a confirmed course enrollment.');
                $minimum = $template->configuration['minimum_cumulative_gpa'] ?? null;
                if ($minimum !== null) {
                    $gpa = AcademicGpaSnapshot::query()->where('student_id', $studentId)->where('calculation_type', 'cumulative')->orderByDesc('calculated_at')->value('gpa');
                    abort_unless($gpa !== null && (float) $gpa >= (float) $minimum, 409, 'The configured GPA eligibility condition is not satisfied.');
                }
            }
            $rows = $this->publishedGrades((int) $membership->tenant_id, (int) $membership->institution_id, $studentId, $periodId, $membership->campus_id === null ? null : (int) $membership->campus_id);
            if ($template->record_type !== 'certificate' && $rows === []) {
                throw ValidationException::withMessages(['results' => 'No published grade history is available for this academic record.']);
            }
            $gpa = AcademicGpaSnapshot::query()->where('student_id', $studentId)->where('calculation_type', $periodId === null ? 'cumulative' : 'term')->when($periodId !== null, fn ($query) => $query->where('academic_period_id', $periodId))->orderByDesc('calculated_at')->first();
            $payload = [
                'student' => ['student_number' => $student->student_number, 'legal_name' => trim($student->legal_first_name.' '.$student->legal_last_name)],
                'institution' => ['name' => $membership->institution->display_name ?? $membership->institution->official_name],
                'record_type' => $template->record_type, 'template' => ['name' => $template->name, 'version' => (int) $template->version],
                'academic_period_id' => $periodId, 'issued_on' => now()->toDateString(), 'courses' => $rows,
                'gpa' => $gpa ? ['snapshot_id' => (int) $gpa->getKey(), 'value' => (string) $gpa->gpa, 'standing' => $gpa->academic_standing, 'credits' => (string) $gpa->attempted_credits] : null,
                'teacher_comment' => $template->record_type === 'report_card' && $periodId !== null
                    ? AcademicReportCardComment::query()->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)->where('student_id', $studentId)->where('academic_period_id', $periodId)->where('status', 'approved')->orderBy('section_id')->orderBy('teacher_id')->get()->map(fn (AcademicReportCardComment $comment): array => ['section_id' => (int) $comment->section_id, 'teacher_id' => (int) $comment->teacher_id, 'version' => (int) $comment->version, 'comment' => $comment->comment])->all()
                    : [],
            ];
            $latest = AcademicRecordIssue::query()->where('student_id', $studentId)->where('record_type', $template->record_type)->when($periodId !== null, fn ($query) => $query->where('academic_period_id', $periodId))->when($periodId === null, fn ($query) => $query->whereNull('academic_period_id'))->orderByDesc('issue_number')->lockForUpdate()->first();
            $number = (int) AcademicRecordIssue::query()->where('student_id', $studentId)->where('record_type', $template->record_type)->max('issue_number') + 1;
            $issue = AcademicRecordIssue::query()->create([
                'institution_id' => $membership->institution_id, 'student_id' => $studentId, 'template_id' => $templateId, 'academic_period_id' => $periodId,
                'record_type' => $template->record_type, 'status' => 'draft', 'issue_number' => $number, 'verification_code' => (string) Str::uuid(),
                'record_snapshot' => $payload, 'source_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'supersedes_id' => $latest?->getKey(), 'created_by' => $actor->getKey(),
            ]);
            $this->audit->record('academic.record.prepared', $actor, (int) $membership->tenant_id, $issue, ['record_type' => $issue->record_type, 'issue_number' => $number]);

            return $issue;
        });
    }

    public function submit(int $issueId, User $actor): AcademicRecordIssue
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.records.issue'), 403);

        return DB::transaction(function () use ($issueId, $actor, $membership): AcademicRecordIssue {
            $issue = AcademicRecordIssue::query()->lockForUpdate()->findOrFail($issueId);
            abort_unless((int) $issue->institution_id === (int) $membership->institution_id, 404);
            if ($issue->status !== 'draft') {
                throw new ConflictHttpException('Only a draft record can be submitted for independent approval.');
            }
            $issue->forceFill(['status' => 'submitted'])->save();
            $this->audit->record('academic.record.submitted', $actor, (int) $membership->tenant_id, $issue, ['record_type' => $issue->record_type]);
            $this->queueAcademicEvent((int) $membership->tenant_id, 'academic.record.submitted', (int) $issue->getKey());

            return $issue->refresh();
        });
    }

    public function decide(int $issueId, bool $approve, string $reason, User $actor): AcademicRecordIssue
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.records.review'), 403);

        return DB::transaction(function () use ($issueId, $approve, $reason, $actor, $membership): AcademicRecordIssue {
            $issue = AcademicRecordIssue::query()->with(['student', 'template'])->lockForUpdate()->findOrFail($issueId);
            abort_unless((int) $issue->institution_id === (int) $membership->institution_id, 404);
            $this->academicAccess->assertCampus($issue->student->home_campus_id === null ? null : (int) $issue->student->home_campus_id);
            abort_unless((int) $issue->created_by !== (int) $actor->getKey(), 403);
            if ($issue->status !== 'submitted') {
                throw new ConflictHttpException('Only a submitted record can be reviewed.');
            }
            if ($approve) {
                $this->assertSourcesCurrent($issue, $membership->campus_id === null ? null : (int) $membership->campus_id);
            }
            $issue->forceFill(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'revocation_reason' => $approve ? null : trim($reason)])->save();
            $this->audit->record('academic.record.'.($approve ? 'approved' : 'rejected'), $actor, (int) $membership->tenant_id, $issue, ['record_type' => $issue->record_type, 'reason' => trim($reason)]);
            $this->queueAcademicEvent((int) $membership->tenant_id, 'academic.record.decided', (int) $issue->getKey());

            return $issue->refresh();
        });
    }

    public function issue(int $issueId, User $actor): AcademicRecordIssue
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.records.review'), 403);

        return DB::transaction(function () use ($issueId, $actor, $membership): AcademicRecordIssue {
            $issue = AcademicRecordIssue::query()->with(['student', 'template'])->lockForUpdate()->findOrFail($issueId);
            abort_unless((int) $issue->institution_id === (int) $membership->institution_id, 404);
            $this->academicAccess->assertCampus($issue->student->home_campus_id === null ? null : (int) $issue->student->home_campus_id);
            if ($issue->status !== 'approved' || (int) $issue->approved_by === (int) $actor->getKey()) {
                throw new ConflictHttpException('An approved record requires issuance by an independent authorized operator.');
            }
            $this->assertSourcesCurrent($issue, $membership->campus_id === null ? null : (int) $membership->campus_id);
            $issue->forceFill(['status' => 'issued', 'issued_by' => $actor->getKey(), 'issued_at' => now()])->save();
            $this->audit->record('academic.record.issued', $actor, (int) $membership->tenant_id, $issue, ['record_type' => $issue->record_type, 'issue_number' => (int) $issue->issue_number]);
            $this->queueRecordNotification($issue, 'academic.record.issued');

            return $issue->refresh();
        });
    }

    public function revoke(int $issueId, string $reason, User $actor): AcademicRecordIssue
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.records.review'), 403);

        return DB::transaction(function () use ($issueId, $reason, $actor, $membership): AcademicRecordIssue {
            $issue = AcademicRecordIssue::query()->lockForUpdate()->findOrFail($issueId);
            abort_unless((int) $issue->institution_id === (int) $membership->institution_id, 404);
            $this->academicAccess->assertCampus($issue->student->home_campus_id === null ? null : (int) $issue->student->home_campus_id);
            if ($issue->status !== 'issued') {
                throw new ConflictHttpException('Only an issued record can be revoked.');
            }
            $issue->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revocation_reason' => trim($reason)])->save();
            $this->audit->record('academic.record.revoked', $actor, (int) $membership->tenant_id, $issue, ['record_type' => $issue->record_type, 'reason' => trim($reason)]);
            $this->queueRecordNotification($issue, 'academic.record.revoked');

            return $issue->refresh();
        });
    }

    /** @return array{valid:bool,record_type:string|null,issued_on:string|null,institution:string|null} */
    public function verify(string $code): array
    {
        $issue = DB::table('academic_record_issues as issues')
            ->join('institutions', function ($join): void {
                $join->on('institutions.id', '=', 'issues.institution_id')->on('institutions.tenant_id', '=', 'issues.tenant_id');
            })
            ->where('issues.verification_code', $code)->where('issues.record_type', 'certificate')->where('issues.status', 'issued')
            ->first(['issues.record_type', 'issues.issued_at', 'institutions.display_name']);
        if ($issue === null) {
            return ['valid' => false, 'record_type' => null, 'issued_on' => null, 'institution' => null];
        }

        return ['valid' => true, 'record_type' => 'certificate', 'issued_on' => $issue->issued_at === null ? null : CarbonImmutable::parse((string) $issue->issued_at)->toDateString(), 'institution' => (string) $issue->display_name];
    }

    public function downloadable(int $issueId, User $user): AcademicRecordIssue
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.records.view-own') || $membership->hasPermission('academic.records.guardian.view') || $membership->hasPermission('academic.records.view-all'), 403);
        $issue = AcademicRecordIssue::query()->with('student.institution')->findOrFail($issueId);
        abort_unless($issue->status === 'issued' && (int) $issue->institution_id === (int) $membership->institution_id, 404);
        $this->academicAccess->assertCampus($issue->student->home_campus_id === null ? null : (int) $issue->student->home_campus_id);
        $isStudent = (int) $issue->student->user_id === (int) $user->getKey();
        $isGuardian = ! $isStudent && $membership->hasPermission('academic.records.guardian.view') && SisStudentGuardian::query()->where('student_id', $issue->student_id)->where('guardian_user_id', $user->getKey())->where('authority_status', 'verified')->whereNull('revoked_at')->where('can_view_profile', true)->exists();
        abort_unless($isStudent || $isGuardian || $membership->hasPermission('academic.records.view-all'), 404);

        return $issue;
    }

    /** @return list<array{id:int,record_type:string,issue_number:int,status:string,issued_at:string|null,verification_code:string}> */
    public function recordsFor(User $user): array
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.records.view-own') || $membership->hasPermission('academic.records.guardian.view'), 403);
        $guardianStudentIds = $membership->hasPermission('academic.records.guardian.view')
            ? SisStudentGuardian::query()->where('guardian_user_id', $user->getKey())->where('tenant_id', $membership->tenant_id)->where('authority_status', 'verified')->whereNull('revoked_at')->where('can_view_profile', true)->pluck('student_id')
            : collect();
        $studentIds = SisStudent::query()->where('institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('home_campus_id', $membership->campus_id))
            ->where(function ($query) use ($membership, $user, $guardianStudentIds): void {
                if ($membership->hasPermission('academic.records.view-own')) {
                    $query->where('user_id', $user->getKey());
                    if ($guardianStudentIds->isNotEmpty()) {
                        $query->orWhereIn('id', $guardianStudentIds);
                    }
                } else {
                    $query->whereIn('id', $guardianStudentIds);
                }
            })->pluck('id');

        return AcademicRecordIssue::query()->whereIn('student_id', $studentIds)->where('status', 'issued')->orderByDesc('issued_at')->get()
            ->map(fn (AcademicRecordIssue $issue): array => ['id' => (int) $issue->getKey(), 'record_type' => $issue->record_type, 'issue_number' => (int) $issue->issue_number, 'status' => $issue->status, 'issued_at' => $issue->issued_at === null ? null : CarbonImmutable::parse((string) $issue->issued_at)->toIso8601String(), 'verification_code' => $issue->record_type === 'certificate' ? $issue->verification_code : ''])->all();
    }

    /** @return list<array{student:string,course:string,period:string,section:string,grade:string,grade_points:?string,credits:?string,publication_number:int,published_at:string}> */
    public function guardianPublishedGrades(User $user): array
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.records.guardian.view'), 403);

        $authorizedStudents = SisStudent::query()
            ->join('sis_student_guardians as links', function ($join): void {
                $join->on('links.student_id', '=', 'sis_students.id')
                    ->on('links.tenant_id', '=', 'sis_students.tenant_id');
            })
            ->where('sis_students.tenant_id', $membership->tenant_id)
            ->where('sis_students.institution_id', $membership->institution_id)
            ->where('links.guardian_user_id', $user->getKey())
            ->where('links.authority_status', 'verified')
            ->whereNull('links.revoked_at')
            ->where('links.can_view_profile', true)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('sis_students.home_campus_id', $membership->campus_id))
            ->select('sis_students.id');

        return DB::table('academic_grade_publication_entries as e')
            ->join('course_enrollments as ce', function ($join): void {
                $join->on('ce.id', '=', 'e.enrollment_id')->on('ce.tenant_id', '=', 'e.tenant_id');
            })
            ->join('sis_students as st', function ($join): void {
                $join->on('st.id', '=', 'ce.student_id')->on('st.tenant_id', '=', 'ce.tenant_id');
            })
            ->join('academic_grade_publications as pub', function ($join): void {
                $join->on('pub.id', '=', 'e.publication_id')->on('pub.tenant_id', '=', 'e.tenant_id');
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
            ->join('academic_courses as c', function ($join): void {
                $join->on('c.id', '=', 'o.academic_course_id')->on('c.tenant_id', '=', 'o.tenant_id');
            })
            ->join('academic_periods as p', function ($join): void {
                $join->on('p.id', '=', 'o.academic_period_id')->on('p.tenant_id', '=', 'o.tenant_id');
            })
            ->where('e.tenant_id', $membership->tenant_id)
            ->where('b.institution_id', $membership->institution_id)
            ->where('b.status', 'published')
            ->whereIn('ce.student_id', $authorizedStudents)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('s.campus_id', $membership->campus_id))
            ->whereRaw('e.publication_id = (select max(e2.publication_id) from academic_grade_publication_entries e2 where e2.tenant_id = e.tenant_id and e2.gradebook_id = e.gradebook_id and e2.enrollment_id = e.enrollment_id)')
            ->orderBy('st.student_number')->orderBy('p.starts_on')->orderBy('c.code')
            ->get(['st.student_number as student', 'c.title as course', 'p.name as period', 's.section_code as section', 'e.scale_result as grade', 'e.percentage', 'e.grade_points', 'ce.credits_snapshot as credits', 'pub.publication_number', 'pub.published_at'])
            ->map(fn ($row): array => [
                'student' => (string) $row->student,
                'course' => (string) $row->course,
                'period' => (string) $row->period,
                'section' => (string) $row->section,
                'grade' => (string) ($row->grade ?? $row->percentage.'%'),
                'grade_points' => $row->grade_points === null ? null : (string) $row->grade_points,
                'credits' => $row->credits === null ? null : (string) $row->credits,
                'publication_number' => (int) $row->publication_number,
                'published_at' => CarbonImmutable::parse((string) $row->published_at)->toIso8601String(),
            ])->all();
    }

    /** @return list<array{course:string,period:string,section:string,grade:string,grade_points:?string,credits:?string}> */
    private function publishedGrades(int $tenantId, int $institutionId, int $studentId, ?int $periodId, ?int $campusId): array
    {
        return DB::table('academic_grade_publication_entries as e')
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
            ->join('academic_courses as c', function ($join): void {
                $join->on('c.id', '=', 'o.academic_course_id')->on('c.tenant_id', '=', 'o.tenant_id');
            })
            ->join('academic_periods as p', function ($join): void {
                $join->on('p.id', '=', 'o.academic_period_id')->on('p.tenant_id', '=', 'o.tenant_id');
            })
            ->where('e.tenant_id', $tenantId)->where('ce.student_id', $studentId)->where('b.institution_id', $institutionId)
            ->when($periodId !== null, fn ($query) => $query->where('o.academic_period_id', $periodId))
            ->when($campusId !== null, fn ($query) => $query->where('s.campus_id', $campusId))
            ->whereRaw('e.publication_id = (select max(e2.publication_id) from academic_grade_publication_entries e2 where e2.tenant_id = e.tenant_id and e2.gradebook_id = e.gradebook_id and e2.enrollment_id = e.enrollment_id)')
            ->orderBy('p.starts_on')->orderBy('c.code')
            ->get(['c.title as course', 'p.name as period', 's.section_code as section', 'e.scale_result as grade', 'e.grade_points', 'ce.credits_snapshot as credits'])
            ->map(fn ($row): array => ['course' => (string) $row->course, 'period' => (string) $row->period, 'section' => (string) $row->section, 'grade' => (string) $row->grade, 'grade_points' => $row->grade_points === null ? null : (string) $row->grade_points, 'credits' => $row->credits === null ? null : (string) $row->credits])->all();
    }

    private function assertSourcesCurrent(AcademicRecordIssue $issue, ?int $campusId): void
    {
        $membership = $this->context->membership();
        if ($issue->record_type === 'certificate') {
            abort_unless($issue->student->status === 'active' && DB::table('course_enrollments')->where('tenant_id', $membership->tenant_id)->where('student_id', $issue->student_id)->where('status', 'confirmed')->exists(), 409, 'Certificate eligibility changed before approval or issuance.');
            $minimum = $issue->template->configuration['minimum_cumulative_gpa'] ?? null;
            if ($minimum !== null) {
                $gpa = AcademicGpaSnapshot::query()->where('student_id', $issue->student_id)->where('calculation_type', 'cumulative')->orderByDesc('calculated_at')->value('gpa');
                abort_unless($gpa !== null && (float) $gpa >= (float) $minimum, 409, 'Certificate GPA eligibility changed before approval or issuance.');
            }

            return;
        }
        $currentCourses = $this->publishedGrades((int) $membership->tenant_id, (int) $membership->institution_id, (int) $issue->student_id, $issue->academic_period_id === null ? null : (int) $issue->academic_period_id, $campusId);
        $snapshot = $issue->record_snapshot;
        if (json_encode($currentCourses, JSON_THROW_ON_ERROR) !== json_encode($snapshot['courses'] ?? [], JSON_THROW_ON_ERROR)) {
            throw new ConflictHttpException('Published grades changed after this record draft. Prepare a new version before approval or issuance.');
        }
        $gpaSnapshotId = $snapshot['gpa']['snapshot_id'] ?? null;
        if ($gpaSnapshotId !== null) {
            $latestGpaId = AcademicGpaSnapshot::query()->where('student_id', $issue->student_id)->where('calculation_type', $issue->academic_period_id === null ? 'cumulative' : 'term')->when($issue->academic_period_id !== null, fn ($query) => $query->where('academic_period_id', $issue->academic_period_id))->orderByDesc('calculated_at')->value('id');
            if ((int) $latestGpaId !== (int) $gpaSnapshotId) {
                throw new ConflictHttpException('The GPA snapshot changed after this record draft. Prepare a new version before approval or issuance.');
            }
        }
    }

    private function queueRecordNotification(AcademicRecordIssue $issue, string $eventType): void
    {
        DB::table('quiz_outbox_events')->insertOrIgnore([
            'tenant_id' => $issue->tenant_id,
            'event_key' => $eventType.':'.$issue->getKey().':'.$issue->issue_number,
            'event_type' => $eventType,
            'safe_payload' => json_encode(['record_issue_id' => (int) $issue->getKey()], JSON_THROW_ON_ERROR),
            'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function queueAcademicEvent(int $tenantId, string $eventType, int $subjectId, string $payloadKey = 'record_issue_id'): void
    {
        DB::table('quiz_outbox_events')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'event_key' => $eventType.':'.$subjectId,
            'event_type' => $eventType,
            'safe_payload' => json_encode([$payloadKey => $subjectId], JSON_THROW_ON_ERROR),
            'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
