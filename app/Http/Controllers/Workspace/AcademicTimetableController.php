<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\Timetabling\TimetableOccurrenceProjector;
use App\Domain\Academic\Timetabling\TimetableWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicBuilding;
use App\Models\AcademicCalendarException;
use App\Models\AcademicInstructorAvailability;
use App\Models\AcademicRoom;
use App\Models\AcademicRoomBlock;
use App\Models\AcademicSchedulingSetting;
use App\Models\AcademicTimetable;
use App\Models\AcademicTimetableChange;
use App\Models\AcademicTimetableMeeting;
use App\Models\Campus;
use App\Models\Institution;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AcademicTimetableController extends Controller
{
    public function index(Request $request, TenantContext $context): Response
    {
        $institution = Institution::query()->firstOrFail();
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.view'), 403);
        $campusId = $membership->campus_id === null ? null : (int) $membership->campus_id;
        $timetables = AcademicTimetable::query()->where('institution_id', $institution->getKey())
            ->when($campusId, fn ($query) => $query->where('campus_id', $campusId))
            ->with('meetings')->withCount('meetings')->orderByDesc('academic_period_id')->paginate(15)->withQueryString();
        $campuses = Campus::query()->where('institution_id', $institution->getKey())->where('status', 'active')->when($campusId, fn ($query) => $query->whereKey($campusId))->orderBy('name')->get(['id', 'name', 'code']);
        $rooms = AcademicRoom::query()->where('institution_id', $institution->getKey())->where('status', 'active')->when($campusId, fn ($query) => $query->where('campus_id', $campusId))->orderBy('name')->get();
        $buildings = AcademicBuilding::query()->where('institution_id', $institution->getKey())->where('status', 'active')->when($campusId, fn ($query) => $query->where('campus_id', $campusId))->orderBy('name')->get();
        $settings = AcademicSchedulingSetting::query()->where('institution_id', $institution->getKey())->when($campusId, fn ($query) => $query->where(fn ($scope) => $scope->where('campus_id', $campusId)->orWhereNull('campus_id')))->orderByRaw('campus_id IS NULL')->first();
        $years = DB::table('academic_years')->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey())->where('status', 'active')->orderByDesc('starts_on')->get(['id', 'name', 'starts_on', 'ends_on']);
        $periods = DB::table('academic_periods as p')->join('academic_years as y', function ($join): void {
            $join->on('y.id', '=', 'p.academic_year_id')->on('y.tenant_id', '=', 'p.tenant_id');
        })->where('p.tenant_id', $context->tenant()->getKey())->where('y.institution_id', $institution->getKey())->where('p.status', 'active')->orderByDesc('p.starts_on')->get(['p.id', 'p.academic_year_id', 'p.name', 'p.starts_on', 'p.ends_on']);
        $sectionRows = DB::table('course_sections as s')->join('course_offerings as o', function ($join): void {
            $join->on('o.id', '=', 's.offering_id')->on('o.tenant_id', '=', 's.tenant_id');
        })
            ->join('academic_courses as c', function ($join): void {
                $join->on('c.id', '=', 'o.academic_course_id')->on('c.tenant_id', '=', 'o.tenant_id');
            })
            ->join('course_section_instructors as si', function ($join): void {
                $join->on('si.section_id', '=', 's.id')->on('si.tenant_id', '=', 's.tenant_id');
            })
            ->join('users as u', 'u.id', '=', 'si.user_id')->where('s.tenant_id', $context->tenant()->getKey())->where('o.institution_id', $institution->getKey())
            ->where('s.status', 'open')->when($campusId, fn ($query) => $query->where('s.campus_id', $campusId))
            ->orderBy('c.title')->orderBy('s.section_code')->get(['s.id', 's.section_code', 's.capacity', 's.campus_id', 'o.academic_period_id', 'c.title as course_title', 'c.code as course_code', 'u.id as instructor_user_id', 'u.name as instructor_name']);

        return Inertia::render('workspace/academics/timetables', [
            'tenant' => ['slug' => $context->tenant()->slug], 'institution' => ['name' => $institution->display_name, 'timezone' => $institution->timezone],
            'canManage' => $membership->hasPermission('academic.calendar.manage'), 'canApprove' => $membership->hasPermission('academic.policy.approve'),
            'campusScope' => $campusId, 'campuses' => $campuses, 'years' => $years, 'periods' => $periods, 'sections' => $sectionRows,
            'buildings' => $buildings, 'rooms' => $rooms, 'settings' => $settings, 'closures' => AcademicCalendarException::query()->where('institution_id', $institution->getKey())->orderBy('starts_on')->limit(100)->get(),
            'availability' => AcademicInstructorAvailability::query()->where('institution_id', $institution->getKey())->where('status', 'active')->latest()->limit(100)->get(),
            'roomBlocks' => AcademicRoomBlock::query()->where('tenant_id', $context->tenant()->getKey())->where('status', 'active')->with('room')->latest()->limit(100)->get(),
            'timetables' => $timetables, 'filters' => $request->only('status'),
            'changes' => AcademicTimetableChange::query()->where('timetable_id', '>', 0)->whereHas('timetable', fn ($query) => $query->where('institution_id', $institution->getKey())->where('status', 'published')->when($campusId, fn ($scope) => $scope->where('campus_id', $campusId)))->with('meeting.section.offering.course')->whereIn('status', ['pending', 'approved', 'rejected'])->latest()->limit(100)->get(),
        ]);
    }

    public function saveSettings(Request $request, Tenant $tenant, TimetableWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['campus_id' => ['nullable', 'integer'], 'timezone' => ['nullable', 'timezone'], 'teaching_days' => ['required', 'array', 'min:1', 'max:7'], 'teaching_days.*' => ['required', 'integer', 'between:1,7'], 'opens_at' => ['required', 'date_format:H:i'], 'closes_at' => ['required', 'date_format:H:i', 'after:opens_at'], 'slot_minutes' => ['required', 'integer', 'between:15,240'], 'max_weekly_teaching_minutes' => ['required', 'integer', 'between:60,10080'], 'break_windows' => ['nullable', 'array', 'max:8'], 'break_windows.*.starts_at' => ['required', 'date_format:H:i'], 'break_windows.*.ends_at' => ['required', 'date_format:H:i', 'after:break_windows.*.starts_at']]);
        $workflow->saveSettings($data, $request->user());

        return back()->with('status', 'Scheduling rules saved.');
    }

    public function storeBuilding(Request $request, Tenant $tenant, TimetableWorkflow $workflow): RedirectResponse
    {
        $campusId = (int) $request->input('campus_id');
        $data = $request->validate(['campus_id' => ['required', 'integer'], 'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_buildings', 'code')->where('tenant_id', $tenant->getKey())->where('campus_id', $campusId)], 'name' => ['required', 'string', 'max:140'], 'address' => ['nullable', 'string', 'max:1000']]);
        $workflow->createBuilding($data, $request->user());

        return back()->with('status', 'Building created.');
    }

    public function storeRoom(Request $request, Tenant $tenant, TimetableWorkflow $workflow): RedirectResponse
    {
        $campusId = (int) $request->input('campus_id');
        $data = $request->validate(['campus_id' => ['required', 'integer'], 'building_id' => ['nullable', 'integer'], 'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('academic_rooms', 'code')->where('tenant_id', $tenant->getKey())->where('campus_id', $campusId)], 'name' => ['required', 'string', 'max:120'], 'room_type' => ['required', Rule::in(['classroom', 'lecture_hall', 'laboratory', 'seminar', 'examination', 'online'])], 'capacity' => ['required', 'integer', 'between:1,5000'], 'features' => ['nullable', 'array', 'max:20'], 'features.*' => ['string', 'max:40']]);
        $workflow->createRoom($data, $request->user());

        return back()->with('status', 'Room created.');
    }

    public function storeRoomBlock(Request $request, Tenant $tenant, TimetableWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['room_id' => ['required', 'integer'], 'starts_at_utc' => ['required', 'date_format:Y-m-d\\TH:i:sP'], 'ends_at_utc' => ['required', 'date_format:Y-m-d\\TH:i:sP', 'after:starts_at_utc'], 'block_type' => ['required', Rule::in(['maintenance', 'closure', 'reservation'])], 'reason' => ['required', 'string', 'max:500']]);
        $workflow->blockRoom($data, $request->user());

        return back()->with('status', 'Room unavailability recorded.');
    }

    public function storeAvailability(Request $request, Tenant $tenant, TimetableWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['campus_id' => ['nullable', 'integer'], 'user_id' => ['required', 'integer'], 'weekdays' => ['required', 'array', 'min:1', 'max:7'], 'weekdays.*' => ['integer', 'between:1,7'], 'effective_from' => ['required', 'date'], 'effective_until' => ['required', 'date', 'after_or_equal:effective_from'], 'starts_at' => ['required', 'date_format:H:i'], 'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'], 'availability_type' => ['required', Rule::in(['available', 'unavailable'])]]);
        $workflow->saveAvailability($data, $request->user());

        return back()->with('status', 'Instructor availability recorded.');
    }

    public function storeClosure(Request $request, Tenant $tenant, TimetableWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['campus_id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:120'], 'exception_type' => ['required', Rule::in(['closure', 'holiday', 'break'])], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on']]);
        $workflow->createClosure($data, $request->user());

        return back()->with('status', 'Calendar exception recorded.');
    }

    public function storeTimetable(Request $request, Tenant $tenant, TimetableWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['academic_year_id' => ['required', 'integer'], 'academic_period_id' => ['required', 'integer'], 'campus_id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:140'], 'timezone' => ['nullable', 'timezone'], 'independent_review_required' => ['boolean']]);
        $timetable = $workflow->createTimetable($data, $request->user());

        return back()->with('status', "Draft timetable {$timetable->name} created.");
    }

    public function previewMeeting(Request $request, Tenant $tenant, int $timetableId, TimetableWorkflow $workflow): JsonResponse
    {
        $data = $this->meetingRules($request);
        $timetable = AcademicTimetable::query()->findOrFail($timetableId);
        $ignoreId = $request->integer('meeting_id') ?: null;
        if ($ignoreId !== null) {
            AcademicTimetableMeeting::query()->where('timetable_id', $timetable->getKey())->findOrFail($ignoreId);
        }

        return response()->json(['conflicts' => $workflow->previewMeeting($timetable, $data, $ignoreId)]);
    }

    public function storeMeeting(Request $request, Tenant $tenant, int $timetableId, TimetableWorkflow $workflow): RedirectResponse
    {
        $data = $this->meetingRules($request);
        $timetable = AcademicTimetable::query()->findOrFail($timetableId);
        $workflow->createMeeting($timetable, $data, $request->user());

        return back()->with('status', 'Recurring class meeting added to the draft timetable.');
    }

    public function updateMeeting(Request $request, Tenant $tenant, int $meetingId, TimetableWorkflow $workflow): RedirectResponse
    {
        $data = $this->meetingRules($request);
        $workflow->updateMeeting(AcademicTimetableMeeting::query()->findOrFail($meetingId), $data, $request->user());

        return back()->with('status', 'Recurring series updated in the draft timetable. The published version remains unchanged.');
    }

    public function cancelSeries(Request $request, Tenant $tenant, int $meetingId, TimetableWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:1000']]);
        $workflow->cancelMeetingSeries(AcademicTimetableMeeting::query()->findOrFail($meetingId), $data['reason'], $request->user());

        return back()->with('status', 'Recurring series canceled in the draft timetable. The published version remains unchanged.');
    }

    public function submit(Request $request, Tenant $tenant, int $timetableId, TimetableWorkflow $workflow): RedirectResponse
    {
        $workflow->submit(AcademicTimetable::query()->findOrFail($timetableId), $request->user());

        return back()->with('status', 'Timetable submitted for independent review.');
    }

    public function revise(Request $request, Tenant $tenant, int $timetableId, TimetableWorkflow $workflow): RedirectResponse
    {
        $revision = $workflow->revise(AcademicTimetable::query()->findOrFail($timetableId), $request->user());

        return back()->with('status', "Revision {$revision->version} created as a draft.");
    }

    public function decide(Request $request, Tenant $tenant, int $timetableId, TimetableWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'return'])], 'note' => ['required', 'string', 'min:8', 'max:2000']]);
        $workflow->decide(AcademicTimetable::query()->findOrFail($timetableId), $data['decision'], $data['note'], $request->user());

        return back()->with('status', 'Timetable review recorded.');
    }

    public function publish(Request $request, Tenant $tenant, int $timetableId, TimetableWorkflow $workflow): RedirectResponse
    {
        $workflow->publish(AcademicTimetable::query()->findOrFail($timetableId), $request->user());

        return back()->with('status', 'Approved timetable version published.');
    }

    public function requestChange(Request $request, Tenant $tenant, int $meetingId, TimetableWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'occurrence_date' => ['required', 'date'],
            'change_type' => ['required', Rule::in(['reschedule', 'substitution', 'cancellation', 'makeup'])],
            'replacement_date' => ['nullable', 'required_unless:change_type,cancellation', 'date'],
            'starts_at_local' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'between:10,480'],
            'instructor_user_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);
        $workflow->requestChange(AcademicTimetableMeeting::query()->findOrFail($meetingId), $data, $request->user());

        return back()->with('status', 'Schedule change submitted for independent approval.');
    }

    public function decideChange(Request $request, Tenant $tenant, int $changeId, TimetableWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])]]);
        $workflow->decideChange(AcademicTimetableChange::query()->findOrFail($changeId), $data['decision'], $request->user());

        return back()->with('status', 'Schedule change decision recorded.');
    }

    public function personal(Request $request, Tenant $tenant, TenantContext $context): Response
    {
        $membership = $context->membership();
        $isStudent = $membership->hasPermission('enrollment.student.view');
        abort_unless($isStudent || $membership->hasPermission('enrollment.instructor.roster.view'), 403);
        $query = AcademicTimetableMeeting::query()->where('status', 'scheduled')->whereHas('timetable', fn ($timetables) => $timetables->where('status', 'published'))
            ->whereHas('section', function ($sections) use ($membership, $isStudent): void {
                if ($isStudent) {
                    $sections->whereHas('enrollments', fn ($enrollments) => $enrollments->where('student_id', DB::table('sis_students')->where('tenant_id', $membership->tenant_id)->where('user_id', $membership->user_id)->value('id'))->where('status', 'confirmed'));
                }
                if (! $isStudent) {
                    $sections->whereExists(fn ($assignment) => $assignment->selectRaw('1')->from('course_section_instructors')->whereColumn('course_section_instructors.section_id', 'course_sections.id')->where('course_section_instructors.tenant_id', $membership->tenant_id)->where('course_section_instructors.user_id', $membership->user_id));
                }
            })->with(['timetable', 'section.offering.course', 'instructor', 'room'])->orderBy('starts_on');
        $meetings = $query->limit(200)->get();

        $scheduleEvents = $meetings->flatMap(fn (AcademicTimetableMeeting $meeting): array => app(TimetableOccurrenceProjector::class)->project($meeting))
            ->filter(fn (array $event): bool => $event['start']->gte(now('UTC')))
            ->take(200)->map(fn (array $event): array => ['id' => $event['key'], 'date' => $event['date'], 'starts_at' => $event['start']->toIso8601String(), 'ends_at' => $event['end']->toIso8601String(), 'course' => $event['meeting']->section->offering->course->title, 'course_code' => $event['meeting']->section->offering->course->code, 'section_code' => $event['meeting']->section->section_code, 'instructor' => $event['instructor_name'], 'location' => $event['location'], 'timetable_timezone' => $event['meeting']->timetable->timezone])->values();
        $changeHistory = AcademicTimetableChange::query()->whereIn('meeting_id', $meetings->pluck('id'))->whereIn('status', ['approved', 'pending'])->orderBy('occurrence_date')->get(['id', 'meeting_id', 'change_type', 'occurrence_date', 'replacement_date', 'status', 'reason']);

        return Inertia::render('workspace/academics/my-schedule', ['tenant' => ['slug' => $tenant->slug], 'kind' => $isStudent ? 'student' : 'instructor', 'timezone' => Institution::query()->value('timezone') ?: 'UTC', 'meetings' => $meetings, 'scheduleEvents' => $scheduleEvents, 'changeHistory' => $changeHistory]);
    }

    public function export(Request $request, Tenant $tenant, TenantContext $context, TimetableOccurrenceProjector $projector): HttpResponse
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('enrollment.student.view') || $membership->hasPermission('enrollment.instructor.roster.view'), 403);
        $studentId = DB::table('sis_students')->where('tenant_id', $context->tenant()->getKey())->where('user_id', $request->user()->getKey())->value('id');
        $meetings = AcademicTimetableMeeting::query()->where('status', 'scheduled')->whereHas('timetable', fn ($query) => $query->where('status', 'published'))
            ->whereHas('section', function ($sections) use ($membership, $studentId): void {
                if ($studentId) {
                    $sections->whereHas('enrollments', fn ($enrollments) => $enrollments->where('student_id', $studentId)->where('status', 'confirmed'));
                } else {
                    $sections->whereExists(fn ($assignment) => $assignment->selectRaw('1')->from('course_section_instructors')->whereColumn('course_section_instructors.section_id', 'course_sections.id')->where('course_section_instructors.tenant_id', $membership->tenant_id)->where('course_section_instructors.user_id', $membership->user_id));
                }
            })->with(['timetable', 'section.offering.course', 'instructor', 'room'])->get();
        $events = [];
        foreach ($meetings as $meeting) {
            array_push($events, ...$projector->project($meeting));
        }
        if ($request->routeIs('tenant.academics.schedule.ics')) {
            $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//TechMentors.PK//Academic Timetable//EN', 'CALSCALE:GREGORIAN'];
            foreach ($events as $event) {
                $meeting = $event['meeting'];
                $lines[] = 'BEGIN:VEVENT';
                $lines[] = 'UID:'.$context->tenant()->slug.'-'.$meeting->getKey().'-'.$event['date'].'@techmentors.pk';
                $lines[] = 'DTSTAMP:'.now('UTC')->format('Ymd\THis\Z');
                $lines[] = 'DTSTART:'.$event['start']->format('Ymd\THis\Z');
                $lines[] = 'DTEND:'.$event['end']->format('Ymd\THis\Z');
                $lines[] = 'SUMMARY:'.str_replace(['\\', ',', ';', "\n"], ['\\\\', '\\,', '\\;', ' '], $meeting->section->offering->course->title);
                $lines[] = 'LOCATION:'.str_replace(['\\', ',', ';', "\n"], ['\\\\', '\\,', '\\;', ' '], $event['location']);
                $lines[] = 'DESCRIPTION:Instructor: '.str_replace(['\\', ',', ';', "\n"], ['\\\\', '\\,', '\\;', ' '], $event['instructor_name']);
                $lines[] = 'END:VEVENT';
            }
            $lines[] = 'END:VCALENDAR';

            return response(implode("\r\n", $lines)."\r\n", 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="timetable.ics"', 'Cache-Control' => 'private, no-store']);
        }
        $csv = fopen('php://temp', 'w+');
        fputcsv($csv, ['date', 'start_utc', 'end_utc', 'course', 'section', 'instructor', 'location']);
        foreach ($events as $event) {
            fputcsv($csv, [$event['date'], $event['start']->toIso8601String(), $event['end']->toIso8601String(), $event['meeting']->section->offering->course->title, $event['meeting']->section->section_code, $event['instructor_name'], $event['location']]);
        }
        rewind($csv);
        $contents = stream_get_contents($csv) ?: '';
        fclose($csv);

        return response($contents, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="timetable.csv"', 'Cache-Control' => 'private, no-store']);
    }

    /** @return array<string,mixed> */
    private function meetingRules(Request $request): array
    {
        return $request->validate(['section_id' => ['required', 'integer'], 'instructor_user_id' => ['required', 'integer'], 'room_id' => ['nullable', 'integer'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'], 'weekdays' => ['required', 'array', 'min:1', 'max:7'], 'weekdays.*' => ['integer', 'between:1,7'], 'starts_at_local' => ['required', 'date_format:H:i'], 'duration_minutes' => ['required', 'integer', 'between:10,480'], 'delivery_mode' => ['required', Rule::in(['in_person', 'online', 'hybrid'])], 'online_location' => ['nullable', 'url', 'max:500'], 'series_key' => ['nullable', 'string', 'max:64']]);
    }
}
