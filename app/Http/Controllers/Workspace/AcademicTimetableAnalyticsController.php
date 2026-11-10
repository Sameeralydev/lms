<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\Timetabling\TimetableConflictChecker;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicRoom;
use App\Models\AcademicTimetable;
use App\Models\AcademicTimetableChange;
use App\Models\AcademicTimetableMeeting;
use App\Models\Institution;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AcademicTimetableAnalyticsController extends Controller
{
    public function __invoke(TenantContext $context, TimetableConflictChecker $conflicts): Response
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.view'), 403);
        $institution = Institution::query()->firstOrFail();
        $campusId = $membership->campus_id;
        $published = AcademicTimetable::query()->where('institution_id', $institution->getKey())->where('status', 'published')->when($campusId, fn ($query) => $query->where('campus_id', $campusId));
        $meetings = AcademicTimetableMeeting::query()->where('status', 'scheduled')->whereHas('timetable', fn ($query) => $query->where('institution_id', $institution->getKey())->where('status', 'published')->when($campusId, fn ($scope) => $scope->where('campus_id', $campusId)));
        $activeRooms = AcademicRoom::query()->where('institution_id', $institution->getKey())->where('status', 'active')->when($campusId, fn ($query) => $query->where('campus_id', $campusId));
        $usedRooms = (clone $meetings)->whereNotNull('room_id')->distinct()->count('room_id');
        $roomCount = (clone $activeRooms)->count();
        $unscheduledSections = DB::table('course_sections as s')->join('course_offerings as o', function ($join): void {
            $join->on('o.id', '=', 's.offering_id')->on('o.tenant_id', '=', 's.tenant_id');
        })->where('s.tenant_id', $context->tenant()->getKey())->where('o.institution_id', $institution->getKey())->where('s.status', 'open')
            ->when($campusId, fn ($query) => $query->where('s.campus_id', $campusId))->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('academic_timetable_meetings as m')->join('academic_timetables as t', function ($join): void {
                    $join->on('t.id', '=', 'm.timetable_id')->on('t.tenant_id', '=', 'm.tenant_id');
                })->whereColumn('m.section_id', 's.id')->whereColumn('m.tenant_id', 's.tenant_id')->where('m.status', 'scheduled')->where('t.status', 'published');
            })->orderBy('o.offering_code')->orderBy('s.section_code')->get(['s.id', 's.section_code', 's.campus_id', 'o.offering_code']);
        $workload = (clone $meetings)->with('instructor')->get()->groupBy('instructor_user_id')->map(fn ($items): array => [
            'instructor' => $items->first()->instructor->name,
            'weekly_minutes' => $items->sum(fn (AcademicTimetableMeeting $meeting): int => count(array_unique(array_map('intval', $meeting->weekdays))) * (int) $meeting->duration_minutes),
        ])->values()->all();
        $changeCounts = AcademicTimetableChange::query()->whereIn('timetable_id', (clone $published)->select('id'))->where('status', 'approved')->select('change_type', DB::raw('count(*) as total'))->groupBy('change_type')->pluck('total', 'change_type')->all();
        $pendingChanges = AcademicTimetableChange::query()->whereIn('timetable_id', (clone $published)->select('id'))->where('status', 'pending')->count();
        $qualityFindings = $unscheduledSections->map(fn (object $section): array => [
            'key' => 'unscheduled-'.$section->id, 'severity' => 'action', 'category' => 'coverage',
            'title' => 'Open section has no published meeting', 'description' => $section->offering_code.' · '.$section->section_code,
            'href' => '/t/'.$context->tenant()->slug.'/academics/timetables#timetable-create-meeting', 'action' => 'Open timetable studio',
        ])->all();
        if ($pendingChanges > 0) {
            $qualityFindings[] = ['key' => 'pending-approvals', 'severity' => 'action', 'category' => 'review', 'title' => 'Occurrence changes need independent review', 'description' => $pendingChanges.' pending change request(s).', 'href' => '/t/'.$context->tenant()->slug.'/academics/timetables#schedule-changes-heading', 'action' => 'Review requests'];
        }
        $publishedMeetings = (clone $meetings)->with(['timetable', 'section.offering.course'])->orderBy('id')->get();
        foreach ($publishedMeetings as $meeting) {
            $timetable = $meeting->timetable;
            $candidate = $meeting->toArray() + [
                'institution_id' => (int) $timetable->institution_id, 'academic_period_id' => (int) $timetable->academic_period_id,
                'campus_id' => $timetable->campus_id === null ? null : (int) $timetable->campus_id, 'timetable_id' => (int) $timetable->getKey(),
                'supersedes_timetable_id' => $timetable->supersedes_timetable_id, 'timezone' => $timetable->timezone,
            ];
            foreach ($conflicts->conflicts($candidate, (int) $meeting->getKey()) as $finding) {
                $type = (string) ($finding['type'] ?? 'schedule_conflict');
                $key = 'conflict-'.$meeting->getKey().'-'.$type.'-'.($finding['date'] ?? 'series').'-'.($finding['meeting_id'] ?? 'external');
                $qualityFindings[$key] = [
                    'key' => $key, 'severity' => 'warning', 'category' => 'conflict', 'title' => str($type)->replace('_', ' ')->ucfirst().' detected',
                    'description' => $meeting->section->offering->course->code.' · '.$meeting->section->section_code.($finding['date'] ?? null ? ' · '.$finding['date'] : ''),
                    'href' => '/t/'.$context->tenant()->slug.'/academics/timetables#timetable-'.$timetable->getKey(), 'action' => 'Inspect timetable',
                ];
            }
        }
        $qualityFindings = array_values($qualityFindings);

        return Inertia::render('workspace/academics/timetable-analytics', [
            'tenant' => ['slug' => $context->tenant()->slug],
            'metrics' => ['published_timetables' => (clone $published)->count(), 'published_meetings' => (clone $meetings)->count(), 'active_rooms' => $roomCount, 'rooms_in_use' => $usedRooms, 'room_use_percent' => $roomCount === 0 ? null : round($usedRooms / $roomCount * 100, 1), 'unscheduled_open_sections' => $unscheduledSections->count(), 'pending_change_approvals' => $pendingChanges, 'approved_changes' => $changeCounts],
            'qualityFindings' => $qualityFindings,
            'weeklyInstructorWorkload' => $workload,
        ]);
    }
}
