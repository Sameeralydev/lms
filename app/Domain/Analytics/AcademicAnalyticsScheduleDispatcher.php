<?php

namespace App\Domain\Analytics;

use App\Jobs\GenerateAcademicAnalyticsReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AcademicAnalyticsScheduleDispatcher
{
    public function dispatchDue(int $limit = 50): int
    {
        $pending = DB::table('academic_analytics_report_runs')->where('status', 'queued')->orderBy('created_at')->limit($limit)->pluck('id');
        foreach ($pending as $runId) {
            GenerateAcademicAnalyticsReport::dispatch((int) $runId)->afterCommit();
        }
        $dispatched = $pending->count();
        $ids = DB::table('academic_analytics_report_schedules')->where('status', 'active')->where('next_run_at', '<=', now())->orderBy('next_run_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $runId = DB::transaction(function () use ($id): ?int {
                $schedule = DB::table('academic_analytics_report_schedules')->where('id', $id)->lockForUpdate()->first();
                if ($schedule === null || $schedule->status !== 'active' || CarbonImmutable::parse($schedule->next_run_at)->isFuture()) {
                    return null;
                }
                $scheduledFor = CarbonImmutable::parse($schedule->next_run_at)->utc();
                $inserted = DB::table('academic_analytics_report_runs')->insertOrIgnore([
                    'tenant_id' => $schedule->tenant_id, 'schedule_id' => $schedule->id, 'scheduled_for' => $scheduledFor,
                    'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $next = app(AcademicAnalyticsScheduleService::class)->nextOccurrence((array) $schedule, CarbonImmutable::now('UTC'));
                DB::table('academic_analytics_report_schedules')->where('id', $id)->update(['next_run_at' => $next, 'updated_at' => now()]);
                if ($inserted !== 1) {
                    return null;
                }

                return (int) DB::table('academic_analytics_report_runs')->where('schedule_id', $schedule->id)->where('scheduled_for', $scheduledFor)->value('id');
            });
            if ($runId !== null) {
                GenerateAcademicAnalyticsReport::dispatch($runId)->afterCommit();
                $dispatched++;
            }
        }

        return $dispatched;
    }
}
