<?php

namespace App\Domain\Enrollment;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicCourse;
use App\Models\AcademicCourseVersion;
use App\Models\AcademicLevel;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\CourseDeliveryAssignment;
use App\Models\CourseDeliveryWorkspace;
use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\CourseRegistrationHold;
use App\Models\CourseSection;
use App\Models\CourseWaitlistEntry;
use App\Models\EnrollmentPolicy;
use App\Models\Institution;
use App\Models\SisStudent;
use App\Models\SisStudentPlacement;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CourseEnrollmentWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly WaitlistNotificationOutbox $waitlistOutbox,
    ) {}

    /** @param array<string, mixed> $data */
    public function createPolicy(array $data, User $actor): EnrollmentPolicy
    {
        $this->authorize('enrollment.registrar.manage');
        $institution = $this->institution();
        $levelIds = array_values(array_unique(array_map('intval', $data['academic_level_ids'] ?? [])));
        foreach ($levelIds as $levelId) {
            if (! AcademicLevel::query()->where('institution_id', $institution->getKey())->whereKey($levelId)->exists()) {
                throw ValidationException::withMessages(['academic_level_ids' => 'Every selected academic level must belong to this institution.']);
            }
        }

        return DB::transaction(function () use ($data, $actor, $institution, $levelIds): EnrollmentPolicy {
            $policy = EnrollmentPolicy::query()->create([
                'institution_id' => $institution->getKey(), 'name' => $data['name'],
                'minimum_courses' => $data['minimum_courses'] ?? 0, 'maximum_courses' => $data['maximum_courses'],
                'maximum_credits' => $data['maximum_credits'] ?? null, 'requires_approval' => $data['requires_approval'] ?? false,
                'waitlist_enabled' => $data['waitlist_enabled'] ?? true, 'waitlist_offer_hours' => $data['waitlist_offer_hours'] ?? 48,
                'require_prerequisites' => $data['require_prerequisites'] ?? false,
                'require_withdrawal_approval' => $data['require_withdrawal_approval'] ?? true,
                'allow_section_transfer' => $data['allow_section_transfer'] ?? true,
                'allow_late_registration' => $data['allow_late_registration'] ?? false,
                'waitlist_ordering' => $data['waitlist_ordering'] ?? 'fifo',
                'status' => 'active', 'created_by' => $actor->getKey(),
            ]);
            foreach ($levelIds as $levelId) {
                DB::table('enrollment_policy_academic_levels')->insert([
                    'tenant_id' => $this->context->tenant()->getKey(), 'policy_id' => $policy->getKey(), 'academic_level_id' => $levelId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit->record('enrollment.policy.created', $actor, (int) $this->context->tenant()->getKey(), $policy, ['policy_id' => $policy->getKey()]);

            return $policy;
        });
    }

    /** @param array<string, mixed> $data */
    public function updatePolicy(EnrollmentPolicy $policy, array $data, User $actor): EnrollmentPolicy
    {
        $this->authorize('enrollment.registrar.manage');
        $this->assertOwned($policy);
        $institution = $this->institution();
        $syncLevels = array_key_exists('academic_level_ids', $data);
        $levelIds = array_values(array_unique(array_map('intval', $data['academic_level_ids'] ?? [])));
        foreach ($levelIds as $levelId) {
            if (! AcademicLevel::query()->where('institution_id', $institution->getKey())->whereKey($levelId)->exists()) {
                throw ValidationException::withMessages(['academic_level_ids' => 'Every selected academic level must belong to this institution.']);
            }
        }

        return DB::transaction(function () use ($policy, $data, $actor, $institution, $levelIds, $syncLevels): EnrollmentPolicy {
            $locked = EnrollmentPolicy::query()->lockForUpdate()->findOrFail($policy->getKey());
            if ((int) $locked->institution_id !== (int) $institution->getKey()) {
                throw new ModelNotFoundException;
            }
            $locked->fill(collect($data)->except('academic_level_ids')->all())->save();
            if ($syncLevels) {
                DB::table('enrollment_policy_academic_levels')->where('tenant_id', $this->context->tenant()->getKey())->where('policy_id', $locked->getKey())->delete();
                foreach ($levelIds as $levelId) {
                    DB::table('enrollment_policy_academic_levels')->insert([
                        'tenant_id' => $this->context->tenant()->getKey(), 'policy_id' => $locked->getKey(), 'academic_level_id' => $levelId,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            $this->audit->record('enrollment.policy.updated', $actor, (int) $this->context->tenant()->getKey(), $locked, ['policy_id' => $locked->getKey(), 'changed_fields' => array_values(array_diff(array_keys($data), ['academic_level_ids']))]);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createOffering(array $data, User $actor): CourseOffering
    {
        $this->authorize('enrollment.registrar.manage');
        $tenantId = (int) $this->context->tenant()->getKey();
        $institution = $this->institution();
        $this->assertOwned(AcademicYear::query()->where('institution_id', $institution->getKey())->find($data['academic_year_id'] ?? null));
        $period = AcademicPeriod::query()->find($data['academic_period_id'] ?? null);
        if (! $period || (int) $period->academic_year_id !== (int) $data['academic_year_id']) {
            throw ValidationException::withMessages(['academic_period_id' => 'Select a period belonging to the selected academic year.']);
        }
        $this->assertOwned(AcademicCourse::query()->where('institution_id', $institution->getKey())->find($data['academic_course_id'] ?? null));
        if (! empty($data['academic_program_id'])) {
            $this->assertOwned(AcademicProgram::query()->where('institution_id', $institution->getKey())->find($data['academic_program_id']));
        }
        if (! empty($data['campus_id'])) {
            $this->assertOwned(Campus::query()->where('institution_id', $institution->getKey())->find($data['campus_id']));
        }
        if (! empty($data['academic_course_version_id'])) {
            $version = AcademicCourseVersion::query()->where('academic_course_id', $data['academic_course_id'])->find($data['academic_course_version_id']);
            if (! $version || $version->status !== 'published') {
                throw ValidationException::withMessages(['academic_course_version_id' => 'Choose a published version of the selected course.']);
            }
        }
        $policy = EnrollmentPolicy::query()->where('institution_id', $institution->getKey())->find($data['policy_id'] ?? null);
        if (! $policy || $policy->status !== 'active') {
            throw ValidationException::withMessages(['policy_id' => 'Select an active registration policy.']);
        }
        if (! empty($data['delivery_workspace_id'])) {
            $workspace = CourseDeliveryWorkspace::query()->where('institution_id', $institution->getKey())->find($data['delivery_workspace_id']);
            if (! $workspace || (int) $workspace->academic_course_id !== (int) $data['academic_course_id']) {
                throw ValidationException::withMessages(['delivery_workspace_id' => 'The learning workspace must belong to this institution and course.']);
            }
        }

        foreach (['registration_opens_at', 'registration_closes_at', 'late_registration_closes_at', 'add_deadline_at', 'drop_deadline_at', 'withdrawal_deadline_at'] as $dateField) {
            if (! empty($data[$dateField])) {
                $data[$dateField] = Carbon::parse($data[$dateField], $institution->timezone ?: 'UTC')->utc();
            }
        }

        return DB::transaction(function () use ($data, $actor, $institution, $tenantId): CourseOffering {
            $offering = CourseOffering::query()->create($data + [
                'institution_id' => $institution->getKey(), 'status' => 'draft', 'created_by' => $actor->getKey(),
                'institution_timezone' => $institution->timezone ?: 'UTC',
            ]);
            $this->audit->record('enrollment.offering.created', $actor, $tenantId, $offering, ['offering_id' => $offering->getKey(), 'course_id' => $offering->academic_course_id]);

            return $offering;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateOffering(CourseOffering $offering, array $data, User $actor): CourseOffering
    {
        $this->authorize('enrollment.registrar.manage');
        $this->assertOwned($offering);
        $institution = $this->institution();
        $policy = EnrollmentPolicy::query()->where('institution_id', $institution->getKey())->where('status', 'active')->find($data['policy_id'] ?? null);
        if (! $policy) {
            throw ValidationException::withMessages(['policy_id' => 'Select an active policy in this institution.']);
        }
        foreach (['campus_id' => Campus::class, 'academic_program_id' => AcademicProgram::class] as $field => $model) {
            if (! empty($data[$field])) {
                $this->assertOwned($model::query()->where('institution_id', $institution->getKey())->find($data[$field]));
            }
        }
        foreach (['registration_opens_at', 'registration_closes_at', 'late_registration_closes_at', 'add_deadline_at', 'drop_deadline_at', 'withdrawal_deadline_at'] as $dateField) {
            if (array_key_exists($dateField, $data)) {
                $data[$dateField] = $data[$dateField] ? Carbon::parse($data[$dateField], $offering->institution_timezone ?: 'UTC')->utc() : null;
            }
        }

        return DB::transaction(function () use ($offering, $data, $actor): CourseOffering {
            $locked = CourseOffering::query()->lockForUpdate()->findOrFail($offering->getKey());
            if ($locked->institution_id !== $this->institution()->getKey() || in_array($locked->status, ['cancelled', 'completed'], true)) {
                throw new ModelNotFoundException;
            }
            $locked->fill($data)->save();
            $this->audit->record('enrollment.offering.updated', $actor, (int) $this->context->tenant()->getKey(), $locked, ['changed_fields' => array_values(array_intersect(array_keys($data), ['policy_id', 'campus_id', 'academic_program_id', 'delivery_mode', 'registration_opens_at', 'registration_closes_at', 'late_registration_closes_at', 'add_deadline_at', 'drop_deadline_at', 'withdrawal_deadline_at']))]);

            return $locked->refresh();
        }, 3);
    }

    public function createSection(CourseOffering $offering, string $code, int $capacity, int $reservedSeats, ?int $campusId, User $actor): CourseSection
    {
        $this->authorize('enrollment.registrar.manage');
        if ($offering->institution_id !== $this->institution()->getKey() || $offering->status === 'cancelled') {
            throw new ModelNotFoundException;
        }
        if ($reservedSeats > $capacity) {
            throw ValidationException::withMessages(['reserved_seats' => 'Reserved seats cannot exceed section capacity.']);
        }
        if ($campusId !== null) {
            $this->assertOwned(Campus::query()->where('institution_id', $offering->institution_id)->find($campusId));
        }
        $section = CourseSection::query()->create([
            'offering_id' => $offering->getKey(), 'campus_id' => $campusId ?? $offering->campus_id,
            'section_code' => $code, 'capacity' => $capacity, 'reserved_seats' => $reservedSeats, 'status' => 'open',
        ]);
        $this->audit->record('enrollment.section.created', $actor, (int) $this->context->tenant()->getKey(), $section, ['offering_id' => $offering->getKey(), 'section_id' => $section->getKey()]);

        return $section;
    }

    public function updateSection(CourseSection $section, int $capacity, int $reservedSeats, ?int $campusId, string $status, User $actor): CourseSection
    {
        $this->authorize('enrollment.registrar.manage');
        $this->assertOwned($section);
        $offering = $section->offering;
        if ($offering->institution_id !== $this->institution()->getKey() || in_array($offering->status, ['cancelled', 'completed'], true)) {
            throw new ModelNotFoundException;
        }
        if ($reservedSeats > $capacity) {
            throw ValidationException::withMessages(['reserved_seats' => 'Reserved seats cannot exceed section capacity.']);
        }
        if ($campusId !== null) {
            $this->assertOwned(Campus::query()->where('institution_id', $offering->institution_id)->find($campusId));
        }

        return DB::transaction(function () use ($section, $capacity, $reservedSeats, $campusId, $status, $actor): CourseSection {
            $locked = CourseSection::query()->lockForUpdate()->findOrFail($section->getKey());
            $activeEnrollments = (int) CourseEnrollment::query()->where('section_id', $locked->getKey())->whereIn('status', ['confirmed', 'offer_pending'])->count();
            if ($capacity < $activeEnrollments + $reservedSeats) {
                throw ValidationException::withMessages(['capacity' => 'Capacity cannot be lower than reserved seats or current occupied seats.']);
            }
            if (! in_array($status, ['open', 'closed'], true)) {
                throw ValidationException::withMessages(['status' => 'Choose an open or closed section status.']);
            }
            $locked->update(['capacity' => $capacity, 'reserved_seats' => $reservedSeats, 'campus_id' => $campusId, 'status' => $status]);
            $this->audit->record('enrollment.section.updated', $actor, (int) $this->context->tenant()->getKey(), $locked, ['capacity' => $capacity, 'reserved_seats' => $reservedSeats, 'status' => $status]);

            return $locked->refresh();
        }, 3);
    }

    public function transitionOffering(CourseOffering $offering, string $status, User $actor, ?string $reason = null): CourseOffering
    {
        $this->authorize('enrollment.registrar.manage');
        $allowed = ['draft' => ['open', 'cancelled'], 'open' => ['closed', 'cancelled'], 'closed' => ['open', 'completed', 'cancelled'], 'completed' => [], 'cancelled' => []];

        return DB::transaction(function () use ($offering, $status, $reason, $actor, $allowed): CourseOffering {
            $locked = CourseOffering::query()->lockForUpdate()->findOrFail($offering->getKey());
            if ($locked->institution_id !== $this->institution()->getKey() || ! in_array($status, $allowed[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'This offering status transition is not allowed.']);
            }
            if ($status === 'open' && ! $locked->sections()->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['status' => 'Create at least one open section before opening registration.']);
            }
            if ($status === 'cancelled' && mb_strlen(trim((string) $reason)) < 8) {
                throw ValidationException::withMessages(['reason' => 'Provide a cancellation reason of at least 8 characters.']);
            }
            $locked->update(['status' => $status, 'cancellation_reason' => $status === 'cancelled' ? $reason : $locked->cancellation_reason]);
            if ($status === 'cancelled') {
                $active = CourseEnrollment::query()->where('offering_id', $locked->getKey())
                    ->whereIn('status', ['pending', 'confirmed', 'waitlisted', 'offer_pending', 'pending_withdrawal'])->lockForUpdate()->get();
                foreach ($active as $enrollment) {
                    $from = $enrollment->status;
                    $enrollment->update(['status' => 'cancelled']);
                    $this->recordEvent($enrollment, 'offering_cancelled', $from, 'cancelled', $actor, $reason);
                    $this->syncLearningAccess($enrollment, $actor, false);
                }
                CourseWaitlistEntry::query()->where('offering_id', $locked->getKey())->whereIn('status', ['waiting', 'offered'])->update(['status' => 'withdrawn', 'updated_at' => now()]);
            }
            $this->audit->record('enrollment.offering.status_changed', $actor, (int) $this->context->tenant()->getKey(), $locked, ['status' => $status, 'reason_recorded' => $status === 'cancelled']);

            return $locked->refresh();
        }, 3);
    }

    public function register(int $sectionId, string $idempotencyKey, User $actor): CourseEnrollment
    {
        $this->authorize('enrollment.student.register');
        $tenantId = (int) $this->context->tenant()->getKey();

        return DB::transaction(function () use ($sectionId, $idempotencyKey, $actor, $tenantId): CourseEnrollment {
            $section = CourseSection::query()->with('offering.policy', 'offering.period', 'offering.courseVersion')
                ->where('status', 'open')->lockForUpdate()->findOrFail($sectionId);
            $offering = $section->offering;
            $policy = $offering->policy;
            $student = SisStudent::query()->where('user_id', $actor->getKey())->where('institution_id', $offering->institution_id)
                ->where('status', 'active')->lockForUpdate()->first();
            if (! $student || $this->context->membership()->institution_id !== $student->institution_id) {
                throw new AuthorizationException('Active student registration is unavailable for this account.');
            }
            $this->assertStudentEligibility($student, $section, $offering, $policy);
            $existingKey = CourseEnrollment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existingKey) {
                if ((int) $existingKey->student_id === (int) $student->getKey() && (int) $existingKey->offering_id === (int) $offering->getKey() && (int) $existingKey->section_id === (int) $section->getKey()) {
                    return $existingKey;
                }
                throw ValidationException::withMessages(['idempotency_key' => 'This request key has already been used.']);
            }
            $enrollment = CourseEnrollment::query()->where('student_id', $student->getKey())->where('offering_id', $offering->getKey())->lockForUpdate()->first();
            if ($enrollment && in_array($enrollment->status, ['confirmed', 'pending', 'waitlisted', 'offer_pending'], true)) {
                throw ValidationException::withMessages(['section_id' => 'You already have an active registration for this course.']);
            }

            $status = 'pending';
            $previousStatus = $enrollment?->status;
            if (! $policy->requires_approval) {
                if ($this->occupiedSeats($section) < (int) $section->capacity) {
                    $status = 'confirmed';
                } elseif ($policy->waitlist_enabled) {
                    $status = 'waitlisted';
                } else {
                    throw ValidationException::withMessages(['section_id' => 'This section is full and does not have a waitlist.']);
                }
            }

            $values = [
                'institution_id' => $student->institution_id, 'student_id' => $student->getKey(), 'offering_id' => $offering->getKey(),
                'section_id' => $section->getKey(), 'status' => $status, 'registration_source' => 'student',
                'idempotency_key' => $idempotencyKey, 'requested_at' => now(), 'confirmed_at' => $status === 'confirmed' ? now() : null,
                'dropped_at' => null, 'withdrawn_at' => null, 'reviewed_by' => null, 'review_reason' => null,
                'credits_snapshot' => $offering->courseVersion?->total_credits,
            ];
            if ($enrollment) {
                $enrollment->fill($values)->save();
            } else {
                $enrollment = CourseEnrollment::query()->create($values);
            }
            if ($status === 'waitlisted') {
                $position = (int) CourseWaitlistEntry::query()->where('section_id', $section->getKey())->max('position') + 1;
                $waitlist = CourseWaitlistEntry::query()->where('student_id', $student->getKey())->where('section_id', $section->getKey())->first();
                if ($waitlist) {
                    $waitlist->update(['position' => $position, 'status' => 'waiting', 'offered_at' => null, 'offer_expires_at' => null, 'accepted_at' => null]);
                } else {
                    CourseWaitlistEntry::query()->create([
                        'student_id' => $student->getKey(), 'offering_id' => $offering->getKey(), 'section_id' => $section->getKey(),
                        'position' => $position, 'status' => 'waiting',
                    ]);
                }
            }
            $this->audit->record('enrollment.registration.'.$status, $actor, $tenantId, $enrollment, ['offering_id' => $offering->getKey(), 'section_id' => $section->getKey()]);
            $this->recordEvent($enrollment, 'registration_requested', $previousStatus, $status, $actor, null, ['section_id' => (int) $section->getKey()]);
            if ($status === 'confirmed') {
                $this->syncLearningAccess($enrollment, $actor, true);
            }

            return $enrollment;
        }, 3);
    }

    /** @return array{offering_code:string,course:string,section_code:string,available_seats:int,full:bool,waitlist_enabled:bool,expected_status:string} */
    public function previewRegistration(int $sectionId, User $actor): array
    {
        $this->authorize('enrollment.student.register');
        $tenantId = (int) $this->context->tenant()->getKey();
        $section = CourseSection::query()->with(['offering.policy', 'offering.period', 'offering.courseVersion', 'offering.course'])
            ->where('status', 'open')->findOrFail($sectionId);
        $offering = $section->offering;
        $student = SisStudent::query()->where('user_id', $actor->getKey())->where('institution_id', $offering->institution_id)->where('status', 'active')->first();
        if (! $student || (int) $this->context->membership()->institution_id !== (int) $student->institution_id) {
            throw new AuthorizationException('Active student registration is unavailable for this account.');
        }
        $policy = $offering->policy;
        $this->assertStudentEligibility($student, $section, $offering, $policy);
        if (CourseEnrollment::query()->where('student_id', $student->getKey())->where('offering_id', $offering->getKey())
            ->whereIn('status', ['confirmed', 'pending', 'waitlisted', 'offer_pending'])->exists()) {
            throw ValidationException::withMessages(['section_id' => 'You already have an active registration for this course.']);
        }
        $available = max(0, (int) $section->capacity - (int) $section->reserved_seats - $this->occupiedSeats($section));
        $full = $available === 0;
        if ($full && ! $policy->waitlist_enabled) {
            throw ValidationException::withMessages(['section_id' => 'This section is full and does not have a waitlist.']);
        }

        return [
            'offering_code' => $offering->offering_code, 'course' => $offering->course->title,
            'section_code' => $section->section_code, 'available_seats' => $available, 'full' => $full,
            'waitlist_enabled' => (bool) $policy->waitlist_enabled,
            'expected_status' => $full ? 'waitlisted' : ($policy->requires_approval ? 'pending' : 'confirmed'),
        ];
    }

    public function drop(CourseEnrollment $enrollment, User $actor, ?string $reason = null): CourseEnrollment
    {
        $student = SisStudent::query()->where('user_id', $actor->getKey())->where('institution_id', $enrollment->institution_id)->where('status', 'active')->find($enrollment->student_id);
        $isRegistrar = $this->context->membership()->hasPermission('enrollment.registrar.manage');
        if (! $student && ! $isRegistrar) {
            throw new ModelNotFoundException;
        }
        if ($student) {
            $this->authorize('enrollment.student.register');
        } else {
            $this->authorize('enrollment.registrar.manage');
        }

        return DB::transaction(function () use ($enrollment, $actor, $reason): CourseEnrollment {
            $locked = CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollment->getKey());
            if (! in_array($locked->status, ['confirmed', 'pending', 'waitlisted', 'offer_pending'], true)) {
                throw ValidationException::withMessages(['enrollment' => 'Only an active enrollment can be dropped.']);
            }
            $section = CourseSection::query()->lockForUpdate()->findOrFail($locked->section_id);
            $previous = $locked->status;
            if (in_array($previous, ['confirmed', 'pending', 'offer_pending'], true)) {
                $policy = $section->offering->policy;
                $activeCount = CourseEnrollment::query()->where('student_id', $locked->student_id)
                    ->whereIn('offering_id', CourseOffering::query()->select('id')->where('academic_period_id', $section->offering->academic_period_id))
                    ->whereIn('status', ['confirmed', 'pending', 'offer_pending'])->count();
                if ($activeCount - 1 < (int) $policy->minimum_courses) {
                    throw ValidationException::withMessages(['enrollment' => 'This change would put the student below the policy minimum course load.']);
                }
            }
            $offering = $section->offering;
            if ($offering->drop_deadline_at && now('UTC')->gt($offering->drop_deadline_at)) {
                throw ValidationException::withMessages(['enrollment' => 'The drop deadline has passed; request a withdrawal instead.']);
            }
            $locked->update(['status' => 'dropped', 'dropped_at' => now(), 'reviewed_by' => $actor->getKey(), 'review_reason' => $reason]);
            CourseWaitlistEntry::query()->where('student_id', $locked->student_id)->where('section_id', $section->getKey())->whereIn('status', ['waiting', 'offered'])->update(['status' => 'withdrawn', 'updated_at' => now()]);
            $this->promoteNext($section);
            $this->recordEvent($locked, 'dropped', $previous, 'dropped', $actor, $reason);
            $this->syncLearningAccess($locked, $actor, false);
            $this->audit->record('enrollment.registration.dropped', $actor, (int) $this->context->tenant()->getKey(), $locked, ['previous_status' => $previous, 'section_id' => $section->getKey()]);

            return $locked->refresh();
        }, 3);
    }

    public function decide(int $enrollmentId, string $decision, string $reason, User $actor): CourseEnrollment
    {
        $this->authorize('enrollment.registrar.manage');
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose approve or reject.']);
        }

        return DB::transaction(function () use ($enrollmentId, $decision, $reason, $actor): CourseEnrollment {
            $enrollment = CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollmentId);
            if ($enrollment->status !== 'pending') {
                throw ValidationException::withMessages(['enrollment' => 'This request is no longer pending approval.']);
            }
            $section = CourseSection::query()->lockForUpdate()->findOrFail($enrollment->section_id);
            if ($decision === 'approve' && $this->occupiedSeats($section) >= (int) $section->capacity) {
                throw ValidationException::withMessages(['enrollment' => 'Capacity changed; this request cannot be confirmed.']);
            }
            $enrollment->update([
                'status' => $decision === 'approve' ? 'confirmed' : 'rejected',
                'confirmed_at' => $decision === 'approve' ? now() : null,
                'reviewed_by' => $actor->getKey(), 'review_reason' => $reason,
            ]);
            $this->recordEvent($enrollment, 'registrar_decision', 'pending', $decision === 'approve' ? 'confirmed' : 'rejected', $actor, $reason);
            if ($decision === 'approve') {
                $this->syncLearningAccess($enrollment, $actor, true);
            }
            $this->audit->record('enrollment.registration.'.$decision.'d', $actor, (int) $this->context->tenant()->getKey(), $enrollment, ['section_id' => $section->getKey()]);

            return $enrollment->refresh();
        }, 3);
    }

    public function requestWithdrawal(int $enrollmentId, string $reason, User $actor): CourseEnrollment
    {
        $this->authorize('enrollment.student.register');

        return DB::transaction(function () use ($enrollmentId, $reason, $actor): CourseEnrollment {
            $enrollment = CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollmentId);
            $student = SisStudent::query()->where('user_id', $actor->getKey())->where('institution_id', $enrollment->institution_id)->where('status', 'active')->find($enrollment->student_id);
            if (! $student) {
                throw new ModelNotFoundException;
            }
            if ((int) $this->context->membership()->institution_id !== (int) $student->institution_id) {
                throw new AuthorizationException;
            }
            if ($enrollment->status !== 'confirmed') {
                throw ValidationException::withMessages(['enrollment' => 'Only a confirmed enrollment can be withdrawn.']);
            }
            $offering = CourseOffering::query()->findOrFail($enrollment->offering_id);
            if ($offering->withdrawal_deadline_at && now('UTC')->gt($offering->withdrawal_deadline_at)) {
                throw ValidationException::withMessages(['enrollment' => 'The withdrawal deadline has passed.']);
            }
            $requiresApproval = (bool) $offering->policy->require_withdrawal_approval;
            $next = $requiresApproval ? 'pending_withdrawal' : 'withdrawn';
            $enrollment->update([
                'status' => $next, 'withdrawal_reason' => $reason,
                'withdrawn_at' => $requiresApproval ? null : now(), 'withdrawn_effective_at' => $requiresApproval ? null : now(),
            ]);
            $this->recordEvent($enrollment, 'withdrawal_requested', 'confirmed', $next, $actor, $reason);
            if (! $requiresApproval) {
                $this->syncLearningAccess($enrollment, $actor, false);
                $this->promoteNext(CourseSection::query()->findOrFail($enrollment->section_id));
            }
            $this->audit->record('enrollment.withdrawal.requested', $actor, (int) $this->context->tenant()->getKey(), $enrollment, ['status' => $next]);

            return $enrollment->refresh();
        }, 3);
    }

    public function decideWithdrawal(int $enrollmentId, string $decision, string $reason, User $actor): CourseEnrollment
    {
        $this->authorize('enrollment.registrar.manage');
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose approve or reject.']);
        }

        return DB::transaction(function () use ($enrollmentId, $decision, $reason, $actor): CourseEnrollment {
            $enrollment = CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollmentId);
            if ($enrollment->status !== 'pending_withdrawal') {
                throw ValidationException::withMessages(['enrollment' => 'This withdrawal is no longer pending.']);
            }
            $section = CourseSection::query()->lockForUpdate()->findOrFail($enrollment->section_id);
            $next = $decision === 'approve' ? 'withdrawn' : 'confirmed';
            $enrollment->update([
                'status' => $next, 'reviewed_by' => $actor->getKey(), 'review_reason' => $reason,
                'withdrawn_at' => $decision === 'approve' ? now() : null,
                'withdrawn_effective_at' => $decision === 'approve' ? now() : null,
            ]);
            $this->recordEvent($enrollment, 'withdrawal_'.$decision.'d', 'pending_withdrawal', $next, $actor, $reason);
            if ($decision === 'approve') {
                $this->syncLearningAccess($enrollment, $actor, false);
                $this->promoteNext($section);
            }
            $this->audit->record('enrollment.withdrawal.'.$decision.'d', $actor, (int) $this->context->tenant()->getKey(), $enrollment, ['reason_recorded' => true]);

            return $enrollment->refresh();
        }, 3);
    }

    public function requestSectionTransfer(int $enrollmentId, int $destinationSectionId, string $reason, User $actor): int
    {
        $this->authorize('enrollment.student.register');

        return DB::transaction(function () use ($enrollmentId, $destinationSectionId, $reason, $actor): int {
            $enrollment = CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollmentId);
            $student = SisStudent::query()->where('user_id', $actor->getKey())->where('institution_id', $enrollment->institution_id)->where('status', 'active')->find($enrollment->student_id);
            if (! $student) {
                throw new ModelNotFoundException;
            }
            $source = CourseSection::query()->with('offering.policy')->lockForUpdate()->findOrFail($enrollment->section_id);
            $destination = CourseSection::query()->with('offering.policy')->lockForUpdate()->findOrFail($destinationSectionId);
            if ($enrollment->status !== 'confirmed' || ! $source->offering->policy->allow_section_transfer || $source->offering_id !== $destination->offering_id || $source->getKey() === $destination->getKey() || $destination->status !== 'open') {
                throw ValidationException::withMessages(['section_id' => 'This section transfer is not allowed.']);
            }
            $pendingTransfer = DB::table('course_section_transfers')
                ->where('tenant_id', $this->context->tenant()->getKey())
                ->where('enrollment_id', $enrollment->getKey())
                ->where('status', 'pending')
                ->lockForUpdate()
                ->exists();
            if ($pendingTransfer) {
                throw ValidationException::withMessages(['section_id' => 'A section transfer request is already pending.']);
            }
            $this->assertStudentEligibility($student, $destination, $destination->offering, $destination->offering->policy);
            if ($this->occupiedSeats($destination) >= (int) $destination->capacity) {
                throw ValidationException::withMessages(['section_id' => 'The destination section is full.']);
            }
            $transfer = DB::table('course_section_transfers')->insertGetId([
                'tenant_id' => $this->context->tenant()->getKey(), 'enrollment_id' => $enrollment->getKey(),
                'source_section_id' => $source->getKey(), 'destination_section_id' => $destination->getKey(),
                'status' => 'pending', 'reason' => $reason, 'requested_by' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->recordEvent($enrollment, 'section_transfer_requested', 'confirmed', 'confirmed', $actor, $reason, ['transfer_id' => $transfer, 'destination_section_id' => $destination->getKey()]);
            $this->audit->record('enrollment.section_transfer.requested', $actor, (int) $this->context->tenant()->getKey(), $enrollment, ['transfer_id' => $transfer]);

            return (int) $transfer;
        }, 3);
    }

    public function addPrerequisiteRule(int $courseId, int $prerequisiteCourseId, string $relationType, bool $required, User $actor): void
    {
        $this->authorize('enrollment.registrar.manage');
        if (! in_array($relationType, ['prerequisite', 'corequisite'], true) || $courseId === $prerequisiteCourseId) {
            throw ValidationException::withMessages(['prerequisite_course_id' => 'Choose a distinct course and a supported prerequisite relation.']);
        }
        $institution = $this->institution();
        $course = AcademicCourse::query()->where('institution_id', $institution->getKey())->findOrFail($courseId);
        $prerequisite = AcademicCourse::query()->where('institution_id', $institution->getKey())->findOrFail($prerequisiteCourseId);
        DB::table('course_prerequisite_rules')->insertOrIgnore([
            'tenant_id' => $this->context->tenant()->getKey(), 'institution_id' => $institution->getKey(),
            'course_id' => $course->getKey(), 'prerequisite_course_id' => $prerequisite->getKey(),
            'relation_type' => $relationType, 'is_required' => $required, 'created_by' => $actor->getKey(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('enrollment.prerequisite.rule_saved', $actor, (int) $this->context->tenant()->getKey(), $course, ['prerequisite_course_id' => $prerequisite->getKey(), 'relation_type' => $relationType, 'required' => $required]);
    }

    /** @return array{batch_id:int,rows:int,valid:int,errors:int} */
    public function previewBulkCsv(string $contents, string $idempotencyKey, User $actor): array
    {
        $this->authorize('enrollment.registrar.manage');
        if (strlen($contents) > 2_000_000) {
            throw ValidationException::withMessages(['file' => 'CSV files may not exceed 2 MB.']);
        }
        $institution = $this->institution();
        $digest = hash('sha256', $contents);
        $existing = DB::table('course_enrollment_batches')->where('tenant_id', $this->context->tenant()->getKey())->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if (! hash_equals((string) $existing->source_sha256, $digest)) {
                throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for a different CSV.']);
            }

            return ['batch_id' => (int) $existing->id, 'rows' => (int) $existing->row_count, 'valid' => (int) $existing->valid_count, 'errors' => (int) $existing->error_count];
        }
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);
        $header = fgetcsv($stream);
        if ($header !== ['student_number', 'offering_code', 'section_code']) {
            fclose($stream);
            throw ValidationException::withMessages(['file' => 'Use the required CSV columns: student_number, offering_code, section_code.']);
        }
        $rawRows = [];
        while (($row = fgetcsv($stream)) !== false) {
            if (count($rawRows) >= 500) {
                fclose($stream);
                throw ValidationException::withMessages(['file' => 'CSV files are limited to 500 data rows.']);
            }
            if (count($row) === 1 && $row[0] === null) {
                continue;
            }
            $rawRows[] = array_map(static fn ($value): string => trim((string) $value), $row);
        }
        fclose($stream);
        $seen = [];
        $sectionReservations = [];
        $prepared = [];
        foreach ($rawRows as $index => $row) {
            $paddedRow = array_pad($row, 3, '');
            $number = (string) $paddedRow[0];
            $offeringCode = (string) $paddedRow[1];
            $sectionCode = (string) $paddedRow[2];
            $error = null;
            if (count($row) !== 3 || $number === '' || $offeringCode === '' || $sectionCode === '' || max(strlen($number), strlen($offeringCode), strlen($sectionCode)) > 64) {
                $error = 'invalid_row';
            } elseif (isset($seen[$number.'|'.$offeringCode])) {
                $error = 'duplicate_csv_row';
            }
            $seen[$number.'|'.$offeringCode] = true;
            $student = $error === null ? SisStudent::query()->where('institution_id', $institution->getKey())->where('student_number', $number)->where('status', 'active')->first() : null;
            $section = $error === null ? CourseSection::query()->with(['offering.policy', 'offering.period', 'offering.courseVersion'])->where('section_code', $sectionCode)->whereHas('offering', fn ($query) => $query->where('institution_id', $institution->getKey())->where('offering_code', $offeringCode))->first() : null;
            if ($error === null && (! $student || ! $section || $section->status !== 'open' || $section->offering->status !== 'open')) {
                $error = 'invalid_reference_or_closed';
            }
            $key = $section?->getKey();
            if ($error === null && CourseEnrollment::query()->where('student_id', $student->getKey())->where('offering_id', $section->offering_id)->whereIn('status', ['pending', 'confirmed', 'waitlisted', 'offer_pending'])->exists()) {
                $error = 'already_enrolled';
            }
            if ($error === null) {
                $reserved = $sectionReservations[$key] ?? 0;
                if ($this->occupiedSeats($section) + $reserved >= (int) $section->capacity && ! $section->offering->policy->waitlist_enabled) {
                    $error = 'capacity_exceeded';
                } else {
                    $sectionReservations[$key] = $reserved + 1;
                }
            }
            $prepared[] = ['row_number' => $index + 2, 'student_number' => mb_substr($number, 0, 64), 'offering_code' => mb_substr($offeringCode, 0, 48), 'section_code' => mb_substr($sectionCode, 0, 32), 'status' => $error === null ? 'valid' : 'error', 'error_code' => $error];
        }
        $valid = count(array_filter($prepared, static fn (array $row): bool => $row['status'] === 'valid'));
        $batchId = DB::transaction(function () use ($institution, $actor, $idempotencyKey, $digest, $prepared, $valid): int {
            $batchId = DB::table('course_enrollment_batches')->insertGetId([
                'tenant_id' => $this->context->tenant()->getKey(), 'institution_id' => $institution->getKey(), 'created_by' => $actor->getKey(),
                'idempotency_key' => $idempotencyKey, 'source_sha256' => $digest, 'status' => 'previewed', 'row_count' => count($prepared),
                'valid_count' => $valid, 'error_count' => count($prepared) - $valid,
                'safe_summary' => json_encode(['valid' => $valid, 'errors' => count($prepared) - $valid], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($prepared as $row) {
                DB::table('course_enrollment_batch_rows')->insert($row + ['tenant_id' => $this->context->tenant()->getKey(), 'batch_id' => $batchId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('enrollment.bulk.previewed', $actor, (int) $this->context->tenant()->getKey(), null, ['batch_id' => $batchId, 'rows' => count($prepared), 'valid' => $valid, 'errors' => count($prepared) - $valid]);

            return (int) $batchId;
        });

        return ['batch_id' => $batchId, 'rows' => count($prepared), 'valid' => $valid, 'errors' => count($prepared) - $valid];
    }

    /** @return array{imported:int,errors:int} */
    public function confirmBulkBatch(int $batchId, User $actor): array
    {
        $this->authorize('enrollment.registrar.manage');

        return DB::transaction(function () use ($batchId, $actor): array {
            $batch = DB::table('course_enrollment_batches')->where('tenant_id', $this->context->tenant()->getKey())->where('institution_id', $this->institution()->getKey())->lockForUpdate()->find($batchId);
            if (! $batch) {
                throw new ModelNotFoundException;
            }
            if (in_array(data_get($batch, 'status'), ['confirmed', 'partial'], true)) {
                return [
                    'imported' => (int) DB::table('course_enrollment_batch_rows')->where('batch_id', $batchId)->where('status', 'imported')->count(),
                    'errors' => (int) DB::table('course_enrollment_batch_rows')->where('batch_id', $batchId)->where('status', 'error')->count(),
                ];
            }
            if (data_get($batch, 'status') !== 'previewed' || (int) data_get($batch, 'error_count') > 0) {
                throw ValidationException::withMessages(['batch' => 'Fix all preview errors before confirming the batch.']);
            }
            $rows = DB::table('course_enrollment_batch_rows')->where('tenant_id', $this->context->tenant()->getKey())->where('batch_id', $batchId)->where('status', 'valid')->orderBy('row_number')->lockForUpdate()->get();
            $imported = 0;
            $batchInstitutionId = (int) data_get($batch, 'institution_id');
            foreach ($rows as $row) {
                $student = SisStudent::query()->where('institution_id', $batchInstitutionId)->where('student_number', $row->student_number)->where('status', 'active')->lockForUpdate()->first();
                $section = CourseSection::query()->with(['offering.policy', 'offering.period', 'offering.courseVersion'])->where('section_code', $row->section_code)->whereHas('offering', fn ($query) => $query->where('institution_id', $batchInstitutionId)->where('offering_code', $row->offering_code))->lockForUpdate()->first();
                if (! $student || ! $section || $section->status !== 'open' || $section->offering->status !== 'open') {
                    DB::table('course_enrollment_batch_rows')->where('id', $row->id)->update(['status' => 'error', 'error_code' => 'state_changed_after_preview', 'updated_at' => now()]);

                    continue;
                }
                try {
                    $this->assertStudentEligibility($student, $section, $section->offering, $section->offering->policy);
                } catch (ValidationException|AuthorizationException) {
                    DB::table('course_enrollment_batch_rows')->where('id', $row->id)->update(['status' => 'error', 'error_code' => 'eligibility_changed', 'updated_at' => now()]);

                    continue;
                }
                $occupied = $this->occupiedSeats($section);
                if (CourseEnrollment::query()->where('student_id', $student->getKey())->where('offering_id', $section->offering_id)->whereIn('status', ['pending', 'confirmed', 'waitlisted', 'offer_pending'])->exists()) {
                    DB::table('course_enrollment_batch_rows')->where('id', $row->id)->update(['status' => 'error', 'error_code' => 'already_enrolled', 'updated_at' => now()]);

                    continue;
                }
                if ($occupied >= (int) $section->capacity && ! $section->offering->policy->waitlist_enabled) {
                    DB::table('course_enrollment_batch_rows')->where('id', $row->id)->update(['status' => 'error', 'error_code' => 'capacity_changed', 'updated_at' => now()]);

                    continue;
                }
                $status = $section->offering->policy->requires_approval ? 'pending' : ($occupied < (int) $section->capacity ? 'confirmed' : 'waitlisted');
                $enrollment = CourseEnrollment::query()->create([
                    'institution_id' => $batchInstitutionId, 'student_id' => $student->getKey(), 'offering_id' => $section->offering_id,
                    'section_id' => $section->getKey(), 'status' => $status, 'registration_source' => 'bulk',
                    'idempotency_key' => 'bulk-'.$batchId.'-'.$row->row_number, 'requested_at' => now(), 'confirmed_at' => $status === 'confirmed' ? now() : null,
                    'credits_snapshot' => $section->offering->courseVersion?->total_credits,
                ]);
                if ($status === 'waitlisted') {
                    $position = (int) CourseWaitlistEntry::query()->where('section_id', $section->getKey())->max('position') + 1;
                    CourseWaitlistEntry::query()->create(['student_id' => $student->getKey(), 'offering_id' => $section->offering_id, 'section_id' => $section->getKey(), 'position' => $position, 'status' => 'waiting']);
                }
                $this->recordEvent($enrollment, 'bulk_registration', null, $status, $actor, null, ['batch_id' => $batchId]);
                if ($status === 'confirmed') {
                    $this->syncLearningAccess($enrollment, $actor, true);
                }
                DB::table('course_enrollment_batch_rows')->where('id', $row->id)->update(['status' => 'imported', 'enrollment_id' => $enrollment->getKey(), 'updated_at' => now()]);
                $imported++;
            }
            $errors = (int) DB::table('course_enrollment_batch_rows')->where('tenant_id', $this->context->tenant()->getKey())->where('batch_id', $batchId)->where('status', 'error')->count();
            DB::table('course_enrollment_batches')->where('id', $batchId)->update(['status' => $errors > 0 ? 'partial' : 'confirmed', 'confirmed_at' => now(), 'error_count' => $errors, 'updated_at' => now()]);
            $this->audit->record('enrollment.bulk.confirmed', $actor, (int) $this->context->tenant()->getKey(), null, ['batch_id' => $batchId, 'imported' => $imported, 'errors' => $errors]);

            return ['imported' => $imported, 'errors' => $errors];
        }, 3);
    }

    public function requestPrerequisiteException(int $courseId, int $prerequisiteCourseId, string $type, string $reason, ?string $evidenceReference, User $actor): int
    {
        $this->authorize('enrollment.student.register');
        if (! in_array($type, ['waiver', 'equivalency'], true)) {
            throw ValidationException::withMessages(['exception_type' => 'Choose waiver or equivalency.']);
        }

        return DB::transaction(function () use ($courseId, $prerequisiteCourseId, $type, $reason, $evidenceReference, $actor): int {
            $institution = $this->institution();
            $student = SisStudent::query()->where('user_id', $actor->getKey())->where('institution_id', $institution->getKey())->where('status', 'active')->firstOrFail();
            AcademicCourse::query()->where('institution_id', $institution->getKey())->findOrFail($courseId);
            AcademicCourse::query()->where('institution_id', $institution->getKey())->findOrFail($prerequisiteCourseId);
            $id = DB::table('course_prerequisite_exceptions')->insertGetId([
                'tenant_id' => $this->context->tenant()->getKey(), 'institution_id' => $institution->getKey(),
                'student_id' => $student->getKey(), 'course_id' => $courseId, 'prerequisite_course_id' => $prerequisiteCourseId,
                'exception_type' => $type, 'status' => 'pending', 'reason' => $reason, 'evidence_reference' => $evidenceReference,
                'requested_by' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('enrollment.prerequisite.exception_requested', $actor, (int) $this->context->tenant()->getKey(), $student, ['exception_id' => $id, 'type' => $type]);

            return (int) $id;
        });
    }

    public function decidePrerequisiteException(int $exceptionId, string $decision, string $reason, ?Carbon $expiresAt, User $actor): void
    {
        $this->authorize('enrollment.registrar.manage');
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose approve or reject.']);
        }
        DB::transaction(function () use ($exceptionId, $decision, $reason, $expiresAt, $actor): void {
            $exception = DB::table('course_prerequisite_exceptions')->where('tenant_id', $this->context->tenant()->getKey())->lockForUpdate()->find($exceptionId);
            if (! $exception || data_get($exception, 'status') !== 'pending') {
                throw new ModelNotFoundException;
            }
            if ((int) data_get($exception, 'requested_by') === (int) $actor->getKey()) {
                throw new AuthorizationException('A requester cannot approve their own prerequisite exception.');
            }
            if ($expiresAt && $expiresAt->isPast()) {
                throw ValidationException::withMessages(['expires_at' => 'Expiry must be in the future.']);
            }
            if ($decision === 'approve' && trim((string) data_get($exception, 'evidence_reference')) === '') {
                throw ValidationException::withMessages(['evidence_reference' => 'An authoritative record reference is required before approval.']);
            }
            DB::table('course_prerequisite_exceptions')->where('id', $exceptionId)->update([
                'status' => $decision === 'approve' ? 'approved' : 'rejected', 'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(), 'expires_at' => $decision === 'approve' ? $expiresAt : null,
                'reason' => mb_substr((string) data_get($exception, 'reason').' | Review: '.$reason, 0, 4000), 'updated_at' => now(),
            ]);
            $this->audit->record('enrollment.prerequisite.exception_'.$decision.'d', $actor, (int) $this->context->tenant()->getKey(), null, ['exception_id' => $exceptionId, 'student_id' => (int) data_get($exception, 'student_id')]);
        });
    }

    public function decideSectionTransfer(int $transferId, string $decision, string $reason, User $actor): CourseEnrollment
    {
        $this->authorize('enrollment.registrar.manage');
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose approve or reject.']);
        }

        return DB::transaction(function () use ($transferId, $decision, $reason, $actor): CourseEnrollment {
            $transfer = DB::table('course_section_transfers')->where('tenant_id', $this->context->tenant()->getKey())->lockForUpdate()->find($transferId);
            if (! $transfer || data_get($transfer, 'status') !== 'pending') {
                throw new ModelNotFoundException;
            }
            $enrollment = CourseEnrollment::query()->lockForUpdate()->findOrFail((int) data_get($transfer, 'enrollment_id'));
            $source = CourseSection::query()->lockForUpdate()->findOrFail((int) data_get($transfer, 'source_section_id'));
            $destination = CourseSection::query()->with('offering')->lockForUpdate()->findOrFail((int) data_get($transfer, 'destination_section_id'));
            if ($decision === 'approve') {
                if ($enrollment->status !== 'confirmed' || (int) $enrollment->section_id !== (int) $source->getKey() || $this->occupiedSeats($destination) >= (int) $destination->capacity) {
                    throw ValidationException::withMessages(['transfer' => 'Enrollment or capacity changed; transfer cannot be approved.']);
                }
                $enrollment->update(['section_id' => $destination->getKey()]);
                $this->promoteNext($source);
            }
            DB::table('course_section_transfers')->where('id', $transferId)->update([
                'status' => $decision === 'approve' ? 'approved' : 'rejected', 'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(), 'completed_at' => $decision === 'approve' ? now() : null, 'updated_at' => now(),
            ]);
            $this->recordEvent($enrollment, 'section_transfer_'.$decision, 'confirmed', 'confirmed', $actor, $reason, ['transfer_id' => $transferId, 'section_id' => $enrollment->section_id]);
            $this->audit->record('enrollment.section_transfer.'.$decision.'d', $actor, (int) $this->context->tenant()->getKey(), $enrollment, ['transfer_id' => $transferId]);

            return $enrollment->refresh();
        }, 3);
    }

    public function acceptWaitlistOffer(int $entryId, User $actor): CourseEnrollment
    {
        $this->authorize('enrollment.student.register');
        $expired = false;
        $result = DB::transaction(function () use ($entryId, $actor, &$expired): CourseEnrollment {
            $entry = CourseWaitlistEntry::query()->lockForUpdate()->findOrFail($entryId);
            $student = SisStudent::query()->where('user_id', $actor->getKey())->where('status', 'active')->find($entry->student_id);
            if (! $student) {
                throw new ModelNotFoundException;
            }
            if ($entry->status !== 'offered' || ! $entry->offer_expires_at) {
                throw ValidationException::withMessages(['offer' => 'This seat offer is no longer available.']);
            }
            $enrollment = CourseEnrollment::query()->where('student_id', $student->getKey())->where('offering_id', $entry->offering_id)->lockForUpdate()->firstOrFail();
            if ($enrollment->status !== 'offer_pending') {
                throw ValidationException::withMessages(['offer' => 'This seat offer is no longer available.']);
            }
            $section = CourseSection::query()->with(['offering.policy', 'offering.period', 'offering.courseVersion'])->lockForUpdate()->findOrFail($entry->section_id);
            $this->assertStudentEligibility($student, $section, $section->offering, $section->offering->policy);
            if (Carbon::parse($entry->offer_expires_at)->isPast()) {
                $entry->update(['status' => 'expired']);
                $enrollment->update(['status' => 'rejected', 'review_reason' => 'Waitlist offer expired.']);
                $this->waitlistOutbox->record($entry, $enrollment, 'offer_expired');
                $this->recordEvent($enrollment, 'waitlist_offer_expired', 'offer_pending', 'rejected', null, null, ['section_id' => (int) $entry->section_id]);
                $this->promoteNext($section);
                $expired = true;

                return $enrollment->refresh();
            }
            $entry->update(['status' => 'accepted', 'accepted_at' => now()]);
            $this->waitlistOutbox->record($entry, $enrollment, 'offer_accepted');
            $from = $enrollment->status;
            $enrollment->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            $this->recordEvent($enrollment, 'waitlist_accepted', $from, 'confirmed', $actor, null, ['section_id' => (int) $entry->section_id]);
            $this->syncLearningAccess($enrollment, $actor, true);
            $this->audit->record('enrollment.waitlist.offer_accepted', $actor, (int) $this->context->tenant()->getKey(), $enrollment, ['section_id' => $entry->section_id]);

            return $enrollment->refresh();
        }, 3);
        if ($expired) {
            throw ValidationException::withMessages(['offer' => 'The offer expired; the seat was released and the next eligible waitlist member was processed.']);
        }

        return $result;
    }

    /** Expires outstanding offers and releases their reserved seats for the next eligible learner. */
    public function expireWaitlistOffers(int $limit = 250): int
    {
        $tenantId = (int) $this->context->tenant()->getKey();
        $entryIds = CourseWaitlistEntry::query()
            ->where('status', 'offered')
            ->whereNotNull('offer_expires_at')
            ->where('offer_expires_at', '<=', now())
            ->orderBy('offer_expires_at')
            ->limit(max(1, min($limit, 1000)))
            ->pluck('id');
        $expired = 0;

        foreach ($entryIds as $entryId) {
            $didExpire = DB::transaction(function () use ($entryId, $tenantId): bool {
                $entry = CourseWaitlistEntry::query()->lockForUpdate()->find($entryId);
                if (! $entry || $entry->status !== 'offered' || ! $entry->offer_expires_at || Carbon::parse($entry->offer_expires_at)->isFuture()) {
                    return false;
                }

                $enrollment = CourseEnrollment::query()
                    ->where('student_id', $entry->student_id)
                    ->where('offering_id', $entry->offering_id)
                    ->lockForUpdate()
                    ->first();
                if (! $enrollment || $enrollment->status !== 'offer_pending') {
                    return false;
                }

                $section = CourseSection::query()->with('offering.policy')->lockForUpdate()->find($entry->section_id);
                if (! $section) {
                    return false;
                }

                $entry->update(['status' => 'expired']);
                $enrollment->update(['status' => 'rejected', 'review_reason' => 'Waitlist offer expired.']);
                $this->waitlistOutbox->record($entry, $enrollment, 'offer_expired');
                $this->recordEvent($enrollment, 'waitlist_offer_expired', 'offer_pending', 'rejected', null, null, ['section_id' => (int) $entry->section_id]);
                $this->audit->record('enrollment.waitlist.offer_expired', null, $tenantId, $enrollment, ['section_id' => (int) $section->getKey()]);
                $this->promoteNext($section);

                return true;
            }, 3);

            $expired += (int) $didExpire;
        }

        return $expired;
    }

    public function assignInstructor(CourseSection $section, int $userId, User $actor): void
    {
        $this->authorize('enrollment.registrar.manage');
        $membership = TenantMembership::query()->where('user_id', $userId)->where('status', 'active')->first();
        if (! $membership || $membership->institution_id !== $section->offering->institution_id || ! $membership->hasPermission('enrollment.instructor.roster.view')) {
            throw ValidationException::withMessages(['user_id' => 'Choose an active instructor membership in this institution.']);
        }
        if ($membership->campus_id && $section->campus_id && $membership->campus_id !== $section->campus_id) {
            throw ValidationException::withMessages(['user_id' => 'The instructor is assigned to a different campus.']);
        }
        DB::table('course_section_instructors')->insertOrIgnore([
            'tenant_id' => $this->context->tenant()->getKey(), 'section_id' => $section->getKey(), 'user_id' => $userId,
            'role' => 'instructor', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('enrollment.instructor.assigned', $actor, (int) $this->context->tenant()->getKey(), $section, ['section_id' => $section->getKey(), 'instructor_user_id' => $userId]);
    }

    public function placeHold(int $studentId, string $type, string $reasonCode, string $reason, Carbon $effectiveAt, ?Carbon $expiresAt, User $actor): CourseRegistrationHold
    {
        $this->authorize('enrollment.registrar.manage');
        $student = SisStudent::query()->where('institution_id', $this->institution()->getKey())->findOrFail($studentId);
        if ($expiresAt && $expiresAt->lessThanOrEqualTo($effectiveAt)) {
            throw ValidationException::withMessages(['expires_at' => 'The expiry must be later than the effective time.']);
        }
        $hold = CourseRegistrationHold::query()->create([
            'student_id' => $student->getKey(), 'hold_type' => $type, 'reason_code' => $reasonCode,
            'reason' => $reason, 'effective_at' => $effectiveAt, 'expires_at' => $expiresAt, 'created_by' => $actor->getKey(),
        ]);
        $this->audit->record('enrollment.hold.placed', $actor, (int) $this->context->tenant()->getKey(), $hold, ['student_id' => $student->getKey(), 'hold_type' => $type, 'reason_code' => $reasonCode]);

        return $hold;
    }

    public function revokeHold(int $holdId, string $reason, User $actor): CourseRegistrationHold
    {
        $this->authorize('enrollment.registrar.manage');
        $hold = CourseRegistrationHold::query()->lockForUpdate()->findOrFail($holdId);
        if ($hold->revoked_at) {
            throw ValidationException::withMessages(['hold' => 'This hold has already been revoked.']);
        }
        $hold->update(['revoked_at' => now(), 'revoked_by' => $actor->getKey(), 'reason' => mb_substr($hold->reason.' | Revocation: '.$reason, 0, 1000)]);
        $this->audit->record('enrollment.hold.revoked', $actor, (int) $this->context->tenant()->getKey(), $hold, ['student_id' => $hold->student_id, 'reason_code' => $hold->reason_code]);

        return $hold->refresh();
    }

    /** @return array{tenantId:int,institutionId:int} */
    public function scope(): array
    {
        return ['tenantId' => (int) $this->context->tenant()->getKey(), 'institutionId' => (int) $this->institution()->getKey()];
    }

    private function assertStudentEligibility(SisStudent $student, CourseSection $section, CourseOffering $offering, EnrollmentPolicy $policy): void
    {
        $today = now($this->institution()->timezone)->toDateString();
        $period = $offering->period;
        $year = AcademicYear::query()->find($offering->academic_year_id);
        if ($offering->status !== 'open' || ! $period || $period->status !== 'active' || ! $year || $year->status !== 'active' || Carbon::parse($period->starts_on)->gt($today) || Carbon::parse($period->ends_on)->lt($today)) {
            throw ValidationException::withMessages(['section_id' => 'Registration is not open for this academic period.']);
        }
        $now = now('UTC');
        $outsideStandardWindow = ($offering->registration_opens_at && $now->lt($offering->registration_opens_at)) || ($offering->registration_closes_at && $now->gt($offering->registration_closes_at));
        $withinLateWindow = $policy->allow_late_registration && $offering->late_registration_closes_at && $now->lte($offering->late_registration_closes_at);
        if ($outsideStandardWindow && ! $withinLateWindow) {
            throw ValidationException::withMessages(['section_id' => 'The registration window is closed.']);
        }
        if ($policy->status !== 'active' || $section->offering_id !== $offering->getKey()) {
            throw new ModelNotFoundException;
        }
        if ($policy->require_prerequisites) {
            $requiredRules = DB::table('course_prerequisite_rules')->where('tenant_id', $this->context->tenant()->getKey())
                ->where('institution_id', $offering->institution_id)->where('course_id', $offering->academic_course_id)
                ->where('is_required', true)->get(['prerequisite_course_id', 'relation_type']);
            foreach ($requiredRules as $rule) {
                $prerequisiteId = (int) $rule->prerequisite_course_id;
                if ($rule->relation_type === 'corequisite') {
                    $concurrent = CourseOffering::query()->where('academic_period_id', $offering->academic_period_id)->where('academic_course_id', $prerequisiteId)
                        ->whereHas('enrollments', fn ($query) => $query->where('student_id', $student->getKey())->whereIn('status', ['confirmed', 'pending', 'offer_pending']))->exists();
                    if (! $concurrent) {
                        throw ValidationException::withMessages(['section_id' => 'Register for the required co-requisite in this academic period.']);
                    }

                    continue;
                }
                $verified = DB::table('course_prerequisite_exceptions')->where('tenant_id', $this->context->tenant()->getKey())
                    ->where('student_id', $student->getKey())->where('course_id', $offering->academic_course_id)
                    ->where('prerequisite_course_id', $prerequisiteId)->where('status', 'approved')->whereNull('revoked_at')
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
                if (! $verified) {
                    throw ValidationException::withMessages(['section_id' => 'A registrar-verified prerequisite record or approved exception is required.']);
                }
            }
        }
        $membership = $this->context->membership();
        $placement = SisStudentPlacement::query()->where('student_id', $student->getKey())->where('status', 'active')
            ->whereDate('effective_from', '<=', $today)->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today))
            ->orderByDesc('effective_from')->first();
        $campusId = $placement ? $placement->campus_id : $student->home_campus_id;
        if ($offering->academic_program_id && (! $placement || (int) $placement->academic_program_id !== (int) $offering->academic_program_id)) {
            throw ValidationException::withMessages(['section_id' => 'Your current academic program is not eligible for this offering.']);
        }
        $requiredCampus = $section->campus_id ?? $offering->campus_id;
        if ($membership->campus_id && (int) $membership->campus_id !== (int) $requiredCampus) {
            throw new AuthorizationException('This registration is outside your assigned campus.');
        }
        if ($requiredCampus && $campusId && (int) $requiredCampus !== (int) $campusId) {
            throw new AuthorizationException('This section is not available to your campus placement.');
        }
        $allowedLevels = DB::table('enrollment_policy_academic_levels')->where('tenant_id', $this->context->tenant()->getKey())->where('policy_id', $policy->getKey())->pluck('academic_level_id');
        if ($allowedLevels->isNotEmpty() && (! $placement?->academic_level_id || ! $allowedLevels->contains((int) $placement->academic_level_id))) {
            throw ValidationException::withMessages(['section_id' => 'Your current academic level is not eligible for this offering.']);
        }
        if (CourseRegistrationHold::query()->where('student_id', $student->getKey())->whereNull('revoked_at')->where('effective_at', '<=', now())->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists()) {
            throw ValidationException::withMessages(['section_id' => 'A registration hold must be resolved before adding courses.']);
        }
        $activeCount = CourseEnrollment::query()->where('student_id', $student->getKey())->whereIn('status', ['confirmed', 'pending', 'offer_pending'])
            ->whereIn('offering_id', CourseOffering::query()->select('id')->where('academic_period_id', $offering->academic_period_id))->count();
        if ($activeCount >= $policy->maximum_courses) {
            throw ValidationException::withMessages(['section_id' => 'You have reached the maximum course load for this period.']);
        }
        if ($policy->maximum_credits !== null) {
            $targetCredits = $offering->courseVersion?->total_credits;
            if ($targetCredits === null) {
                throw ValidationException::withMessages(['section_id' => 'A published credit-bearing course version is required by this credit-limit policy.']);
            }
            $currentCredits = (float) DB::table('course_enrollments as enrollments')
                ->join('course_offerings as offerings', function ($join): void {
                    $join->on('offerings.id', '=', 'enrollments.offering_id')->on('offerings.tenant_id', '=', 'enrollments.tenant_id');
                })
                ->leftJoin('academic_course_versions as versions', function ($join): void {
                    $join->on('versions.id', '=', 'offerings.academic_course_version_id')->on('versions.tenant_id', '=', 'offerings.tenant_id');
                })
                ->where('enrollments.tenant_id', $this->context->tenant()->getKey())->where('enrollments.student_id', $student->getKey())
                ->where('offerings.academic_period_id', $offering->academic_period_id)->whereIn('enrollments.status', ['confirmed', 'pending', 'offer_pending'])
                ->sum('versions.total_credits');
            if ($currentCredits + (float) $targetCredits > (float) $policy->maximum_credits) {
                throw ValidationException::withMessages(['section_id' => 'This course would exceed the maximum credit load for the period.']);
            }
        }
    }

    private function occupiedSeats(CourseSection $section): int
    {
        return (int) CourseEnrollment::query()->where('section_id', $section->getKey())->whereIn('status', ['confirmed', 'offer_pending'])->count()
            + (int) $section->reserved_seats;
    }

    private function promoteNext(CourseSection $section): void
    {
        if ($this->occupiedSeats($section) >= (int) $section->capacity) {
            return;
        }
        $entry = CourseWaitlistEntry::query()->where('section_id', $section->getKey())->where('status', 'waiting')->orderBy('position')->lockForUpdate()->first();
        if (! $entry) {
            return;
        }
        $expires = now()->addHours(max(1, (int) $section->offering->policy->waitlist_offer_hours));
        $entry->update(['status' => 'offered', 'offered_at' => now(), 'offer_expires_at' => $expires]);
        $enrollment = CourseEnrollment::query()->where('student_id', $entry->student_id)->where('offering_id', $entry->offering_id)->lockForUpdate()->first();
        if ($enrollment) {
            $previous = $enrollment->status;
            $enrollment->update(['status' => 'offer_pending']);
            $this->waitlistOutbox->record($entry, $enrollment, 'offer_created');
            $this->recordEvent($enrollment, 'waitlist_offer_issued', $previous, 'offer_pending', null, null, ['section_id' => (int) $section->getKey()]);
            $this->audit->record('enrollment.waitlist.offer_issued', null, (int) $this->context->tenant()->getKey(), $enrollment, ['section_id' => $section->getKey(), 'expires_at' => $expires->toIso8601String()]);
        }
    }

    /** @param array<string, int|string|bool|null> $metadata */
    private function recordEvent(CourseEnrollment $enrollment, string $type, ?string $from, ?string $to, ?User $actor, ?string $reason = null, array $metadata = []): void
    {
        DB::table('course_enrollment_events')->insert([
            'tenant_id' => $this->context->tenant()->getKey(), 'enrollment_id' => $enrollment->getKey(),
            'event_type' => $type, 'from_status' => $from, 'to_status' => $to,
            'actor_id' => $actor?->getKey(), 'reason' => $reason,
            'safe_metadata' => $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
            'occurred_at' => now('UTC'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Synchronizes only the Phase 07 learner assignment; it never grants a missing permission. */
    private function syncLearningAccess(CourseEnrollment $enrollment, User $actor, bool $grant): void
    {
        $offering = CourseOffering::query()->find($enrollment->offering_id);
        if (! $offering?->delivery_workspace_id) {
            return;
        }
        $membership = TenantMembership::query()->where('tenant_id', $this->context->tenant()->getKey())
            ->where('institution_id', $enrollment->institution_id)->where('user_id', $enrollment->student->user_id)
            ->where('status', 'active')->first();
        if (! $membership) {
            throw new AuthorizationException('An active institution membership is required before learning access can be synchronized.');
        }
        $assignment = CourseDeliveryAssignment::query()->where('tenant_id', $this->context->tenant()->getKey())
            ->where('workspace_id', $offering->delivery_workspace_id)->where('membership_id', $membership->getKey())
            ->where('assignment_role', 'learner')->where('status', 'active')->whereNull('revoked_at')->first();
        if ($grant) {
            if (! $membership->hasPermission('learning.delivery.learn')) {
                throw new AuthorizationException('The student membership lacks the existing learning access permission.');
            }
            if ($assignment) {
                return;
            }
            $sequence = (int) CourseDeliveryAssignment::query()->where('workspace_id', $offering->delivery_workspace_id)
                ->where('membership_id', $membership->getKey())->where('assignment_role', 'learner')->max('sequence') + 1;
            CourseDeliveryAssignment::query()->create([
                'workspace_id' => $offering->delivery_workspace_id, 'membership_id' => $membership->getKey(),
                'assignment_role' => 'learner', 'sequence' => $sequence, 'status' => 'active', 'assigned_by' => $actor->getKey(),
                'responsibility' => 'Confirmed course enrollment '.$enrollment->getKey(),
            ]);
            $this->audit->record('enrollment.learning_access.granted', $actor, (int) $this->context->tenant()->getKey(), $enrollment, ['workspace_id' => (int) $offering->delivery_workspace_id]);

            return;
        }
        if ($assignment) {
            $assignment->update(['status' => 'revoked', 'revoked_by' => $actor->getKey(), 'revoked_at' => now(), 'change_reason' => 'Enrollment is no longer confirmed.']);
            $this->audit->record('enrollment.learning_access.revoked', $actor, (int) $this->context->tenant()->getKey(), $enrollment, ['workspace_id' => (int) $offering->delivery_workspace_id]);
        }
    }

    private function authorize(string $permission): void
    {
        if (! $this->context->hasMembership() || ! $this->context->membership()->hasPermission($permission)) {
            throw new AuthorizationException;
        }
    }

    private function institution(): Institution
    {
        return Institution::query()->firstOrFail();
    }

    private function assertOwned(mixed $model): void
    {
        if (! $model) {
            throw new ModelNotFoundException;
        }
    }
}
