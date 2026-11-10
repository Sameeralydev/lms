<?php

namespace App\Domain\Academic\Timetabling;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicBuilding;
use App\Models\AcademicCalendarException;
use App\Models\AcademicInstructorAvailability;
use App\Models\AcademicPeriod;
use App\Models\AcademicRoom;
use App\Models\AcademicRoomBlock;
use App\Models\AcademicSchedulingSetting;
use App\Models\AcademicTimetable;
use App\Models\AcademicTimetableChange;
use App\Models\AcademicTimetableMeeting;
use App\Models\AcademicTimetableReview;
use App\Models\AcademicTimetableSnapshot;
use App\Models\AcademicYear;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Institution;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TimetableWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit, private readonly TimetableConflictChecker $conflicts) {}

    /** @param array<string,mixed> $data */
    public function saveSettings(array $data, User $actor): AcademicSchedulingSetting
    {
        $this->authorize('academic.calendar.manage');
        $institution = $this->institution();
        $campusId = $data['campus_id'] ?? null;
        $this->assertCampus($campusId);

        return DB::transaction(function () use ($data, $actor, $institution, $campusId): AcademicSchedulingSetting {
            Institution::query()->lockForUpdate()->findOrFail($institution->getKey());
            $query = AcademicSchedulingSetting::query()->where('institution_id', $institution->getKey());
            $campusId === null ? $query->whereNull('campus_id') : $query->where('campus_id', $campusId);
            $row = $query->first() ?? new AcademicSchedulingSetting;
            $row->fill([...$data, 'institution_id' => $institution->getKey(), 'campus_id' => $campusId, 'timezone' => $data['timezone'] ?? $institution->timezone ?: 'UTC']);
            $row->save();
            $this->audit->record('academic.scheduling.settings_updated', $actor, $this->tenantId(), $row, ['campus_id' => $campusId]);

            return $row;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function createBuilding(array $data, User $actor): AcademicBuilding
    {
        $this->authorize('academic.calendar.manage');
        $institution = $this->institution();
        $this->assertCampus((int) $data['campus_id']);
        $this->assertCampusBelongs((int) $data['campus_id'], (int) $institution->getKey());
        $building = AcademicBuilding::query()->create([...$data, 'institution_id' => $institution->getKey(), 'status' => 'active']);
        $this->audit->record('academic.building.created', $actor, $this->tenantId(), $building, ['campus_id' => $building->campus_id, 'code' => $building->code]);

        return $building;
    }

    /** @param array<string,mixed> $data */
    public function createRoom(array $data, User $actor): AcademicRoom
    {
        $this->authorize('academic.calendar.manage');
        $institution = $this->institution();
        $campusId = (int) $data['campus_id'];
        $this->assertCampus($campusId);
        $this->assertCampusBelongs($campusId, (int) $institution->getKey());
        if (! empty($data['building_id']) && ! AcademicBuilding::query()->where('campus_id', $campusId)->where('institution_id', $institution->getKey())->whereKey($data['building_id'])->exists()) {
            throw ValidationException::withMessages(['building_id' => 'Choose a building in this campus.']);
        }
        $room = AcademicRoom::query()->create([...$data, 'institution_id' => $institution->getKey(), 'status' => 'active']);
        $this->audit->record('academic.room.created', $actor, $this->tenantId(), $room, ['campus_id' => $campusId, 'code' => $room->code]);

        return $room;
    }

    /** @param array<string,mixed> $data */
    public function blockRoom(array $data, User $actor): AcademicRoomBlock
    {
        $this->authorize('academic.calendar.manage');
        $institution = $this->institution();
        $room = AcademicRoom::query()->where('institution_id', $institution->getKey())->where('status', 'active')->find($data['room_id']);
        if (! $room || ! $this->campusIsAccessible((int) $room->campus_id)) {
            throw new ModelNotFoundException;
        }
        if ($data['ends_at_utc'] <= $data['starts_at_utc']) {
            throw ValidationException::withMessages(['ends_at_utc' => 'The room block must end after it starts.']);
        }

        return DB::transaction(function () use ($data, $actor, $room): AcademicRoomBlock {
            $block = AcademicRoomBlock::query()->create([...$data, 'tenant_id' => $this->tenantId(), 'room_id' => $room->getKey(), 'created_by' => $actor->getKey(), 'status' => 'active']);
            $this->audit->record('academic.room.blocked', $actor, $this->tenantId(), $block, ['room_id' => (int) $room->getKey(), 'block_type' => $block->block_type]);

            return $block;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function saveAvailability(array $data, User $actor): AcademicInstructorAvailability
    {
        $this->authorize('academic.calendar.manage');
        $institution = $this->institution();
        $this->assertCampus($data['campus_id'] ?? null);
        $membership = TenantMembership::query()->where('institution_id', $institution->getKey())->where('user_id', $data['user_id'])->where('status', 'active')->first();
        if (! $membership || ! $membership->hasPermission('enrollment.instructor.roster.view')) {
            throw ValidationException::withMessages(['user_id' => 'Select an active instructor in this institution.']);
        }
        $availability = AcademicInstructorAvailability::query()->create([...$data, 'institution_id' => $institution->getKey(), 'status' => 'active']);
        $this->audit->record('academic.instructor.availability_set', $actor, $this->tenantId(), $availability, ['user_id' => (int) $membership->user_id, 'type' => $availability->availability_type]);

        return $availability;
    }

    /** @param array<string,mixed> $data */
    public function createClosure(array $data, User $actor): AcademicCalendarException
    {
        $this->authorize('academic.calendar.manage');
        $institution = $this->institution();
        $campusId = $data['campus_id'] ?? null;
        $this->assertCampus($campusId);
        if ($campusId !== null) {
            $this->assertCampusBelongs((int) $campusId, (int) $institution->getKey());
        }
        $closure = AcademicCalendarException::query()->create([...$data, 'institution_id' => $institution->getKey()]);
        $this->audit->record('academic.calendar.exception_created', $actor, $this->tenantId(), $closure, ['campus_id' => $campusId, 'type' => $closure->exception_type]);

        return $closure;
    }

    /** @param array<string,mixed> $data */
    public function createTimetable(array $data, User $actor): AcademicTimetable
    {
        $this->authorize('academic.calendar.manage');
        $institution = $this->institution();
        $campusId = $data['campus_id'] ?? null;
        $this->assertCampus($campusId);
        $year = AcademicYear::query()->where('institution_id', $institution->getKey())->find($data['academic_year_id']);
        $period = AcademicPeriod::query()->find($data['academic_period_id']);
        if (! $year || ! $period || (int) $period->academic_year_id !== (int) $year->getKey() || $year->status !== 'active' || $period->status !== 'active') {
            throw ValidationException::withMessages(['academic_period_id' => 'Choose an active academic period in the selected active year.']);
        }
        if ($campusId !== null) {
            $this->assertCampusBelongs((int) $campusId, (int) $institution->getKey());
        }

        return DB::transaction(function () use ($data, $actor, $institution, $year, $period, $campusId): AcademicTimetable {
            Institution::query()->lockForUpdate()->findOrFail($institution->getKey());
            if (AcademicTimetable::query()->where('academic_period_id', $period->getKey())->where('campus_id', $campusId)->exists()) {
                throw ValidationException::withMessages(['academic_period_id' => 'A timetable version already exists for this academic period and campus. Create a new version from its timetable.']);
            }
            $timetable = AcademicTimetable::query()->create([
                'institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(),
                'campus_id' => $campusId, 'name' => $data['name'], 'version' => 1, 'status' => 'draft', 'timezone' => $data['timezone'] ?? $institution->timezone ?: 'UTC',
                'independent_review_required' => (bool) ($data['independent_review_required'] ?? true), 'created_by' => $actor->getKey(),
            ]);
            $this->audit->record('academic.timetable.created', $actor, $this->tenantId(), $timetable, ['period_id' => (int) $period->getKey(), 'campus_id' => $campusId, 'version' => 1]);

            return $timetable;
        }, 3);
    }

    public function revise(AcademicTimetable $source, User $actor): AcademicTimetable
    {
        $this->authorize('academic.calendar.manage');
        $this->assertTimetableOwned($source);

        return DB::transaction(function () use ($source, $actor): AcademicTimetable {
            Institution::query()->lockForUpdate()->findOrFail($source->institution_id);
            $locked = AcademicTimetable::query()->lockForUpdate()->findOrFail($source->getKey());
            if ($locked->status !== 'published') {
                throw ValidationException::withMessages(['timetable' => 'Only a published timetable can be revised.']);
            }
            $existingDraft = AcademicTimetable::query()->where('tenant_id', $locked->tenant_id)->where('supersedes_timetable_id', $locked->getKey())->whereIn('status', ['draft', 'review', 'approved'])->first();
            if ($existingDraft) {
                if ($existingDraft->status === 'draft') {
                    return $existingDraft;
                }

                throw ValidationException::withMessages(['timetable' => 'A revision is already awaiting review or publication.']);
            }
            $version = (int) AcademicTimetable::query()->where('tenant_id', $locked->tenant_id)->where('academic_period_id', $locked->academic_period_id)
                ->where('campus_id', $locked->campus_id)->max('version') + 1;
            $revision = AcademicTimetable::query()->create([
                'institution_id' => $locked->institution_id,
                'academic_year_id' => $locked->academic_year_id,
                'academic_period_id' => $locked->academic_period_id,
                'campus_id' => $locked->campus_id,
                'supersedes_timetable_id' => $locked->getKey(),
                'name' => $locked->name,
                'version' => $version,
                'status' => 'draft',
                'timezone' => $locked->timezone,
                'independent_review_required' => $locked->independent_review_required,
                'created_by' => $actor->getKey(),
            ]);
            foreach ($locked->meetings()->where('status', 'scheduled')->get() as $meeting) {
                $revision->meetings()->create($meeting->only(['section_id', 'instructor_user_id', 'room_id', 'starts_on', 'ends_on', 'weekdays', 'starts_at_local', 'duration_minutes', 'delivery_mode', 'online_location', 'series_key']) + ['status' => 'scheduled', 'created_by' => $actor->getKey()]);
            }
            $this->audit->record('academic.timetable.revision_created', $actor, $this->tenantId(), $revision, ['source_timetable_id' => (int) $locked->getKey(), 'version' => $version]);

            return $revision;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public function previewMeeting(AcademicTimetable $timetable, array $data, ?int $ignoreId = null): array
    {
        $this->assertTimetableOwned($timetable);
        $candidate = $this->candidate($timetable, $data);

        return $this->conflicts->conflicts($candidate, $ignoreId);
    }

    /** @param array<string,mixed> $data */
    public function createMeeting(AcademicTimetable $timetable, array $data, User $actor): AcademicTimetableMeeting
    {
        $this->authorize('academic.calendar.manage');
        $this->assertTimetableOwned($timetable);
        $candidate = $this->candidate($timetable, $data);
        $this->assertSectionInstructorRoom($timetable, $data, $candidate);

        return DB::transaction(function () use ($timetable, $data, $actor, $candidate): AcademicTimetableMeeting {
            Institution::query()->lockForUpdate()->findOrFail($timetable->institution_id);
            $locked = AcademicTimetable::query()->lockForUpdate()->findOrFail($timetable->getKey());
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['timetable' => 'Only a draft timetable can be edited.']);
            }
            $issues = $this->conflicts->conflicts($candidate);
            if ($issues !== []) {
                throw ValidationException::withMessages(['conflicts' => 'Resolve scheduling conflicts before saving: '.implode(', ', array_unique(array_column($issues, 'type')))]);
            }
            $meeting = AcademicTimetableMeeting::query()->create([
                'timetable_id' => $locked->getKey(), 'section_id' => $candidate['section_id'], 'instructor_user_id' => $candidate['instructor_user_id'], 'room_id' => $candidate['room_id'],
                'starts_on' => $candidate['starts_on'], 'ends_on' => $candidate['ends_on'], 'weekdays' => $candidate['weekdays'], 'starts_at_local' => $candidate['starts_at_local'],
                'duration_minutes' => $candidate['duration_minutes'], 'delivery_mode' => $candidate['delivery_mode'], 'online_location' => $candidate['online_location'],
                'status' => 'scheduled', 'series_key' => $data['series_key'] ?? null, 'created_by' => $actor->getKey(),
            ]);
            $this->audit->record('academic.timetable.meeting_created', $actor, $this->tenantId(), $meeting, ['timetable_id' => (int) $locked->getKey(), 'section_id' => $candidate['section_id'], 'meeting_id' => (int) $meeting->getKey()]);

            return $meeting;
        }, 3);
    }

    /**
     * Update one recurring series on a draft revision; published source versions remain immutable.
     *
     * @param  array<string,mixed>  $data
     */
    public function updateMeeting(AcademicTimetableMeeting $meeting, array $data, User $actor): AcademicTimetableMeeting
    {
        $this->authorize('academic.calendar.manage');
        $meeting->loadMissing('timetable');
        $timetable = $meeting->timetable;
        $this->assertTimetableOwned($timetable);
        if ($timetable->status !== 'draft' || $meeting->status !== 'scheduled') {
            throw ValidationException::withMessages(['meeting' => 'Only a scheduled series in a draft version can be edited. Revise a published timetable first.']);
        }
        $candidate = $this->candidate($timetable, $data);
        $this->assertSectionInstructorRoom($timetable, $data, $candidate);

        return DB::transaction(function () use ($meeting, $timetable, $data, $candidate, $actor): AcademicTimetableMeeting {
            Institution::query()->lockForUpdate()->findOrFail($timetable->institution_id);
            $lockedTimetable = AcademicTimetable::query()->lockForUpdate()->findOrFail($timetable->getKey());
            $locked = AcademicTimetableMeeting::query()->lockForUpdate()->findOrFail($meeting->getKey());
            if ($lockedTimetable->status !== 'draft' || $locked->timetable_id !== $lockedTimetable->getKey() || $locked->status !== 'scheduled') {
                throw ValidationException::withMessages(['meeting' => 'The draft series changed while the edit was being prepared. Refresh and retry.']);
            }
            $issues = $this->conflicts->conflicts($candidate, (int) $locked->getKey());
            if ($issues !== []) {
                throw ValidationException::withMessages(['conflicts' => 'Resolve scheduling conflicts before saving: '.implode(', ', array_unique(array_column($issues, 'type')))]);
            }
            $before = $locked->only(['section_id', 'instructor_user_id', 'room_id', 'starts_on', 'ends_on', 'weekdays', 'starts_at_local', 'duration_minutes', 'delivery_mode', 'online_location']);
            $locked->fill([
                'section_id' => $candidate['section_id'], 'instructor_user_id' => $candidate['instructor_user_id'], 'room_id' => $candidate['room_id'],
                'starts_on' => $candidate['starts_on'], 'ends_on' => $candidate['ends_on'], 'weekdays' => $candidate['weekdays'],
                'starts_at_local' => $candidate['starts_at_local'], 'duration_minutes' => $candidate['duration_minutes'],
                'delivery_mode' => $candidate['delivery_mode'], 'online_location' => $candidate['online_location'],
                'series_key' => $data['series_key'] ?? $locked->series_key,
            ])->save();
            $this->audit->record('academic.timetable.series_updated', $actor, $this->tenantId(), $locked, ['timetable_id' => (int) $lockedTimetable->getKey(), 'meeting_id' => (int) $locked->getKey(), 'before' => $before, 'after' => $locked->only(array_keys($before))]);

            return $locked->refresh();
        }, 3);
    }

    public function cancelMeetingSeries(AcademicTimetableMeeting $meeting, string $reason, User $actor): AcademicTimetableMeeting
    {
        $this->authorize('academic.calendar.manage');
        $meeting->loadMissing('timetable');
        $this->assertTimetableOwned($meeting->timetable);

        return DB::transaction(function () use ($meeting, $reason, $actor): AcademicTimetableMeeting {
            Institution::query()->lockForUpdate()->findOrFail($meeting->timetable->institution_id);
            $timetable = AcademicTimetable::query()->lockForUpdate()->findOrFail($meeting->timetable_id);
            $locked = AcademicTimetableMeeting::query()->lockForUpdate()->findOrFail($meeting->getKey());
            if ($timetable->status !== 'draft' || $locked->timetable_id !== $timetable->getKey() || $locked->status !== 'scheduled') {
                throw ValidationException::withMessages(['meeting' => 'Only a scheduled series in a draft version can be canceled. Revise a published timetable first.']);
            }
            $locked->update(['status' => 'cancelled']);
            $this->audit->record('academic.timetable.series_cancelled', $actor, $this->tenantId(), $locked, ['timetable_id' => (int) $timetable->getKey(), 'meeting_id' => (int) $locked->getKey(), 'reason' => trim($reason)]);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function requestChange(AcademicTimetableMeeting $meeting, array $data, User $actor): AcademicTimetableChange
    {
        $this->authorize('academic.calendar.manage');
        $meeting->loadMissing('timetable');
        $timetable = $meeting->timetable;
        $this->assertTimetableOwned($timetable);
        if ($timetable->status !== 'published' || $meeting->status !== 'scheduled') {
            throw ValidationException::withMessages(['meeting' => 'Changes can only be requested for a scheduled meeting in the published timetable.']);
        }
        $date = Carbon::parse($data['occurrence_date'])->toDateString();
        $pattern = $this->conflicts->occurrences($meeting->toArray() + ['timezone' => $timetable->timezone]);
        if (! isset($pattern[$date])) {
            throw ValidationException::withMessages(['occurrence_date' => 'Choose a date that belongs to this meeting recurrence.']);
        }
        $type = (string) $data['change_type'];
        $replacementDate = Carbon::parse($data['replacement_date'] ?? $date)->toDateString();
        if ($type === 'substitution' && $replacementDate !== $date) {
            throw ValidationException::withMessages(['replacement_date' => 'An instructor substitution keeps the original occurrence date. Use reschedule to move the class.']);
        }
        if ($type !== 'cancellation') {
            $period = AcademicPeriod::query()->where('academic_year_id', $timetable->academic_year_id)->findOrFail($timetable->academic_period_id);
            if ($replacementDate < Carbon::parse($period->starts_on)->toDateString() || $replacementDate > Carbon::parse($period->ends_on)->toDateString()) {
                throw ValidationException::withMessages(['replacement_date' => 'The changed occurrence must remain within its academic period.']);
            }
            $instructorId = (int) ($data['instructor_user_id'] ?? $meeting->instructor_user_id);
            $roomId = empty($data['room_id']) ? $meeting->room_id : (int) $data['room_id'];
            if ($instructorId !== (int) $meeting->instructor_user_id && $type !== 'substitution') {
                throw ValidationException::withMessages(['change_type' => 'Use the substitution workflow when changing the instructor.']);
            }
            $startsAt = (string) ($data['starts_at_local'] ?? substr((string) $meeting->starts_at_local, 0, 5));
            $duration = (int) ($data['duration_minutes'] ?? $meeting->duration_minutes);
            $candidate = [
                'tenant_id' => $this->tenantId(), 'institution_id' => (int) $timetable->institution_id,
                'academic_period_id' => (int) $timetable->academic_period_id, 'campus_id' => $timetable->campus_id,
                'timetable_id' => (int) $timetable->getKey(), 'supersedes_timetable_id' => $timetable->supersedes_timetable_id,
                'section_id' => (int) $meeting->section_id, 'instructor_user_id' => $instructorId, 'room_id' => $roomId,
                'starts_on' => $replacementDate, 'ends_on' => $replacementDate,
                'weekdays' => [(int) Carbon::parse($replacementDate)->dayOfWeekIso], 'starts_at_local' => $startsAt,
                'duration_minutes' => $duration, 'timezone' => $timetable->timezone,
                'delivery_mode' => $meeting->delivery_mode, 'online_location' => $meeting->online_location,
            ];
            if ($duration < 10 || $duration > 480) {
                throw ValidationException::withMessages(['duration_minutes' => 'Meeting duration must be between 10 minutes and 8 hours.']);
            }
            if (! DB::table('course_section_instructors')->where('tenant_id', $this->tenantId())->where('section_id', $meeting->section_id)->where('user_id', $instructorId)->exists()) {
                throw ValidationException::withMessages(['instructor_user_id' => 'The selected instructor must be assigned to this section.']);
            }
            $activeMembership = TenantMembership::query()->where('institution_id', $timetable->institution_id)->where('user_id', $instructorId)->where('status', 'active')->first();
            if (! $activeMembership || ! $activeMembership->hasPermission('enrollment.instructor.roster.view')) {
                throw ValidationException::withMessages(['instructor_user_id' => 'The selected instructor must have an active instructor membership.']);
            }
            $room = $roomId === null ? null : AcademicRoom::query()->where('institution_id', $timetable->institution_id)->when($timetable->campus_id !== null, fn ($query) => $query->where('campus_id', $timetable->campus_id))->where('status', 'active')->find($roomId);
            if ($roomId !== null && ! $room) {
                throw ValidationException::withMessages(['room_id' => 'Choose an active room in the timetable institution and campus.']);
            }
            if ($roomId === null && in_array($meeting->delivery_mode, ['in_person', 'hybrid'], true)) {
                throw ValidationException::withMessages(['room_id' => 'In-person and hybrid occurrences require an active room.']);
            }
            if ($room) {
                $section = CourseSection::query()->findOrFail($meeting->section_id);
                $enrolled = CourseEnrollment::query()->where('section_id', $section->getKey())->whereIn('status', ['confirmed', 'offer_pending'])->count();
                if ((int) $room->capacity < max((int) $section->capacity, $enrolled)) {
                    throw ValidationException::withMessages(['room_id' => 'Room capacity is lower than the section capacity or current enrollment.']);
                }
            }
            $this->conflicts->occurrences($candidate); // reject nonexistent/ambiguous local wall times
            $this->assertNoSourceSeriesConflict($meeting, $date, $candidate);
            $this->assertNoApprovedExceptionConflict($meeting, $candidate);
            if ($type !== 'cancellation') {
                $conflicts = $this->conflicts->conflicts($candidate, (int) $meeting->getKey());
                if ($conflicts !== []) {
                    throw ValidationException::withMessages(['change' => 'Resolve schedule conflicts before requesting approval: '.implode(', ', array_unique(array_column($conflicts, 'type')))]);
                }
            }
        } else {
            $instructorId = null;
            $roomId = null;
            $startsAt = null;
            $duration = null;
        }

        return DB::transaction(function () use ($meeting, $timetable, $actor, $date, $replacementDate, $type, $instructorId, $roomId, $startsAt, $duration, $data): AcademicTimetableChange {
            Institution::query()->lockForUpdate()->findOrFail($timetable->institution_id);
            $locked = AcademicTimetableMeeting::query()->lockForUpdate()->findOrFail($meeting->getKey());
            if ($locked->timetable_id !== $timetable->getKey() || $locked->status !== 'scheduled' || $timetable->fresh()->status !== 'published') {
                throw ValidationException::withMessages(['meeting' => 'The published meeting changed while the request was being prepared. Refresh and try again.']);
            }
            if (AcademicTimetableChange::query()->where('meeting_id', $locked->getKey())->where('occurrence_date', $date)->where('change_type', $type)->whereIn('status', ['pending', 'approved'])->exists()) {
                throw ValidationException::withMessages(['occurrence_date' => 'An active request of this type already exists for this occurrence.']);
            }
            $change = AcademicTimetableChange::query()->create([
                'timetable_id' => $timetable->getKey(), 'meeting_id' => $locked->getKey(), 'occurrence_date' => $date,
                'replacement_date' => $type === 'cancellation' ? null : $replacementDate, 'change_type' => $type,
                'starts_at_local' => $startsAt, 'duration_minutes' => $duration, 'instructor_user_id' => $instructorId,
                'room_id' => $roomId, 'reason' => trim((string) $data['reason']), 'status' => 'pending', 'requested_by' => $actor->getKey(),
            ]);
            $this->audit->record('academic.timetable.change_requested', $actor, $this->tenantId(), $change, ['change_type' => $type, 'meeting_id' => (int) $locked->getKey(), 'occurrence_date' => $date, 'replacement_date' => $change->replacement_date === null ? null : Carbon::parse($change->replacement_date)->toDateString()]);

            return $change;
        }, 3);
    }

    public function decideChange(AcademicTimetableChange $change, string $decision, User $actor): AcademicTimetableChange
    {
        $this->authorize('academic.policy.approve');
        $change->loadMissing(['timetable', 'meeting']);
        $this->assertTimetableOwned($change->timetable);

        return DB::transaction(function () use ($change, $decision, $actor): AcademicTimetableChange {
            Institution::query()->lockForUpdate()->findOrFail($change->timetable->institution_id);
            $locked = AcademicTimetableChange::query()->lockForUpdate()->findOrFail($change->getKey());
            $timetable = AcademicTimetable::query()->lockForUpdate()->findOrFail($locked->timetable_id);
            if ($locked->status !== 'pending' || $timetable->status !== 'published') {
                throw ValidationException::withMessages(['change' => 'Only pending requests for the current published timetable can be reviewed.']);
            }
            if ((int) $locked->requested_by === (int) $actor->getKey()) {
                throw new AuthorizationException('A separate reviewer must decide this timetable change.');
            }
            if ($decision === 'approve' && $locked->change_type !== 'cancellation') {
                $meeting = AcademicTimetableMeeting::query()->findOrFail($locked->meeting_id);
                $date = Carbon::parse($locked->replacement_date ?? $locked->occurrence_date)->toDateString();
                $candidate = [
                    'tenant_id' => $this->tenantId(), 'institution_id' => (int) $timetable->institution_id,
                    'academic_period_id' => (int) $timetable->academic_period_id, 'campus_id' => $timetable->campus_id,
                    'timetable_id' => (int) $timetable->getKey(), 'supersedes_timetable_id' => $timetable->supersedes_timetable_id,
                    'section_id' => (int) $meeting->section_id, 'instructor_user_id' => (int) ($locked->instructor_user_id ?? $meeting->instructor_user_id),
                    'room_id' => $locked->room_id ?? $meeting->room_id, 'starts_on' => $date, 'ends_on' => $date,
                    'weekdays' => [(int) Carbon::parse($date)->dayOfWeekIso], 'starts_at_local' => substr((string) ($locked->starts_at_local ?? $meeting->starts_at_local), 0, 5),
                    'duration_minutes' => (int) ($locked->duration_minutes ?? $meeting->duration_minutes), 'timezone' => $timetable->timezone,
                    'delivery_mode' => $meeting->delivery_mode, 'online_location' => $meeting->online_location,
                ];
                $assigned = DB::table('course_section_instructors')->where('tenant_id', $this->tenantId())->where('section_id', $meeting->section_id)->where('user_id', $candidate['instructor_user_id'])->exists();
                $membership = TenantMembership::query()->where('institution_id', $timetable->institution_id)->where('user_id', $candidate['instructor_user_id'])->where('status', 'active')->first();
                if (! $assigned || ! $membership || ! $membership->hasPermission('enrollment.instructor.roster.view')) {
                    throw ValidationException::withMessages(['change' => 'The proposed instructor is no longer actively assigned to this section.']);
                }
                if ($candidate['room_id'] !== null) {
                    $room = AcademicRoom::query()->where('institution_id', $timetable->institution_id)->when($timetable->campus_id !== null, fn ($query) => $query->where('campus_id', $timetable->campus_id))->where('status', 'active')->find($candidate['room_id']);
                    $section = CourseSection::query()->findOrFail($meeting->section_id);
                    $enrolled = CourseEnrollment::query()->where('section_id', $section->getKey())->whereIn('status', ['confirmed', 'offer_pending'])->count();
                    if (! $room || (int) $room->capacity < max((int) $section->capacity, $enrolled)) {
                        throw ValidationException::withMessages(['change' => 'The proposed room is no longer eligible or has insufficient capacity.']);
                    }
                } elseif (in_array($meeting->delivery_mode, ['in_person', 'hybrid'], true)) {
                    throw ValidationException::withMessages(['change' => 'The proposed class requires an active room.']);
                }
                $this->assertNoSourceSeriesConflict($meeting, (string) $locked->occurrence_date, $candidate);
                $this->assertNoApprovedExceptionConflict($meeting, $candidate, (int) $locked->getKey());
                if ($this->conflicts->conflicts($candidate, (int) $meeting->getKey()) !== []) {
                    throw ValidationException::withMessages(['change' => 'The proposed change now conflicts with the current schedule.']);
                }
            }
            $locked->update(['status' => $decision === 'approve' ? 'approved' : 'rejected', 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now()]);
            $this->audit->record('academic.timetable.change_reviewed', $actor, $this->tenantId(), $locked, ['change_type' => $locked->change_type, 'meeting_id' => (int) $locked->meeting_id, 'decision' => $decision]);
            if ($decision === 'approve') {
                $this->queueChangeNotifications($locked);
            }

            return $locked->refresh();
        }, 3);
    }

    private function queueChangeNotifications(AcademicTimetableChange $change): void
    {
        $recipients = DB::table('course_enrollments as e')->join('sis_students as s', function ($join): void {
            $join->on('s.id', '=', 'e.student_id')->on('s.tenant_id', '=', 'e.tenant_id');
        })->where('e.tenant_id', $this->tenantId())->where('e.section_id', $change->meeting->section_id)->where('e.status', 'confirmed')->pluck('s.user_id')
            ->merge(DB::table('course_section_instructors')->where('tenant_id', $this->tenantId())->where('section_id', $change->meeting->section_id)->pluck('user_id'))
            ->map(fn ($id): int => (int) $id)->unique()->values();
        $tenantSlug = (string) DB::table('tenants')->where('id', $this->tenantId())->value('slug');
        $eventType = match ($change->change_type) {
            'cancellation' => 'academic.schedule.cancelled',
            'substitution' => 'academic.schedule.substituted',
            'makeup' => 'academic.schedule.makeup_added',
            default => 'academic.schedule.rescheduled',
        };
        if ($change->room_id !== $change->meeting->room_id && $change->change_type !== 'cancellation') {
            $eventType = 'academic.schedule.room_changed';
        }
        foreach ($recipients as $recipientId) {
            $eventKey = 'academic-timetable-change:'.$change->getKey().':approved:'.$recipientId;
            $existing = DB::table('admission_outbox_events')->where('tenant_id', $this->tenantId())->where('safe_payload->event_key', $eventKey)->exists();
            if ($existing) {
                continue;
            }
            DB::table('admission_outbox_events')->insert([
                'tenant_id' => $this->tenantId(), 'application_id' => null, 'event_type' => $eventType, 'channel' => 'local',
                'safe_payload' => json_encode(['event_key' => $eventKey, 'recipient_user_id' => $recipientId, 'tenant_slug' => $tenantSlug, 'change_id' => (int) $change->getKey(), 'change_type' => $change->change_type, 'occurrence_date' => Carbon::parse($change->occurrence_date)->toDateString(), 'replacement_date' => $change->replacement_date === null ? null : Carbon::parse($change->replacement_date)->toDateString()], JSON_THROW_ON_ERROR),
                'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param array<string,mixed> $candidate */
    private function assertNoSourceSeriesConflict(AcademicTimetableMeeting $meeting, string $originalDate, array $candidate): void
    {
        $originalDate = Carbon::parse($originalDate)->toDateString();
        $targetDate = Carbon::parse($candidate['starts_on'])->toDateString();
        if ($targetDate === $originalDate) {
            return;
        }
        $source = $meeting->toArray() + ['timezone' => $meeting->timetable->timezone];
        $candidateOccurrence = $this->conflicts->occurrences($candidate)[$targetDate] ?? null;
        $sourceOccurrence = $this->conflicts->occurrences($source)[$targetDate] ?? null;
        if ($candidateOccurrence !== null && $sourceOccurrence !== null && $candidateOccurrence[0] < $sourceOccurrence[1] && $candidateOccurrence[1] > $sourceOccurrence[0]) {
            throw ValidationException::withMessages(['replacement_date' => 'The proposed date overlaps another occurrence in the same recurring series.']);
        }
    }

    /** @param array<string,mixed> $candidate */
    private function assertNoApprovedExceptionConflict(AcademicTimetableMeeting $meeting, array $candidate, ?int $ignoreChangeId = null): void
    {
        $date = Carbon::parse($candidate['starts_on'])->toDateString();
        $candidateRange = $this->conflicts->occurrences($candidate)[$date] ?? null;
        if ($candidateRange === null) {
            return;
        }
        foreach (AcademicTimetableChange::query()->where('meeting_id', $meeting->getKey())->where('status', 'approved')->where('id', '!=', $ignoreChangeId ?? 0)->get() as $existing) {
            if ($existing->change_type === 'cancellation') {
                continue;
            }
            $existingDate = in_array($existing->change_type, ['reschedule', 'makeup'], true)
                ? Carbon::parse($existing->replacement_date ?? $existing->occurrence_date)->toDateString()
                : Carbon::parse($existing->occurrence_date)->toDateString();
            if ($existingDate !== $date) {
                continue;
            }
            $existingStart = Carbon::instance(LocalScheduleTime::toUtc($existingDate, substr((string) ($existing->starts_at_local ?? $meeting->starts_at_local), 0, 5), $meeting->timetable->timezone));
            $existingEnd = $existingStart->copy()->addMinutes((int) ($existing->duration_minutes ?? $meeting->duration_minutes));
            if ($candidateRange[0] < $existingEnd && $candidateRange[1] > $existingStart) {
                throw ValidationException::withMessages(['replacement_date' => 'The proposed occurrence overlaps an already approved exception.']);
            }
        }
    }

    private function queuePublicationNotifications(AcademicTimetable $timetable): void
    {
        $tenantSlug = (string) DB::table('tenants')->where('id', $this->tenantId())->value('slug');
        foreach ($timetable->meetings()->where('status', 'scheduled')->get() as $meeting) {
            $recipients = DB::table('course_enrollments as e')->join('sis_students as s', function ($join): void {
                $join->on('s.id', '=', 'e.student_id')->on('s.tenant_id', '=', 'e.tenant_id');
            })->where('e.tenant_id', $this->tenantId())->where('e.section_id', $meeting->section_id)->where('e.status', 'confirmed')->pluck('s.user_id')
                ->merge(DB::table('course_section_instructors')->where('tenant_id', $this->tenantId())->where('section_id', $meeting->section_id)->pluck('user_id'))->map(fn ($id): int => (int) $id)->unique();
            foreach ($recipients as $recipientId) {
                $eventKey = 'academic-timetable:'.$timetable->getKey().':'.$timetable->version.':'.$meeting->getKey().':'.$recipientId;
                if (DB::table('admission_outbox_events')->where('tenant_id', $this->tenantId())->where('safe_payload->event_key', $eventKey)->exists()) {
                    continue;
                }
                DB::table('admission_outbox_events')->insert([
                    'tenant_id' => $this->tenantId(), 'application_id' => null, 'event_type' => 'academic.schedule.published', 'channel' => 'local',
                    'safe_payload' => json_encode(['event_key' => $eventKey, 'recipient_user_id' => $recipientId, 'tenant_slug' => $tenantSlug, 'timetable_id' => (int) $timetable->getKey(), 'version' => (int) $timetable->version, 'meeting_id' => (int) $meeting->getKey()], JSON_THROW_ON_ERROR),
                    'status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function submit(AcademicTimetable $timetable, User $actor): AcademicTimetable
    {
        $this->authorize('academic.calendar.manage');

        return DB::transaction(function () use ($timetable, $actor): AcademicTimetable {
            Institution::query()->lockForUpdate()->findOrFail($timetable->institution_id);
            $locked = AcademicTimetable::query()->lockForUpdate()->findOrFail($timetable->getKey());
            if ($locked->status !== 'draft' || ! $locked->meetings()->where('status', 'scheduled')->exists()) {
                throw ValidationException::withMessages(['timetable' => 'A draft with scheduled meetings is required for review.']);
            }
            foreach ($locked->meetings()->where('status', 'scheduled')->get() as $meeting) {
                $data = $meeting->toArray() + ['timezone' => $locked->timezone, 'academic_period_id' => $locked->academic_period_id, 'institution_id' => $locked->institution_id, 'campus_id' => $locked->campus_id, 'timetable_id' => (int) $locked->getKey(), 'supersedes_timetable_id' => $locked->supersedes_timetable_id];
                $issues = $this->conflicts->conflicts($data, (int) $meeting->getKey());
                if ($issues !== []) {
                    throw ValidationException::withMessages(['timetable' => 'Timetable validation found an unresolved conflict: '.implode(', ', array_unique(array_column($issues, 'type')))]);
                }
            }
            $locked->update(['status' => 'review', 'submitted_by' => $actor->getKey(), 'submitted_at' => now()]);
            $this->audit->record('academic.timetable.submitted', $actor, $this->tenantId(), $locked, ['version' => (int) $locked->version]);

            return $locked->refresh();
        }, 3);
    }

    public function decide(AcademicTimetable $timetable, string $decision, string $note, User $actor): AcademicTimetable
    {
        $this->authorize('academic.policy.approve');

        return DB::transaction(function () use ($timetable, $decision, $note, $actor): AcademicTimetable {
            Institution::query()->lockForUpdate()->findOrFail($timetable->institution_id);
            $locked = AcademicTimetable::query()->lockForUpdate()->findOrFail($timetable->getKey());
            if ($locked->status !== 'review' || ($locked->independent_review_required && (int) $locked->submitted_by === (int) $actor->getKey())) {
                throw new AuthorizationException('A separate authorized reviewer is required.');
            }
            $newStatus = $decision === 'approve' ? 'approved' : 'draft';
            $locked->update(['status' => $newStatus, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now()]);
            AcademicTimetableReview::query()->create(['timetable_id' => $locked->getKey(), 'version' => $locked->version, 'reviewer_user_id' => $actor->getKey(), 'decision' => $decision, 'note' => $note]);
            $this->audit->record('academic.timetable.reviewed', $actor, $this->tenantId(), $locked, ['version' => (int) $locked->version, 'decision' => $decision]);

            return $locked->refresh();
        }, 3);
    }

    public function publish(AcademicTimetable $timetable, User $actor): AcademicTimetable
    {
        $this->authorize('academic.policy.approve');

        return DB::transaction(function () use ($timetable, $actor): AcademicTimetable {
            Institution::query()->lockForUpdate()->findOrFail($timetable->institution_id);
            $locked = AcademicTimetable::query()->lockForUpdate()->findOrFail($timetable->getKey());
            if ($locked->status !== 'approved' || ($locked->independent_review_required && (int) $locked->reviewed_by === (int) $actor->getKey())) {
                throw new AuthorizationException('Publication requires an approved version and, when configured, a different publisher.');
            }
            $snapshot = ['timetable_id' => (int) $locked->getKey(), 'version' => (int) $locked->version, 'timezone' => $locked->timezone, 'period_id' => (int) $locked->academic_period_id,
                'meetings' => $locked->meetings()->where('status', 'scheduled')->orderBy('id')->get()->map(fn (AcademicTimetableMeeting $meeting): array => $meeting->only(['id', 'section_id', 'instructor_user_id', 'room_id', 'starts_on', 'ends_on', 'weekdays', 'starts_at_local', 'duration_minutes', 'delivery_mode', 'online_location']))->all()];
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            AcademicTimetableSnapshot::query()->create(['timetable_id' => $locked->getKey(), 'version' => $locked->version, 'snapshot' => $snapshot, 'sha256' => hash('sha256', $json), 'published_at' => now()]);
            AcademicTimetable::query()->where('tenant_id', $locked->tenant_id)->where('academic_period_id', $locked->academic_period_id)->where('campus_id', $locked->campus_id)
                ->where('status', 'published')->where('id', '!=', $locked->getKey())->update(['status' => 'superseded']);
            $locked->update(['status' => 'published', 'published_by' => $actor->getKey(), 'published_at' => now()]);
            $this->queuePublicationNotifications($locked);
            $this->audit->record('academic.timetable.published', $actor, $this->tenantId(), $locked, ['version' => (int) $locked->version, 'snapshot_sha256' => hash('sha256', $json)]);

            return $locked->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function candidate(AcademicTimetable $timetable, array $data): array
    {
        $period = AcademicPeriod::query()->where('academic_year_id', $timetable->academic_year_id)->findOrFail($timetable->academic_period_id);
        if ($data['starts_on'] < Carbon::parse($period->starts_on)->toDateString() || $data['ends_on'] > Carbon::parse($period->ends_on)->toDateString()) {
            throw ValidationException::withMessages(['starts_on' => 'Meetings must remain within the academic period.']);
        }
        $weekdays = array_values(array_unique(array_map('intval', $data['weekdays'])));
        sort($weekdays);
        if ($weekdays === [] || min($weekdays) < 1 || max($weekdays) > 7) {
            throw ValidationException::withMessages(['weekdays' => 'Choose one or more valid weekdays.']);
        }
        if (Carbon::parse($data['ends_on'])->diffInDays(Carbon::parse($data['starts_on'])) > 370) {
            throw ValidationException::withMessages(['ends_on' => 'A recurrence may span no more than 370 days.']);
        }
        $result = [...$data, 'tenant_id' => $this->tenantId(), 'institution_id' => (int) $timetable->institution_id, 'academic_period_id' => (int) $period->getKey(), 'campus_id' => $timetable->campus_id === null ? null : (int) $timetable->campus_id,
            'timetable_id' => (int) $timetable->getKey(), 'supersedes_timetable_id' => $timetable->supersedes_timetable_id === null ? null : (int) $timetable->supersedes_timetable_id,
            'weekdays' => $weekdays, 'timezone' => $timetable->timezone, 'duration_minutes' => (int) $data['duration_minutes'], 'room_id' => empty($data['room_id']) ? null : (int) $data['room_id'],
            'instructor_user_id' => (int) $data['instructor_user_id'], 'section_id' => (int) $data['section_id'], 'online_location' => $data['online_location'] ?? null];
        if ($result['duration_minutes'] < 10 || $result['duration_minutes'] > 480) {
            throw ValidationException::withMessages(['duration_minutes' => 'Meeting duration must be between 10 minutes and 8 hours.']);
        }
        // Expansion performs strict DST existence/ambiguity checks for each local occurrence.
        $this->conflicts->occurrences($result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $candidate
     */
    private function assertSectionInstructorRoom(AcademicTimetable $timetable, array $data, array $candidate): void
    {
        if ($timetable->status !== 'draft') {
            throw ValidationException::withMessages(['timetable' => 'Only draft timetable versions can be edited.']);
        }
        $section = CourseSection::query()->with('offering')->find($candidate['section_id']);
        if (! $section || (int) $section->offering->institution_id !== (int) $timetable->institution_id || (int) $section->offering->academic_period_id !== (int) $timetable->academic_period_id || ($timetable->campus_id !== null && (int) $section->campus_id !== (int) $timetable->campus_id)) {
            throw new ModelNotFoundException;
        }
        $membership = TenantMembership::query()->where('institution_id', $timetable->institution_id)->where('user_id', $candidate['instructor_user_id'])->where('status', 'active')->first();
        $assigned = DB::table('course_section_instructors')->where('tenant_id', $this->tenantId())->where('section_id', $section->getKey())->where('user_id', $candidate['instructor_user_id'])->exists();
        if (! $membership || ! $membership->hasPermission('enrollment.instructor.roster.view') || ! $assigned) {
            throw ValidationException::withMessages(['instructor_user_id' => 'Select an active instructor explicitly assigned to this section.']);
        }
        if ($candidate['room_id'] === null && in_array($candidate['delivery_mode'], ['in_person', 'hybrid'], true)) {
            throw ValidationException::withMessages(['room_id' => 'In-person and hybrid meetings require an assigned room.']);
        }
        if ($candidate['delivery_mode'] === 'online' && (! is_string($candidate['online_location']) || ! str_starts_with($candidate['online_location'], 'https://'))) {
            throw ValidationException::withMessages(['online_location' => 'Online meeting locations must use HTTPS.']);
        }
        if ($candidate['room_id'] !== null) {
            $room = AcademicRoom::query()->where('institution_id', $timetable->institution_id)->where('status', 'active')->find($candidate['room_id']);
            if (! $room || ($timetable->campus_id !== null && (int) $room->campus_id !== (int) $timetable->campus_id)) {
                throw ValidationException::withMessages(['room_id' => 'Choose an active room in the timetable institution and campus.']);
            }
            $enrolled = CourseEnrollment::query()->where('section_id', $section->getKey())->whereIn('status', ['confirmed', 'offer_pending'])->count();
            if ((int) $room->capacity < max((int) $section->capacity, $enrolled)) {
                throw ValidationException::withMessages(['room_id' => 'Room capacity is lower than the section capacity or current enrollment.']);
            }
        }
    }

    private function assertTimetableOwned(AcademicTimetable $timetable): void
    {
        if ((int) $timetable->tenant_id !== $this->tenantId() || (int) $timetable->institution_id !== (int) $this->institution()->getKey()) {
            throw new ModelNotFoundException;
        }
        $this->assertCampus($timetable->campus_id === null ? null : (int) $timetable->campus_id);
    }

    private function assertCampus(?int $campusId): void
    {
        $assigned = $this->context->membership()->campus_id;
        if ($assigned !== null && (int) $assigned !== (int) $campusId) {
            throw new ModelNotFoundException;
        }
    }

    private function assertCampusBelongs(int $campusId, int $institutionId): void
    {
        if (! DB::table('campuses')->where('tenant_id', $this->tenantId())->where('institution_id', $institutionId)->where('id', $campusId)->exists()) {
            throw new ModelNotFoundException;
        }
    }

    private function campusIsAccessible(int $campusId): bool
    {
        $assigned = $this->context->membership()->campus_id;

        return $assigned === null || (int) $assigned === $campusId;
    }

    private function institution(): Institution
    {
        return Institution::query()->firstOrFail();
    }

    private function tenantId(): int
    {
        return (int) $this->context->tenant()->getKey();
    }

    private function authorize(string $permission): void
    {
        if (! $this->context->membership()->hasPermission($permission)) {
            throw new AuthorizationException;
        }
    }
}
