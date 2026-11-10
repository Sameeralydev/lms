<?php

namespace App\Domain\Academic\Timetabling;

use App\Models\AcademicRoom;
use App\Models\AcademicTimetableChange;
use App\Models\AcademicTimetableMeeting;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Projects immutable published recurrence rows plus approved occurrence exceptions. */
final class TimetableOccurrenceProjector
{
    public function __construct(private readonly TimetableConflictChecker $conflicts) {}

    /** @return list<array{key:string,meeting:AcademicTimetableMeeting,date:string,start:Carbon,end:Carbon,instructor_name:string,room_name:?string,location:string}> */
    public function project(AcademicTimetableMeeting $meeting): array
    {
        $meeting->loadMissing(['timetable', 'instructor', 'room']);
        $timezone = $meeting->timetable->timezone;
        /** @var list<array{key:string,meeting:AcademicTimetableMeeting,date:string,start:Carbon,end:Carbon,instructor_name:string,room_name:?string,location:string}> $instances */
        $instances = [];
        foreach ($this->conflicts->occurrences($meeting->toArray() + ['timezone' => $timezone]) as $date => [$start, $end]) {
            $roomName = $meeting->room_id === null ? null : $meeting->room->name;
            $instances[] = ['key' => $meeting->getKey().'-'.$date, 'meeting' => $meeting, 'date' => $date, 'start' => $start, 'end' => $end, 'instructor_name' => $meeting->instructor->name, 'room_name' => $roomName, 'location' => $roomName ?? $meeting->online_location ?? 'Online'];
        }

        $changes = AcademicTimetableChange::query()->where('meeting_id', $meeting->getKey())->where('status', 'approved')->orderBy('id')->get();
        foreach ($changes as $change) {
            $originalDate = Carbon::parse($change->occurrence_date)->toDateString();
            if ($change->change_type === 'cancellation' || $change->change_type === 'reschedule') {
                $instances = array_filter($instances, static fn (array $instance): bool => $instance['date'] !== $originalDate);
            }
            if ($change->change_type === 'substitution') {
                foreach ($instances as &$instance) {
                    if ($instance['date'] === $originalDate) {
                        $instance['instructor_name'] = User::query()->whereKey($change->instructor_user_id)->value('name') ?? $instance['instructor_name'];
                        $instance['room_name'] = $change->room_id ? AcademicRoom::query()->whereKey($change->room_id)->value('name') : $instance['room_name'];
                        $instance['location'] = $instance['room_name'] ?? $instance['location'];
                    }
                }
                unset($instance);
            }
            if (in_array($change->change_type, ['reschedule', 'makeup'], true)) {
                $date = Carbon::parse($change->replacement_date ?? $change->occurrence_date)->toDateString();
                $start = Carbon::instance(LocalScheduleTime::toUtc($date, substr((string) ($change->starts_at_local ?? $meeting->starts_at_local), 0, 5), $timezone));
                $end = $start->copy()->addMinutes((int) ($change->duration_minutes ?? $meeting->duration_minutes));
                $instructorName = $change->instructor_user_id ? User::query()->whereKey($change->instructor_user_id)->value('name') : null;
                $roomName = $change->room_id ? AcademicRoom::query()->whereKey($change->room_id)->value('name') : null;
                $meetingRoomName = $meeting->room_id === null ? null : $meeting->room->name;
                $instances[] = ['key' => $meeting->getKey().'-'.$date.'-change-'.$change->getKey(), 'meeting' => $meeting, 'date' => $date, 'start' => $start, 'end' => $end, 'instructor_name' => $instructorName ?? $meeting->instructor->name, 'room_name' => $roomName ?? $meetingRoomName, 'location' => $roomName ?? $meetingRoomName ?? $meeting->online_location ?? 'Online'];
            }
        }

        usort($instances, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        return $instances;
    }
}
