<?php

namespace App\Domain\Assignments;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AssignmentOutboxProcessor
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array{claimed:int, delivered:int, retried:int, failed:int} */
    public function dispatchAvailable(int $limit = 100): array
    {
        $tenantId = (int) $this->context->tenant()->getKey();
        $eventIds = DB::transaction(fn (): array => DB::table('assignment_outbox_events')->where('tenant_id', $tenantId)
            ->where('status', 'queued')->where('available_at', '<=', now())->orderBy('id')->limit(max(1, min($limit, 500)))
            ->lockForUpdate()->pluck('id')->all(), 3);
        $result = ['claimed' => count($eventIds), 'delivered' => 0, 'retried' => 0, 'failed' => 0];

        foreach ($eventIds as $eventId) {
            try {
                $delivered = DB::transaction(function () use ($tenantId, $eventId): bool {
                    $event = DB::table('assignment_outbox_events')->where('tenant_id', $tenantId)->where('id', $eventId)->lockForUpdate()->first();
                    if (! $event || $event->status !== 'queued' || $event->available_at > now()->toDateTimeString()) {
                        return false;
                    }
                    $payload = json_decode((string) $event->safe_payload, true, 32, JSON_THROW_ON_ERROR);
                    if (! is_array($payload)) {
                        throw new \UnexpectedValueException('Invalid assignment outbox payload.');
                    }
                    [$title, $message, $userIds] = $this->recipients($tenantId, (string) $event->event_type, $payload);
                    $now = now();
                    foreach (array_unique(array_map('intval', $userIds)) as $userId) {
                        if ($userId < 1) {
                            continue;
                        }
                        DB::table('assignment_notifications')->insertOrIgnore([
                            'tenant_id' => $tenantId, 'user_id' => $userId,
                            'event_key' => 'assignment-notification:'.substr(hash('sha256', $event->event_key.'|'.$userId), 0, 64),
                            'event_type' => $event->event_type, 'title' => mb_substr($title, 0, 180),
                            'message' => mb_substr($message, 0, 500), 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                    DB::table('assignment_outbox_events')->where('tenant_id', $tenantId)->where('id', $eventId)->update([
                        'status' => 'delivered', 'attempts' => min(65535, (int) $event->attempts + 1), 'delivered_at' => $now,
                        'last_error_code' => null, 'updated_at' => $now,
                    ]);

                    return true;
                }, 3);
                if ($delivered) {
                    $result['delivered']++;
                }
            } catch (Throwable) {
                $failed = DB::transaction(function () use ($tenantId, $eventId): bool {
                    $event = DB::table('assignment_outbox_events')->where('tenant_id', $tenantId)->where('id', $eventId)->lockForUpdate()->first();
                    if (! $event || $event->status !== 'queued') {
                        return false;
                    }
                    $attempts = min(65535, (int) $event->attempts + 1);
                    $permanent = $attempts >= 8;
                    DB::table('assignment_outbox_events')->where('tenant_id', $tenantId)->where('id', $eventId)->update([
                        'status' => $permanent ? 'failed' : 'queued', 'attempts' => $attempts,
                        'available_at' => now()->addSeconds(min(3600, 2 ** min($attempts, 10))),
                        'last_error_code' => 'notification_dispatch_failed', 'updated_at' => now(),
                    ]);

                    return $permanent;
                }, 3);
                $result[$failed ? 'failed' : 'retried']++;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $payload
     * @return array{0:string, 1:string, 2:list<int>}
     */
    private function recipients(int $tenantId, string $eventType, array $payload): array
    {
        $assignmentId = (int) ($payload['assignment_id'] ?? 0);
        $assignment = DB::table('assignments')->where('tenant_id', $tenantId)->where('id', $assignmentId)->first();
        if (! $assignment) {
            throw new \UnexpectedValueException('Assignment reference is unavailable.');
        }
        $name = (string) $assignment->title;

        if ($eventType === 'assignment.published') {
            $users = DB::table('assignment_recipients as recipient')->join('tenant_memberships as membership', function ($join) use ($tenantId): void {
                $join->on('membership.id', '=', 'recipient.membership_id')->where('membership.tenant_id', '=', $tenantId)->where('membership.status', '=', 'active');
            })->where('recipient.tenant_id', $tenantId)->where('recipient.assignment_id', $assignmentId)->where('recipient.status', 'eligible')->distinct()->pluck('membership.user_id')->map(static fn ($id): int => (int) $id)->all();

            return ['New assignment published', $name, $users];
        }

        if (in_array($eventType, ['assignment.submission.received', 'assignment.submission.late'], true)) {
            $attemptId = (int) ($payload['attempt_id'] ?? 0);
            $attempt = DB::table('assignment_attempts')->where('tenant_id', $tenantId)->where('id', $attemptId)->first();
            if (! $attempt) {
                throw new \UnexpectedValueException('Submission reference is unavailable.');
            }
            $users = DB::table('assignment_sections as section')->join('course_section_instructors as instructor', function ($join) use ($tenantId): void {
                $join->on('instructor.section_id', '=', 'section.section_id')->where('instructor.tenant_id', '=', $tenantId);
            })->join('tenant_memberships as membership', function ($join) use ($tenantId): void {
                $join->on('membership.user_id', '=', 'instructor.user_id')->where('membership.tenant_id', '=', $tenantId)->where('membership.status', '=', 'active');
            })->where('section.tenant_id', $tenantId)->where('section.assignment_id', $assignmentId)->distinct()->pluck('instructor.user_id')->map(static fn ($id): int => (int) $id)->all();

            return ['Assignment submission received', $name, $users];
        }

        if ($eventType === 'assignment.grade.released') {
            $groupId = (int) ($payload['group_id'] ?? 0);
            if ($groupId > 0) {
                return ['Feedback and grade available', $name, $this->activeGroupUsers($tenantId, $groupId)];
            }
            $recipientId = (int) ($payload['recipient_id'] ?? 0);
            $userId = DB::table('assignment_recipients as recipient')->join('tenant_memberships as membership', function ($join) use ($tenantId): void {
                $join->on('membership.id', '=', 'recipient.membership_id')->where('membership.tenant_id', '=', $tenantId)->where('membership.status', '=', 'active');
            })->where('recipient.tenant_id', $tenantId)->where('recipient.assignment_id', $assignmentId)->where('recipient.id', $recipientId)->value('membership.user_id');

            return ['Feedback and grade available', $name, $userId === null ? [] : [(int) $userId]];
        }

        if (in_array($eventType, ['assignment.extension.approved', 'assignment.extension.rejected', 'assignment.extension.revoked', 'assignment.resubmission.requested'], true)) {
            $groupId = (int) ($payload['group_id'] ?? 0);
            if ($groupId > 0) {
                $title = $eventType === 'assignment.resubmission.requested' ? 'Revision requested' : ($eventType === 'assignment.extension.approved' ? 'Extension approved' : 'Extension request update');

                return [$title, $name, $this->activeGroupUsers($tenantId, $groupId)];
            }
            $recipientId = (int) ($payload['recipient_id'] ?? 0);
            $userId = DB::table('assignment_recipients as recipient')->join('tenant_memberships as membership', function ($join) use ($tenantId): void {
                $join->on('membership.id', '=', 'recipient.membership_id')->where('membership.tenant_id', '=', $tenantId)->where('membership.status', '=', 'active');
            })->where('recipient.tenant_id', $tenantId)->where('recipient.id', $recipientId)->value('membership.user_id');

            $title = match ($eventType) {
                'assignment.extension.approved' => 'Extension approved',
                'assignment.extension.revoked' => 'Extension revoked',
                'assignment.resubmission.requested' => 'Revision requested',
                default => 'Extension request update',
            };

            return [$title, $name, $userId === null ? [] : [(int) $userId]];
        }

        if (in_array($eventType, ['assignment.integrity.concerned', 'assignment.integrity.response', 'assignment.integrity.decision'], true)) {
            $attemptId = (int) ($payload['attempt_id'] ?? 0);
            $attempt = DB::table('assignment_attempts')->where('tenant_id', $tenantId)->where('id', $attemptId)->first();
            $submission = $attempt ? DB::table('assignment_submissions')->where('tenant_id', $tenantId)->where('id', $attempt->submission_id)->first() : null;
            if (! $attempt || ! $submission) {
                throw new \UnexpectedValueException('Integrity case submission reference is unavailable.');
            }
            if ($eventType === 'assignment.integrity.response') {
                $users = DB::table('assignment_sections as section')->join('course_section_instructors as instructor', function ($join) use ($tenantId): void {
                    $join->on('instructor.section_id', '=', 'section.section_id')->where('instructor.tenant_id', '=', $tenantId);
                })->where('section.tenant_id', $tenantId)->where('section.assignment_id', $assignmentId)->distinct()->pluck('instructor.user_id')->map(static fn ($id): int => (int) $id)->all();

                return ['Learner response received', $name, $users];
            }
            if ($eventType === 'assignment.integrity.decision') {
                $groupId = (int) ($payload['group_id'] ?? 0);
                if ($groupId > 0) {
                    return ['Integrity review updated', $name, $this->activeGroupUsers($tenantId, $groupId)];
                }
                $recipientId = (int) ($payload['recipient_id'] ?? 0);
                $userId = DB::table('assignment_recipients as recipient')->join('tenant_memberships as membership', function ($join) use ($tenantId): void {
                    $join->on('membership.id', '=', 'recipient.membership_id')->where('membership.tenant_id', '=', $tenantId)->where('membership.status', '=', 'active');
                })->where('recipient.tenant_id', $tenantId)->where('recipient.id', $recipientId)->value('membership.user_id');

                return ['Integrity review updated', $name, $userId === null ? [] : [(int) $userId]];
            }
            $groupId = (int) ($submission->group_id ?? 0);
            if ($groupId > 0) {
                return ['Academic integrity response requested', $name, $this->activeGroupUsers($tenantId, $groupId)];
            }
            $recipientId = (int) ($submission->recipient_id ?? 0);
            $userId = DB::table('assignment_recipients as recipient')->join('tenant_memberships as membership', function ($join) use ($tenantId): void {
                $join->on('membership.id', '=', 'recipient.membership_id')->where('membership.tenant_id', '=', $tenantId)->where('membership.status', '=', 'active');
            })->where('recipient.tenant_id', $tenantId)->where('recipient.id', $recipientId)->value('membership.user_id');

            return ['Academic integrity response requested', $name, $userId === null ? [] : [(int) $userId]];
        }

        throw new \UnexpectedValueException('Unsupported assignment notification type.');
    }

    /** @return list<int> */
    private function activeGroupUsers(int $tenantId, int $groupId): array
    {
        return DB::table('assignment_group_members as member')->join('tenant_memberships as membership', function ($join) use ($tenantId): void {
            $join->on('membership.id', '=', 'member.membership_id')->where('membership.tenant_id', '=', $tenantId)->where('membership.status', '=', 'active');
        })->where('member.tenant_id', $tenantId)->where('member.group_id', $groupId)->whereNull('member.left_at')->distinct()->pluck('membership.user_id')->map(static fn ($id): int => (int) $id)->all();
    }
}
