<?php

namespace App\Domain\Academic\Attendance;

use Illuminate\Support\Facades\DB;
use Throwable;

final class AttendanceOutboxProcessor
{
    private const MAX_ATTEMPTS = 8;

    private const LEASE_MINUTES = 5;

    public function __construct(private readonly AttendanceOutboxDispatcher $dispatcher) {}

    /** @return array{claimed:int,delivered:int,retried:int,failed:int} */
    public function dispatchAvailable(int $limit = 100, ?int $tenantId = null): array
    {
        $result = ['claimed' => 0, 'delivered' => 0, 'retried' => 0, 'failed' => 0];

        for ($index = 0; $index < max(1, min($limit, 500)); $index++) {
            $message = $this->claimOne($tenantId);
            if ($message === null) {
                break;
            }
            $result['claimed']++;

            try {
                $payload = json_decode((string) $message->safe_payload, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($payload)) {
                    throw new \UnexpectedValueException('Malformed attendance outbox payload.');
                }
                $this->dispatcher->dispatch((int) $message->id, (int) $message->tenant_id, (string) $message->event_type, $payload, (int) $message->attempts);
                DB::table('attendance_outbox_events')->where('id', $message->id)->where('status', 'processing')->where('attempts', $message->attempts)->update([
                    'status' => 'delivered', 'delivered_at' => now(), 'locked_at' => null, 'last_error_code' => null, 'updated_at' => now(),
                ]);
                $result['delivered']++;
            } catch (Throwable) {
                $permanent = (int) $message->attempts >= self::MAX_ATTEMPTS;
                $delay = min(3600, 2 ** min((int) $message->attempts, 10));
                DB::table('attendance_outbox_events')->where('id', $message->id)->where('status', 'processing')->where('attempts', $message->attempts)->update([
                    'status' => $permanent ? 'failed' : 'queued',
                    'available_at' => $permanent ? $message->available_at : now()->addSeconds($delay),
                    'locked_at' => null, 'last_error_code' => 'local_dispatch_failed', 'updated_at' => now(),
                ]);
                $result[$permanent ? 'failed' : 'retried']++;
            }
        }

        return $result;
    }

    private function claimOne(?int $tenantId): ?object
    {
        return DB::transaction(function () use ($tenantId): ?object {
            $event = DB::table('attendance_outbox_events')
                ->when($tenantId !== null, static fn ($query) => $query->where('tenant_id', $tenantId))
                ->where('attempts', '<', self::MAX_ATTEMPTS)
                ->where(function ($query): void {
                    $query->where(function ($queued): void {
                        $queued->where('status', 'queued')->where('available_at', '<=', now());
                    })->orWhere(function ($stale): void {
                        $stale->where('status', 'processing')->where('locked_at', '<=', now()->subMinutes(self::LEASE_MINUTES));
                    });
                })
                ->orderBy('id')->lockForUpdate()->first();

            if ($event === null) {
                return null;
            }
            DB::table('attendance_outbox_events')->where('id', $event->id)->update([
                'status' => 'processing', 'attempts' => (int) $event->attempts + 1, 'locked_at' => now(), 'last_error_code' => null, 'updated_at' => now(),
            ]);

            return DB::table('attendance_outbox_events')->where('id', $event->id)->first();
        }, 3);
    }
}
