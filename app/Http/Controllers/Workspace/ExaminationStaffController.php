<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Examinations\ExamInvigilatorWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicRoom;
use App\Models\ExamAttendanceRecord;
use App\Models\ExamCandidate;
use App\Models\ExamCandidateAccommodation;
use App\Models\ExamCycle;
use App\Models\ExamInvigilatorAssignment;
use App\Models\ExamSession;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ExaminationStaffController extends Controller
{
    public function respondToDuty(Request $request, Tenant $tenant, int $assignmentId, ExamInvigilatorWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['accept', 'decline'])]]);
        $workflow->respond($assignmentId, (string) $data['decision'], $request->user());

        return back()->with('success', $data['decision'] === 'accept' ? 'Examination duty accepted.' : 'Examination duty declined.');
    }

    public function assignInvigilator(Request $request, Tenant $tenant, int $sessionId, TenantContext $context, AcademicAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $tenantId = (int) $context->tenant()->getKey();
        $session = ExamSession::query()->with('cycle')->findOrFail($sessionId);
        $access->assertCampus((int) $session->campus_id);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('tenant_memberships', 'user_id')->where('tenant_id', $tenantId)->where('institution_id', $session->cycle->institution_id)->where('status', 'active')],
            'room_id' => ['nullable', 'integer', Rule::exists('academic_rooms', 'id')->where('tenant_id', $tenantId)->where('campus_id', $session->campus_id)->where('status', 'active')],
            'duty_role' => ['required', Rule::in(['chief_invigilator', 'invigilator', 'relief', 'examiner'])],
        ]);
        if (isset($data['room_id'])) {
            AcademicRoom::query()->where('tenant_id', $tenantId)->findOrFail($data['room_id']);
        }
        $assignment = DB::transaction(function () use ($session, $data, $request, $audit, $tenantId): ExamInvigilatorAssignment {
            ExamSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $membership = TenantMembership::query()->where('user_id', $data['user_id'])->where('institution_id', $session->cycle->institution_id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            if ($membership->campus_id !== null && (int) $membership->campus_id !== (int) $session->campus_id) {
                throw ValidationException::withMessages(['user_id' => 'The selected member is assigned to another campus.']);
            }
            $assignment = ExamInvigilatorAssignment::query()->firstOrNew(['session_id' => $session->getKey(), 'user_id' => $data['user_id']]);
            $assignment->forceFill(['room_id' => $data['room_id'] ?? null, 'duty_role' => $data['duty_role'], 'assigned_by' => $request->user()->getKey(), 'status' => 'assigned', 'accepted_at' => null]);
            $assignment->save();
            $audit->record('exams.invigilator.assigned', $request->user(), $tenantId, $assignment, ['session_id' => (int) $session->getKey(), 'user_id' => (int) $assignment->user_id, 'duty_role' => $assignment->duty_role, 'room_id' => $assignment->room_id]);
            DB::table('quiz_outbox_events')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'event_key' => 'exam.invigilator.assigned:'.$assignment->getKey(),
                'event_type' => 'exam.invigilator_assigned',
                'safe_payload' => json_encode(['assignment_id' => (int) $assignment->getKey()], JSON_THROW_ON_ERROR),
                'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $assignment;
        });

        return back()->with('success', 'Examination duty assignment saved.');
    }

    public function storeAccommodation(Request $request, Tenant $tenant, int $cycleId, int $candidateId, TenantContext $context, AcademicAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $tenantId = (int) $context->tenant()->getKey();
        $cycle = ExamCycle::query()->findOrFail($cycleId);
        $access->assertCampus($cycle->campus_id === null ? null : (int) $cycle->campus_id);
        $candidate = ExamCandidate::query()->where('cycle_id', $cycle->getKey())->findOrFail($candidateId);
        $data = $request->validate([
            'accommodation_type' => ['required', Rule::in(['extra_time', 'reader', 'scribe', 'accessible_room', 'assistive_technology', 'other'])],
            'extra_time_minutes' => ['required', 'integer', 'min:0', 'max:300'],
            'confidential_details' => ['nullable', 'string', 'max:2000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $record = DB::transaction(function () use ($candidate, $cycle, $data, $request, $audit, $tenantId): ExamCandidateAccommodation {
            ExamCandidate::query()->lockForUpdate()->findOrFail($candidate->getKey());
            $nextRevision = ((int) ExamCandidateAccommodation::query()->where('candidate_id', $candidate->getKey())->where('accommodation_type', $data['accommodation_type'])->max('revision')) + 1;
            ExamCandidateAccommodation::query()->where('candidate_id', $candidate->getKey())->where('accommodation_type', $data['accommodation_type'])->where('status', 'active')->update(['status' => 'superseded', 'updated_at' => now()]);

            $record = ExamCandidateAccommodation::query()->create([
                'candidate_id' => $candidate->getKey(), 'accommodation_type' => $data['accommodation_type'], 'revision' => $nextRevision,
                'extra_time_minutes' => $data['extra_time_minutes'], 'confidential_details' => isset($data['confidential_details']) ? Str::limit(strip_tags($data['confidential_details']), 2000, '') : null,
                'authorized_by' => $request->user()->getKey(), 'authorized_at' => now(), 'expires_at' => $data['expires_at'] ?? null, 'status' => 'active',
            ]);
            $audit->record('exams.candidate.accommodation.authorized', $request->user(), $tenantId, $record, ['candidate_id' => (int) $candidate->getKey(), 'cycle_id' => (int) $cycle->getKey(), 'type' => $record->accommodation_type, 'revision' => $record->revision, 'expires_at' => isset($record->expires_at) ? Carbon::parse($record->expires_at)->toIso8601String() : null]);

            return $record;
        });

        return back()->with('success', 'Confidential examination accommodation authorized.');
    }

    public function markAttendance(Request $request, Tenant $tenant, int $sessionId, TenantContext $context, AcademicAccess $access, AuditRecorder $audit): RedirectResponse
    {
        $tenantId = (int) $context->tenant()->getKey();
        $session = ExamSession::query()->with('cycle')->findOrFail($sessionId);
        $access->assertCampus((int) $session->campus_id);
        abort_unless(in_array($session->status, ['published', 'active', 'completed'], true), 409, 'Attendance can only be recorded for a published examination session.');
        $data = $request->validate([
            'candidate_id' => ['required', 'integer', Rule::exists('exam_candidates', 'id')->where('tenant_id', $tenantId)->where('cycle_id', $session->cycle_id)],
            'attendance_status' => ['required', Rule::in(['present', 'late', 'absent', 'withdrawn'])],
            'safe_note' => ['nullable', 'string', 'max:500'],
        ]);
        $candidate = ExamCandidate::query()->where('cycle_id', $session->cycle_id)->findOrFail($data['candidate_id']);
        $membership = $context->membership();
        $assigned = ExamInvigilatorAssignment::query()->where('session_id', $session->getKey())->where('user_id', $request->user()->getKey())->where('status', 'assigned')->exists();
        abort_unless($assigned || $membership->hasPermission('exams.manage'), 403, 'Only assigned invigilators and examination managers may record attendance.');

        $attendance = DB::transaction(function () use ($session, $candidate, $data, $request, $audit, $tenantId): ExamAttendanceRecord {
            ExamSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $record = ExamAttendanceRecord::query()->where('session_id', $session->getKey())->where('candidate_id', $candidate->getKey())->lockForUpdate()->first();
            $previousStatus = $record?->attendance_status;
            $revision = ($record === null ? 0 : (int) $record->revision) + 1;
            if ($record === null) {
                $record = new ExamAttendanceRecord(['session_id' => $session->getKey(), 'candidate_id' => $candidate->getKey()]);
            }
            $record->forceFill(['attendance_status' => $data['attendance_status'], 'recording_method' => 'manual', 'recorded_by' => $request->user()->getKey(), 'recorded_at' => now(), 'safe_note' => isset($data['safe_note']) ? Str::limit(strip_tags($data['safe_note']), 500, '') : null, 'revision' => $revision]);
            $record->save();
            DB::table('exam_attendance_revisions')->insert([
                'tenant_id' => (int) app(TenantContext::class)->tenant()->getKey(), 'attendance_record_id' => $record->getKey(), 'revision_number' => $revision,
                'previous_status' => $previousStatus, 'new_status' => $data['attendance_status'], 'changed_by' => $request->user()->getKey(),
                'change_reason' => isset($data['safe_note']) ? Str::limit(strip_tags($data['safe_note']), 500, '') : null, 'created_at' => now(),
            ]);
            $audit->record('exams.candidate.attendance.recorded', $request->user(), $tenantId, $record, ['session_id' => (int) $session->getKey(), 'candidate_id' => (int) $candidate->getKey(), 'status' => $record->attendance_status, 'revision' => $record->revision]);

            return $record;
        });

        return back()->with('success', 'Candidate examination attendance recorded.');
    }
}
