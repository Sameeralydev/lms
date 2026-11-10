<?php

namespace App\Domain\Academic\Timetabling;

use App\Models\AcademicCalendarException;
use App\Models\AcademicInstructorAvailability;
use App\Models\AcademicRoomBlock;
use App\Models\AcademicSchedulingSetting;
use App\Models\AcademicTimetableMeeting;
use App\Models\CourseEnrollment;
use Illuminate\Support\Carbon;

final class TimetableConflictChecker
{
    /**
     * @param  array<string, mixed>  $candidate
     * @return list<array<string, mixed>>
     */
    public function conflicts(array $candidate, ?int $ignoreMeetingId = null): array
    {
        $candidateOccurrences = $this->occurrences($candidate);
        $conflicts = [];
        $periodId = (int) $candidate['academic_period_id'];
        $candidates = AcademicTimetableMeeting::query()->where('tenant_id', $candidate['tenant_id'])
            ->where('status', 'scheduled')->whereDate('starts_on', '<=', $candidate['ends_on'])->whereDate('ends_on', '>=', $candidate['starts_on'])
            ->where('id', '!=', $ignoreMeetingId ?? 0)->with('timetable')->get();

        $candidateStudentIds = CourseEnrollment::query()->where('tenant_id', $candidate['tenant_id'])->where('section_id', $candidate['section_id'])->where('status', 'confirmed')->pluck('student_id')->map(fn ($id): int => (int) $id)->all();
        foreach ($candidates as $existing) {
            if ((int) $existing->timetable->academic_period_id !== $periodId || ! in_array($existing->timetable->status, ['draft', 'review', 'approved', 'published'], true)) {
                continue;
            }
            if ((int) ($candidate['supersedes_timetable_id'] ?? 0) === (int) $existing->timetable_id) {
                continue;
            }
            $shared = array_values(array_intersect($candidateStudentIds, CourseEnrollment::query()->where('tenant_id', $candidate['tenant_id'])->where('section_id', $existing->section_id)->where('status', 'confirmed')->pluck('student_id')->map(fn ($id): int => (int) $id)->all()));
            $types = [];
            if ((int) $existing->section_id === (int) $candidate['section_id']) {
                $types[] = 'section_double_booking';
            }
            if ((int) $existing->instructor_user_id === (int) $candidate['instructor_user_id']) {
                $types[] = 'instructor_double_booking';
            }
            if ($candidate['room_id'] !== null && (int) $existing->room_id === (int) $candidate['room_id']) {
                $types[] = 'room_double_booking';
            }
            if ($shared !== []) {
                $types[] = 'student_schedule_clash';
            }
            if ($types === []) {
                continue;
            }
            foreach ($candidateOccurrences as $date => [$start, $end]) {
                foreach ($this->occurrences($existing->toArray() + ['timezone' => $existing->timetable->timezone]) as $existingDate => [$existingStart, $existingEnd]) {
                    if ($date === $existingDate && $start < $existingEnd && $end > $existingStart) {
                        foreach ($types as $type) {
                            $conflicts[] = ['type' => $type, 'date' => $date, 'meeting_id' => (int) $existing->getKey(), 'section_id' => (int) $existing->section_id, 'instructor_user_id' => (int) $existing->instructor_user_id, 'room_id' => $existing->room_id === null ? null : (int) $existing->room_id, 'student_ids' => $type === 'student_schedule_clash' ? $shared : []];
                        }
                    }
                }
            }
        }

        $this->appendCalendarConflicts($candidate, $candidateOccurrences, $conflicts);
        $this->appendAvailabilityConflicts($candidate, $candidateOccurrences, $conflicts);
        $this->appendOperatingConflicts($candidate, $candidateOccurrences, $conflicts);
        $this->appendRoomBlockConflicts($candidate, $candidateOccurrences, $conflicts);
        $this->appendInstructorWorkloadConflicts($candidate, $candidateOccurrences, $conflicts);

        return $conflicts;
    }

