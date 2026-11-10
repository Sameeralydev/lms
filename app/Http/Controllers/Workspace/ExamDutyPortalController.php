<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Examinations\ExamInvigilatorWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class ExamDutyPortalController extends Controller
{
    public function index(Tenant $tenant, Request $request, TenantContext $context, AcademicAccess $access): Response
    {
        $tenantId = (int) $context->tenant()->getKey();
        $assignments = DB::table('exam_invigilator_assignments as assignments')
            ->join('exam_sessions as sessions', function ($join): void {
                $join->on('sessions.id', '=', 'assignments.session_id')->on('sessions.tenant_id', '=', 'assignments.tenant_id');
            })
            ->join('exam_cycles as cycles', function ($join): void {
                $join->on('cycles.id', '=', 'sessions.cycle_id')->on('cycles.tenant_id', '=', 'sessions.tenant_id');
            })
            ->leftJoin('academic_rooms as rooms', function ($join): void {
                $join->on('rooms.id', '=', 'assignments.room_id')->on('rooms.tenant_id', '=', 'assignments.tenant_id');
            })
            ->where('assignments.tenant_id', $tenantId)
            ->where('assignments.user_id', $request->user()->getKey())
            ->when($access->isCampusScoped(), fn ($query) => $query->where('sessions.campus_id', $access->campusId()))
            ->orderBy('sessions.starts_at_utc')
            ->limit(100)
            ->get(['assignments.id', 'assignments.status', 'assignments.accepted_at', 'assignments.duty_role', 'sessions.title as session_title', 'sessions.starts_at_utc', 'cycles.title as cycle_title', 'rooms.name as room_name', 'rooms.code as room_code'])
            ->map(static fn (object $assignment): array => [
                'id' => (int) $assignment->id,
                'status' => (string) $assignment->status,
                'acceptedAt' => $assignment->accepted_at,
                'role' => str_replace('_', ' ', (string) $assignment->duty_role),
                'session' => (string) $assignment->session_title,
                'cycle' => (string) $assignment->cycle_title,
                'startsAt' => (string) $assignment->starts_at_utc,
                'room' => $assignment->room_name === null ? null : trim($assignment->room_name.' · '.$assignment->room_code),
            ])->all();

        return Inertia::render('workspace/academics/exam-duties', ['assignments' => $assignments]);
    }

    public function respond(Tenant $tenant, int $assignmentId, Request $request, ExamInvigilatorWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:accept,decline']]);
        $workflow->respond($assignmentId, (string) $data['decision'], $request->user());

        return back()->with('success', $data['decision'] === 'accept' ? 'Examination duty accepted.' : 'Examination duty declined.');
    }
}
