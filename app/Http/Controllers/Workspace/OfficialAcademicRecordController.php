<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Grading\AcademicRecordPdf;
use App\Domain\Grading\CertificateVerificationQr;
use App\Domain\Grading\OfficialAcademicRecordWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicRecordIssue;
use App\Models\AcademicRecordTemplate;
use App\Models\AcademicReportCardComment;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class OfficialAcademicRecordController
{
    public function index(Tenant $tenant, TenantContext $context): Response
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.records.issue') || $membership->hasPermission('academic.records.review'), 403);
        $students = DB::table('sis_students')->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)->where('status', 'active')
            ->when($membership->campus_id !== null, fn ($query) => $query->where('home_campus_id', $membership->campus_id))
            ->orderBy('student_number')->limit(500)->get(['id', 'student_number', 'legal_first_name', 'legal_last_name']);
        $periods = DB::table('academic_periods as p')->join('academic_years as y', function ($join): void {
            $join->on('y.id', '=', 'p.academic_year_id')->on('y.tenant_id', '=', 'p.tenant_id');
        })
            ->where('p.tenant_id', $membership->tenant_id)->where('y.institution_id', $membership->institution_id)->orderByDesc('p.starts_on')->get(['p.id', 'p.name', 'p.code']);
        $templates = AcademicRecordTemplate::query()->where('institution_id', $membership->institution_id)->orderByDesc('created_at')->get()
            ->map(fn (AcademicRecordTemplate $template): array => ['id' => (int) $template->getKey(), 'record_type' => $template->record_type, 'name' => $template->name, 'template_key' => $template->template_key, 'version' => (int) $template->version, 'status' => $template->status])->values();
        $issues = AcademicRecordIssue::query()->with('student')->where('institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($query) => $query->whereHas('student', fn ($students) => $students->where('home_campus_id', $membership->campus_id)))
            ->orderByDesc('created_at')->limit(100)->get()
            ->map(fn (AcademicRecordIssue $issue): array => ['id' => (int) $issue->getKey(), 'student' => $issue->student->student_number, 'record_type' => $issue->record_type, 'issue_number' => (int) $issue->issue_number, 'status' => $issue->status, 'verification_code' => $issue->verification_code, 'created_by' => (int) $issue->created_by])->values();

        $comments = AcademicReportCardComment::query()->with(['student', 'section'])->where('institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($query) => $query->whereHas('section', fn ($sections) => $sections->where('campus_id', $membership->campus_id)))
            ->where('status', 'submitted')->orderBy('created_at')->limit(100)->get()
            ->map(fn (AcademicReportCardComment $comment): array => ['id' => (int) $comment->getKey(), 'student' => (string) $comment->student->student_number, 'section' => (string) $comment->section->section_code, 'comment' => $comment->comment, 'version' => (int) $comment->version])->values();

        return Inertia::render('workspace/academics/records/index', ['tenant' => ['slug' => $tenant->slug], 'students' => $students, 'periods' => $periods, 'templates' => $templates, 'issues' => $issues, 'comments' => $comments, 'permissions' => ['issue' => $membership->hasPermission('academic.records.issue'), 'review' => $membership->hasPermission('academic.records.review')]]);
    }

    public function teacherComments(Tenant $tenant, Request $request, TenantContext $context, OfficialAcademicRecordWorkflow $workflow): Response
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.grades.book.manage'), 403);
        $assigned = DB::table('course_section_instructors as i')->join('course_sections as s', function ($join): void {
            $join->on('s.id', '=', 'i.section_id')->on('s.tenant_id', '=', 'i.tenant_id');
        })->join('course_offerings as o', function ($join): void {
            $join->on('o.id', '=', 's.offering_id')->on('o.tenant_id', '=', 's.tenant_id');
        })->join('course_enrollments as e', function ($join): void {
            $join->on('e.section_id', '=', 's.id')->on('e.tenant_id', '=', 's.tenant_id');
        })->join('sis_students as st', function ($join): void {
            $join->on('st.id', '=', 'e.student_id')->on('st.tenant_id', '=', 'e.tenant_id');
        })->where('i.tenant_id', $membership->tenant_id)->where('i.user_id', $request->user()->getKey())
            ->when($membership->campus_id !== null, fn ($query) => $query->where('s.campus_id', $membership->campus_id))->where('e.status', 'confirmed')
            ->orderBy('o.academic_period_id')->orderBy('s.section_code')->orderBy('st.student_number')
            ->get(['s.id as section_id', 's.section_code as section', 'o.academic_period_id as period_id', 'st.id as student_id', 'st.student_number', 'st.legal_first_name', 'st.legal_last_name']);

        return Inertia::render('workspace/academics/records/teacher-comments', [
            'tenant' => ['slug' => $tenant->slug],
            'assignments' => $assigned->map(fn ($row): array => ['section_id' => (int) $row->section_id, 'section' => $row->section, 'period_id' => (int) $row->period_id, 'student_id' => (int) $row->student_id, 'student_number' => $row->student_number, 'student_name' => trim($row->legal_first_name.' '.$row->legal_last_name)])->values(),
            'comments' => $workflow->teacherCommentWorkspace($request->user()),
        ]);
    }

    public function submitTeacherComment(Tenant $tenant, Request $request, OfficialAcademicRecordWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['student_id' => ['required', 'integer', 'min:1'], 'academic_period_id' => ['required', 'integer', 'min:1'], 'section_id' => ['required', 'integer', 'min:1'], 'comment' => ['required', 'string', 'min:10', 'max:2000']]);
        $workflow->submitTeacherComment((int) $data['student_id'], (int) $data['academic_period_id'], (int) $data['section_id'], $data['comment'], $request->user());

        return back()->with('success', 'Comment submitted for independent academic review.');
    }

    public function decideTeacherComment(Tenant $tenant, int $commentId, Request $request, OfficialAcademicRecordWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $workflow->decideTeacherComment($commentId, $data['decision'] === 'approve', $data['reason'], $request->user());

        return back()->with('success', 'Teacher comment review recorded.');
    }

    public function createTemplate(Tenant $tenant, Request $request, OfficialAcademicRecordWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'template_key' => ['required', 'alpha_dash:ascii', 'max:64'], 'record_type' => ['required', 'in:report_card,transcript,certificate'], 'minimum_cumulative_gpa' => ['nullable', 'numeric', 'between:0,20']]);
        $workflow->createTemplate(['name' => $data['name'], 'template_key' => $data['template_key'], 'record_type' => $data['record_type'], 'configuration' => array_filter(['minimum_cumulative_gpa' => $data['minimum_cumulative_gpa'] ?? null], static fn ($value) => $value !== null)], $request->user());

        return back()->with('success', 'Academic record template draft created.');
    }

    public function activateTemplate(Tenant $tenant, int $templateId, Request $request, OfficialAcademicRecordWorkflow $workflow): RedirectResponse
    {
        $workflow->activateTemplate($templateId, $request->user());

        return back()->with('success', 'Academic record template activated.');
    }

    public function prepare(Tenant $tenant, Request $request, OfficialAcademicRecordWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['student_id' => ['required', 'integer', 'min:1'], 'template_id' => ['required', 'integer', 'min:1'], 'academic_period_id' => ['nullable', 'integer', 'min:1'], 'teacher_comment' => ['prohibited']]);
        $issue = $workflow->prepareIssue((int) $data['student_id'], (int) $data['template_id'], isset($data['academic_period_id']) ? (int) $data['academic_period_id'] : null, $data['teacher_comment'] ?? null, $request->user());

        return back()->with('success', "Official record draft {$issue->issue_number} created; source snapshot is fixed.");
    }

    public function submit(Tenant $tenant, int $issueId, Request $request, OfficialAcademicRecordWorkflow $workflow): RedirectResponse
    {
        $workflow->submit($issueId, $request->user());

        return back()->with('success', 'Academic record submitted for independent approval.');
    }

    public function decide(Tenant $tenant, int $issueId, Request $request, OfficialAcademicRecordWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $workflow->decide($issueId, $data['decision'] === 'approve', $data['reason'], $request->user());

        return back()->with('success', 'Academic record review decision recorded.');
    }

    public function issue(Tenant $tenant, int $issueId, Request $request, OfficialAcademicRecordWorkflow $workflow): RedirectResponse
    {
        $workflow->issue($issueId, $request->user());

        return back()->with('success', 'Approved academic record issued.');
    }

    public function revoke(Tenant $tenant, int $issueId, Request $request, OfficialAcademicRecordWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $workflow->revoke($issueId, $data['reason'], $request->user());

        return back()->with('success', 'Academic record revoked. Historical evidence is retained.');
    }

    public function myRecords(Tenant $tenant, Request $request, OfficialAcademicRecordWorkflow $workflow): Response
    {
        return Inertia::render('workspace/academics/records/my-records', ['tenant' => ['slug' => $tenant->slug], 'records' => $workflow->recordsFor($request->user())]);
    }

    public function download(Tenant $tenant, int $issueId, Request $request, OfficialAcademicRecordWorkflow $workflow, AcademicRecordPdf $pdf, CertificateVerificationQr $certificateQr): mixed
    {
        $issue = $workflow->downloadable($issueId, $request->user());
        $snapshot = $issue->record_snapshot;
        $issuedOn = $issue->issued_at === null ? '' : CarbonImmutable::parse((string) $issue->issued_at)->toDateString();
        $lines = [strtoupper(str_replace('_', ' ', $issue->record_type)), $snapshot['institution']['name'] ?? 'Institution', 'Student: '.($snapshot['student']['legal_name'] ?? ''), 'Student number: '.($snapshot['student']['student_number'] ?? ''), 'Issue number: '.$issue->issue_number, 'Issued: '.$issuedOn, ''];
        if ($issue->record_type === 'certificate') {
            $lines[] = 'Public verification code: '.$issue->verification_code;
            $lines[] = 'Verify at: '.route('academic-records.verify-code', ['verificationCode' => $issue->verification_code]);
            $lines[] = 'Scan the QR code on this certificate to verify its current status.';
        }
        foreach (($snapshot['courses'] ?? []) as $row) {
            $lines[] = implode(' | ', [$row['period'] ?? '', $row['course'] ?? '', $row['section'] ?? '', $row['grade'] ?? '', $row['grade_points'] ?? '-', $row['credits'] ?? '-']);
        }
        if (isset($snapshot['gpa'])) {
            $lines[] = '';
            $lines[] = 'GPA/CGPA: '.($snapshot['gpa']['value'] ?? '').' | Credits: '.($snapshot['gpa']['credits'] ?? '').' | Standing: '.($snapshot['gpa']['standing'] ?? 'not assigned');
        }
        if (! empty($snapshot['teacher_comment'])) {
            $lines[] = '';
            foreach ((array) $snapshot['teacher_comment'] as $comment) {
                $lines[] = 'Instructor comment: '.(is_array($comment) ? (string) ($comment['comment'] ?? '') : (string) $comment);
            }
        }
        $filename = $issue->record_type.'-'.$issue->issue_number.'.pdf';

        $qrMatrix = $issue->record_type === 'certificate'
            ? $certificateQr->matrix(route('academic-records.verify-code', ['verificationCode' => $issue->verification_code]))
            : null;

        return response($pdf->render($lines, $qrMatrix), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function verifyCode(string $verificationCode, OfficialAcademicRecordWorkflow $workflow): Response
    {
        return Inertia::render('public/academic-records/verify', ['verification' => $workflow->verify($verificationCode)]);
    }

    public function verify(Request $request, OfficialAcademicRecordWorkflow $workflow): Response
    {
        $verification = null;
        if ($request->isMethod('post')) {
            $data = $request->validate(['verification_code' => ['required', 'uuid']]);
            $verification = $workflow->verify($data['verification_code']);
        }

        return Inertia::render('public/academic-records/verify', ['verification' => $verification]);
    }
}