    /**
     * @param  array<string, mixed>  $meeting
     * @return array<string, array{Carbon,Carbon}>
     */
    public function occurrences(array $meeting): array
    {
        $from = Carbon::parse((string) $meeting['starts_on'])->startOfDay();
        $to = Carbon::parse((string) $meeting['ends_on'])->startOfDay();
        if ($to->diffInDays($from) > 370) {
            return [];
        }
        $weekdays = array_map('intval', (array) $meeting['weekdays']);
        $timezone = new \DateTimeZone((string) ($meeting['timezone'] ?? 'UTC'));
        $times = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if (! in_array($day->dayOfWeekIso, $weekdays, true)) {
                continue;
            }
            $utc = LocalScheduleTime::toUtc($day->toDateString(), substr((string) $meeting['starts_at_local'], 0, 5), $timezone->getName());
            $start = Carbon::instance($utc);
            $times[$day->toDateString()] = [$start, $start->copy()->addMinutes((int) $meeting['duration_minutes'])];
        }

        return $times;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, array{Carbon,Carbon}>  $occurrences
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function appendCalendarConflicts(array $candidate, array $occurrences, array &$conflicts): void
    {
        $exceptions = AcademicCalendarException::query()->where('tenant_id', $candidate['tenant_id'])->where('institution_id', $candidate['institution_id'])
            ->whereDate('starts_on', '<=', $candidate['ends_on'])->whereDate('ends_on', '>=', $candidate['starts_on'])
            ->where(fn ($query) => $query->whereNull('campus_id')->orWhere('campus_id', $candidate['campus_id']))->get();
        foreach ($occurrences as $date => $_) {
            foreach ($exceptions as $exception) {
                if ($date >= Carbon::parse($exception->starts_on)->toDateString() && $date <= Carbon::parse($exception->ends_on)->toDateString()) {
                    $conflicts[] = ['type' => $exception->exception_type === 'break' ? 'academic_break' : 'academic_closure', 'date' => $date, 'detail' => $exception->name];
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, array{Carbon,Carbon}>  $occurrences
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function appendAvailabilityConflicts(array $candidate, array $occurrences, array &$conflicts): void
    {
        $availability = AcademicInstructorAvailability::query()->where('tenant_id', $candidate['tenant_id'])->where('institution_id', $candidate['institution_id'])
            ->where('user_id', $candidate['instructor_user_id'])->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('campus_id')->orWhere('campus_id', $candidate['campus_id']))->get();
        $hasAvailableRules = $availability->contains(fn ($rule): bool => $rule->availability_type === 'available');
        foreach ($occurrences as $date => [$start, $end]) {
            $isoDay = Carbon::parse($date)->dayOfWeekIso;
            $dayRules = $availability->filter(fn ($rule): bool => $date >= Carbon::parse($rule->effective_from)->toDateString() && $date <= Carbon::parse($rule->effective_until)->toDateString() && in_array($isoDay, array_map('intval', (array) $rule->weekdays), true));
            $localStart = $start->copy()->setTimezone((string) $candidate['timezone'])->format('H:i');
            $localEnd = $end->copy()->setTimezone((string) $candidate['timezone'])->format('H:i');
            $unavailable = $dayRules->contains(fn ($rule): bool => $rule->availability_type === 'unavailable' && substr((string) $rule->starts_at, 0, 5) < $localEnd && substr((string) $rule->ends_at, 0, 5) > $localStart);
            $available = ! $hasAvailableRules || $dayRules->contains(fn ($rule): bool => $rule->availability_type === 'available' && substr((string) $rule->starts_at, 0, 5) <= $localStart && substr((string) $rule->ends_at, 0, 5) >= $localEnd);
            if ($unavailable || ! $available) {
                $conflicts[] = ['type' => $unavailable ? 'instructor_unavailable' : 'outside_instructor_availability', 'date' => $date, 'instructor_user_id' => (int) $candidate['instructor_user_id']];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, array{Carbon,Carbon}>  $occurrences
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function appendOperatingConflicts(array $candidate, array $occurrences, array &$conflicts): void
    {
        $settings = AcademicSchedulingSetting::query()->where('tenant_id', $candidate['tenant_id'])->where('institution_id', $candidate['institution_id'])
            ->where(fn ($query) => $query->where('campus_id', $candidate['campus_id'])->orWhereNull('campus_id'))->orderByRaw('campus_id IS NULL')->first();
        if (! $settings) {
            return;
        }
        foreach ($occurrences as $date => [$start, $end]) {
            $isoDay = Carbon::parse($date)->dayOfWeekIso;
            $localStart = $start->setTimezone($settings->timezone);
            $localEnd = $end->setTimezone($settings->timezone);
            $teachingDays = $settings->teaching_days;
            if (! in_array($isoDay, array_map('intval', $teachingDays), true) || $localStart->format('H:i:s') < $settings->opens_at || $localEnd->format('H:i:s') > $settings->closes_at) {
                $conflicts[] = ['type' => 'outside_operating_hours', 'date' => $date];
            }
            $breakWindows = $settings->getAttribute('break_windows');
            $breakWindows = is_array($breakWindows) ? $breakWindows : [];
            foreach ($breakWindows as $break) {
                if (($break['starts_at'] ?? '24:00') < $localEnd->format('H:i') && ($break['ends_at'] ?? '00:00') > $localStart->format('H:i')) {
                    $conflicts[] = ['type' => 'break_period_violation', 'date' => $date];
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, array{Carbon,Carbon}>  $occurrences
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function appendRoomBlockConflicts(array $candidate, array $occurrences, array &$conflicts): void
    {
        if ($candidate['room_id'] === null || $occurrences === []) {
            return;
        }
        $maxEnd = max(array_map(static fn (array $range): string => $range[1]->toDateTimeString(), $occurrences));
        $minStart = min(array_map(static fn (array $range): string => $range[0]->toDateTimeString(), $occurrences));
        $blocks = AcademicRoomBlock::query()->where('tenant_id', $candidate['tenant_id'])->where('room_id', $candidate['room_id'])->where('status', 'active')
            ->where('starts_at_utc', '<', $maxEnd)->where('ends_at_utc', '>', $minStart)->get();
        foreach ($occurrences as $date => [$start, $end]) {
            foreach ($blocks as $block) {
                if ($start < $block->ends_at_utc && $end > $block->starts_at_utc) {
                    $conflicts[] = ['type' => 'room_unavailable', 'date' => $date, 'room_id' => (int) $candidate['room_id'], 'block_type' => $block->block_type];
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, array{Carbon,Carbon}>  $occurrences
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function appendInstructorWorkloadConflicts(array $candidate, array $occurrences, array &$conflicts): void
    {
        $settings = AcademicSchedulingSetting::query()->where('tenant_id', $candidate['tenant_id'])->where('institution_id', $candidate['institution_id'])
            ->where(fn ($query) => $query->where('campus_id', $candidate['campus_id'])->orWhereNull('campus_id'))->orderByRaw('campus_id IS NULL')->first();
        if (! $settings) {
            return;
        }
        // A recurrence represents one weekly pattern. Counting every calendar
        // occurrence in a semester incorrectly multiplies teaching load by the
        // number of weeks in the period.
        $weeklyMinutes = count(array_unique(array_map('intval', (array) $candidate['weekdays']))) * (int) $candidate['duration_minutes'];
        $meetings = AcademicTimetableMeeting::query()->where('tenant_id', $candidate['tenant_id'])->where('instructor_user_id', $candidate['instructor_user_id'])->where('status', 'scheduled')
            ->where('id', '!=', $candidate['ignore_meeting_id'] ?? 0)->when($candidate['supersedes_timetable_id'] ?? null, fn ($query, $id) => $query->where('timetable_id', '!=', $id))
            ->whereDate('starts_on', '<=', $candidate['ends_on'])->whereDate('ends_on', '>=', $candidate['starts_on'])->with('timetable')->get();
        foreach ($meetings as $meeting) {
            if (in_array($meeting->timetable->status, ['draft', 'review', 'approved', 'published'], true)) {
                $weeklyMinutes += count(array_unique(array_map('intval', (array) $meeting->weekdays))) * (int) $meeting->duration_minutes;
            }
        }
        if ($weeklyMinutes > (int) $settings->max_weekly_teaching_minutes) {
            $conflicts[] = ['type' => 'instructor_overload', 'instructor_user_id' => (int) $candidate['instructor_user_id'], 'weekly_minutes' => $weeklyMinutes, 'maximum_weekly_minutes' => (int) $settings->max_weekly_teaching_minutes];
        }
    }
}
