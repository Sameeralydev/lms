<?php

namespace App\Domain\Learning;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\CourseDeliveryLesson;
use App\Models\CourseDeliveryLiveSession;
use App\Models\CourseDeliveryWorkspace;
use App\Models\TenantMembership;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CourseDeliveryLiveSessionWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly CourseDeliveryWorkflow $delivery, private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function schedule(User $actor, CourseDeliveryWorkspace $workspace, array $data): CourseDeliveryLiveSession
    {
        $this->assertCanManageSessions($actor, $workspace);
        $instructor = TenantMembership::query()->where('institution_id', $workspace->institution_id)
            ->where('status', 'active')->whereKey($data['instructor_membership_id'])->firstOrFail();
        abort_unless($instructor->hasPermission('learning.delivery.teach') && $workspace->assignments()
            ->where('membership_id', $instructor->getKey())->effective()->whereIn('assignment_role', ['course_owner', 'coordinator', 'instructor'])->exists(), 422, 'Choose an active instructor assigned to this workspace.');
        $lesson = null;
        if (! empty($data['lesson_id'])) {
            $lesson = CourseDeliveryLesson::query()->where('workspace_id', $workspace->getKey())->findOrFail($data['lesson_id']);
        }
        [$start, $end] = $this->normalizeTimes((string) $data['timezone'], (string) $data['starts_at'], (string) $data['ends_at']);
        $session = DB::transaction(function () use ($actor, $workspace, $instructor, $lesson, $data, $start, $end): CourseDeliveryLiveSession {
            $session = CourseDeliveryLiveSession::query()->create([
                'tenant_id' => $workspace->tenant_id, 'workspace_id' => $workspace->getKey(), 'lesson_id' => $lesson?->getKey(),
                'instructor_membership_id' => $instructor->getKey(), 'title' => trim((string) $data['title']),
                'description' => $data['description'] ?? null, 'delivery_type' => $data['delivery_type'], 'timezone' => $data['timezone'],
                'starts_at' => $start, 'ends_at' => $end, 'meeting_url' => $data['meeting_url'] ?? null,
                'venue' => $data['venue'] ?? null, 'preparation_materials' => $data['preparation_materials'] ?? null,
                'post_session_resources' => $data['post_session_resources'] ?? null,
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(), 'status' => 'scheduled',
            ]);
            $this->audit->record('learning.delivery.session.scheduled', $actor, (int) $workspace->tenant_id, $session, [
                'workspace_id' => $workspace->getKey(), 'lesson_id' => $lesson?->getKey(), 'delivery_type' => $session->delivery_type,
                'instructor_membership_id' => $instructor->getKey(), 'starts_at_utc' => $start->format(DATE_ATOM),
            ]);

            return $session;
        });

        return $session;
    }

    /** @param array<string, mixed> $data */
    public function reschedule(User $actor, CourseDeliveryLiveSession $session, array $data): CourseDeliveryLiveSession
    {
        [$start, $end] = $this->normalizeTimes((string) $data['timezone'], (string) $data['starts_at'], (string) $data['ends_at']);

        return DB::transaction(function () use ($actor, $session, $data, $start, $end): CourseDeliveryLiveSession {
            $session = CourseDeliveryLiveSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $workspace = CourseDeliveryWorkspace::query()->findOrFail($session->workspace_id);
            $this->assertCanManageSessions($actor, $workspace);
            abort_unless($session->status === 'scheduled', 409, 'Only scheduled sessions can be changed.');
            $session->forceFill([
                'title' => trim((string) $data['title']), 'description' => $data['description'] ?? null,
                'delivery_type' => $data['delivery_type'], 'timezone' => $data['timezone'], 'starts_at' => $start, 'ends_at' => $end,
                'meeting_url' => $data['meeting_url'] ?? null, 'venue' => $data['venue'] ?? null,
                'preparation_materials' => $data['preparation_materials'] ?? null,
                'post_session_resources' => $data['post_session_resources'] ?? null, 'updated_by' => $actor->getKey(),
            ])->save();
            $this->audit->record('learning.delivery.session.rescheduled', $actor, (int) $workspace->tenant_id, $session, [
                'workspace_id' => $workspace->getKey(), 'starts_at_utc' => $start->format(DATE_ATOM),
            ]);

            return $session->refresh();
        });
    }

    public function cancel(User $actor, CourseDeliveryLiveSession $session, string $reason): CourseDeliveryLiveSession
    {
        return DB::transaction(function () use ($actor, $session, $reason): CourseDeliveryLiveSession {
            $session = CourseDeliveryLiveSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $workspace = CourseDeliveryWorkspace::query()->findOrFail($session->workspace_id);
            $this->assertCanManageSessions($actor, $workspace);
            abort_unless($session->status === 'scheduled', 409, 'Only scheduled sessions can be cancelled.');
            $session->forceFill(['status' => 'cancelled', 'cancellation_reason' => trim($reason), 'cancelled_at' => now(), 'updated_by' => $actor->getKey()])->save();
            $this->audit->record('learning.delivery.session.cancelled', $actor, (int) $workspace->tenant_id, $session, ['workspace_id' => $workspace->getKey()]);

            return $session->refresh();
        }, 3);
    }

    private function assertCanManageSessions(User $actor, CourseDeliveryWorkspace $workspace): void
    {
        $this->delivery->assertCanView($actor, $workspace);
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('learning.delivery.manage') || (
            $membership->hasPermission('learning.delivery.teach') && $workspace->assignments()
                ->where('membership_id', $membership->getKey())->effective()->whereIn('assignment_role', ['course_owner', 'coordinator', 'instructor'])->exists()
        ), 403);
        if ($membership->campus_id !== null) {
            abort_unless((int) $membership->campus_id === (int) $workspace->campus_id, 404);
        }
    }

    /** @return array{DateTimeImmutable,DateTimeImmutable} */
    private function normalizeTimes(string $timezone, string $startsAt, string $endsAt): array
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw ValidationException::withMessages(['timezone' => 'Choose a supported IANA time zone.']);
        }
        $zone = new DateTimeZone($timezone);
        $start = $this->resolveLocalInstant($startsAt, $zone, 'starts_at');
        $end = $this->resolveLocalInstant($endsAt, $zone, 'ends_at');
        if ($end <= $start) {
            throw ValidationException::withMessages(['ends_at' => 'The session end must be later than its start.']);
        }

        return [$start, $end];
    }

    private function resolveLocalInstant(string $local, DateTimeZone $zone, string $field): DateTimeImmutable
    {
        $naive = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($naive === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $naive->format('Y-m-d\TH:i') !== $local) {
            throw ValidationException::withMessages([$field => 'Enter a valid local date and time.']);
        }
        $wallTime = $naive->getTimestamp();
        $transitions = $zone->getTransitions($wallTime - 86400, $wallTime + 86400) ?: [];
        $offsets = array_values(array_unique(array_map(static fn (array $transition): int => (int) $transition['offset'], $transitions)));
        if ($offsets === []) {
            $offsets = [$zone->getOffset($naive)];
        }
        $candidates = [];
        foreach ($offsets as $offset) {
            $candidate = (new DateTimeImmutable('@'.($wallTime - $offset)))->setTimezone($zone);
            if ($candidate->format('Y-m-d\TH:i') === $local) {
                $candidates[] = $candidate->setTimezone(new DateTimeZone('UTC'));
            }
        }
        if (count($candidates) !== 1) {
            throw ValidationException::withMessages([$field => count($candidates) === 0
                ? 'This local time does not exist because of a daylight-saving clock change.'
                : 'This local time occurs twice because of a daylight-saving clock change; choose an unambiguous time.']);
        }

        return $candidates[0];
    }
}
