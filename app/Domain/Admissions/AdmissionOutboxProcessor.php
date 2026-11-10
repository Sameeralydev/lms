<?php

namespace App\Domain\Admissions;

use App\Models\AdmissionOutboxEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AdmissionOutboxProcessor
{
    private const MAX_ATTEMPTS = 8;

    private const LEASE_MINUTES = 5;

    public function __construct(private readonly AdmissionOutboxDispatcher $dispatcher) {}

    /** @return array{claimed:int,processed:int,retried:int,failed:int} */
    public function dispatchAvailable(int $limit = 100): array
    {
        $result = ['claimed' => 0, 'processed' => 0, 'retried' => 0, 'failed' => 0];

        for ($index = 0; $index < max(1, min($limit, 500)); $index++) {
            $message = $this->claimOne();
            if (! $message) {
                break;
            }
            $result['claimed']++;

            try {
                $this->dispatcher->dispatch($message);
                AdmissionOutboxEvent::query()->withoutGlobalScope('tenant')->whereKey($message->getKey())
                    ->where('status', 'processing')->where('attempts', $message->attempts)
                    ->update(['status' => 'processed', 'processed_at' => now(), 'locked_at' => null, 'last_error_code' => null, 'updated_at' => now()]);
                $result['processed']++;
            } catch (Throwable) {
                $permanent = (int) $message->attempts >= self::MAX_ATTEMPTS;
                $delaySeconds = min(3600, 2 ** min((int) $message->attempts, 10));
                AdmissionOutboxEvent::query()->withoutGlobalScope('tenant')->whereKey($message->getKey())
                    ->where('status', 'processing')->where('attempts', $message->attempts)
                    ->update([
                        'status' => $permanent ? 'failed' : 'queued',
                        'available_at' => $permanent ? $message->available_at : now()->addSeconds($delaySeconds),
                        'locked_at' => null,
                        'last_error_code' => 'local_dispatch_failed',
                        'updated_at' => now(),
                    ]);
                $result[$permanent ? 'failed' : 'retried']++;
            }
        }

        return $result;
    }

    private function claimOne(): ?AdmissionOutboxEvent
    {
        return DB::transaction(function (): ?AdmissionOutboxEvent {
            $event = AdmissionOutboxEvent::query()->withoutGlobalScope('tenant')
                ->where('attempts', '<', self::MAX_ATTEMPTS)
                ->where(function ($query): void {
                    $query->where(function ($queued): void {
                        $queued->where('status', 'queued')->where(fn ($due) => $due->whereNull('available_at')->orWhere('available_at', '<=', now()));
                    })->orWhere(function ($stale): void {
                        $stale->where('status', 'processing')->where('locked_at', '<=', now()->subMinutes(self::LEASE_MINUTES));
                    });
                })
                ->orderBy('id')->lockForUpdate()->first();

            if (! $event) {
                return null;
            }

            $event->forceFill([
                'status' => 'processing', 'attempts' => (int) $event->attempts + 1,
                'locked_at' => now(), 'last_error_code' => null,
            ])->save();

            return $event->fresh();
        }, 3);
    }
}
