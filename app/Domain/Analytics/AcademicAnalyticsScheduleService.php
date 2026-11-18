<?php

namespace App\Domain\Analytics;

use App\Domain\Audit\AuditRecorder;
use App\Models\AcademicAnalyticsReportSchedule;
use App\Models\AcademicAnalyticsSavedReport;
use App\Models\TenantMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicAnalyticsScheduleService
{
    /** @param array{saved_report_id:int,name:string,frequency:string,timezone:string,local_time:string,weekday?:int|null,day_of_month?:int|null} $data
     * @param  list<int>  $recipientIds
     */
    public function create(TenantMembership $owner, array $data, array $recipientIds, AuditRecorder $audit): AcademicAnalyticsReportSchedule
    {
        $report = AcademicAnalyticsSavedReport::query()->where('membership_id', $owner->getKey())->where('institution_id', $owner->institution_id)->findOrFail($data['saved_report_id']);
        $recipientIds = array_values(array_unique([...$recipientIds, (int) $owner->getKey()]));
        $recipients = TenantMembership::query()->where('tenant_id', $owner->tenant_id)->where('institution_id', $owner->institution_id)->where('status', 'active')->whereIn('id', $recipientIds)->get();
        if ($recipients->count() !== count($recipientIds) || $recipients->contains(fn (TenantMembership $recipient): bool => ! $recipient->hasPermission('academic.analytics.view') || ! $recipient->hasPermission('academic.analytics.export'))) {
            throw ValidationException::withMessages(['recipients' => 'Every recipient must be an active member with analytics view and export permissions.']);
        }
        $this->validateSchedule($data);
        $next = $this->nextOccurrence($data, CarbonImmutable::now('UTC'));

        return DB::transaction(function () use ($owner, $report, $data, $recipientIds, $next, $audit): AcademicAnalyticsReportSchedule {
            $schedule = AcademicAnalyticsReportSchedule::query()->create([
                'tenant_id' => $owner->tenant_id, 'institution_id' => $owner->institution_id, 'membership_id' => $owner->getKey(),
                'saved_report_id' => $report->getKey(), 'name' => $data['name'], 'frequency' => $data['frequency'], 'timezone' => $data['timezone'],
                'local_time' => $data['local_time'], 'weekday' => $data['weekday'] ?? null, 'day_of_month' => $data['day_of_month'] ?? null,
                'next_run_at' => $next, 'status' => 'active',
            ]);
            foreach ($recipientIds as $recipientId) {
                DB::table('academic_analytics_report_schedule_recipients')->insert(['tenant_id' => $owner->tenant_id, 'schedule_id' => $schedule->getKey(), 'membership_id' => $recipientId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $audit->record('academic.analytics.schedule.created', auth()->user(), (int) $owner->tenant_id, $schedule, ['frequency' => $schedule->frequency, 'recipient_count' => count($recipientIds)]);

            return $schedule;
        });
    }

    public function setStatus(AcademicAnalyticsReportSchedule $schedule, string $status, AuditRecorder $audit): void
    {
        if (! in_array($status, ['active', 'paused', 'cancelled'], true) || $schedule->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'This schedule transition is not allowed.']);
        }
        $schedule->forceFill(['status' => $status])->save();
        $audit->record('academic.analytics.schedule.'.$status, auth()->user(), (int) $schedule->tenant_id, $schedule, ['schedule_id' => $schedule->getKey()]);
    }

    /** @param array{saved_report_id:int,name:string,frequency:string,timezone:string,local_time:string,weekday?:int|null,day_of_month?:int|null} $data
     * @param  list<int>|null  $recipientIds
     */
    public function update(AcademicAnalyticsReportSchedule $schedule, TenantMembership $owner, array $data, ?array $recipientIds, AuditRecorder $audit): void
    {
        if ($schedule->status === 'cancelled') {
            throw ValidationException::withMessages(['schedule' => 'Cancelled schedules cannot be edited.']);
        }
        $report = AcademicAnalyticsSavedReport::query()->where('membership_id', $owner->getKey())->where('institution_id', $owner->institution_id)->findOrFail($data['saved_report_id']);
        $this->validateSchedule($data);
        $next = $this->nextOccurrence($data, CarbonImmutable::now('UTC'));
        DB::transaction(function () use ($schedule, $owner, $report, $data, $recipientIds, $next, $audit): void {
            $locked = AcademicAnalyticsReportSchedule::query()->where('membership_id', $owner->getKey())->lockForUpdate()->findOrFail($schedule->getKey());
            $ids = $recipientIds === null ? DB::table('academic_analytics_report_schedule_recipients')->where('schedule_id', $locked->getKey())->pluck('membership_id')->map(fn ($id): int => (int) $id)->all() : array_values(array_unique([...$recipientIds, (int) $owner->getKey()]));
            $recipients = TenantMembership::query()->where('tenant_id', $owner->tenant_id)->where('institution_id', $owner->institution_id)->where('status', 'active')->whereIn('id', $ids)->get();
            if ($recipients->count() !== count($ids) || $recipients->contains(fn (TenantMembership $recipient): bool => ! $recipient->hasPermission('academic.analytics.view') || ! $recipient->hasPermission('academic.analytics.export'))) {
                throw ValidationException::withMessages(['recipients' => 'Every recipient must be an active member with analytics view and export permissions.']);
            }
            $locked->forceFill(['saved_report_id' => $report->getKey(), 'name' => $data['name'], 'frequency' => $data['frequency'], 'timezone' => $data['timezone'], 'local_time' => $data['local_time'], 'weekday' => $data['weekday'] ?? null, 'day_of_month' => $data['day_of_month'] ?? null, 'next_run_at' => $next])->save();
            DB::table('academic_analytics_report_schedule_recipients')->where('schedule_id', $locked->getKey())->delete();
            foreach ($ids as $recipientId) {
                DB::table('academic_analytics_report_schedule_recipients')->insert(['tenant_id' => $owner->tenant_id, 'schedule_id' => $locked->getKey(), 'membership_id' => $recipientId, 'created_at' => now(), 'updated_at' => now()]);
            }
            $audit->record('academic.analytics.schedule.updated', $owner->user, (int) $owner->tenant_id, $locked, ['schedule_id' => $locked->getKey(), 'recipient_count' => count($ids)]);
        });
    }

    /** @param array{name:string,frequency:string,timezone:string,local_time:string,weekday?:int|null,day_of_month?:int|null} $data */
    public function nextOccurrence(array $data, CarbonImmutable $after): CarbonImmutable
    {
        $local = $after->setTimezone($data['timezone'])->setTimeFromTimeString($data['local_time']);
        if ($data['frequency'] === 'daily') {
            if ($local->lessThanOrEqualTo($after->setTimezone($data['timezone']))) {
                $local = $local->addDay();
            }
        } elseif ($data['frequency'] === 'weekly') {
            $days = ((int) $data['weekday'] - $local->dayOfWeek + 7) % 7;
            $local = $local->addDays($days);
            if ($local->lessThanOrEqualTo($after->setTimezone($data['timezone']))) {
                $local = $local->addWeek();
            }
        } else {
            $day = (int) $data['day_of_month'];
            $local = $local->setDay($day);
            if ($local->lessThanOrEqualTo($after->setTimezone($data['timezone']))) {
                $local = $local->addMonthNoOverflow()->setDay($day);
            }
        }

        return $local->utc();
    }

    /** @param array{name:string,frequency:string,timezone:string,local_time:string,weekday?:int|null,day_of_month?:int|null} $data */
    private function validateSchedule(array $data): void
    {
        try {
            new \DateTimeZone($data['timezone']);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['timezone' => 'Choose a valid timezone.']);
        }
        if (! in_array($data['frequency'], ['daily', 'weekly', 'monthly'], true)) {
            throw ValidationException::withMessages(['frequency' => 'Choose an approved frequency.']);
        }
        if ($data['frequency'] === 'weekly' && ! isset($data['weekday'])) {
            throw ValidationException::withMessages(['weekday' => 'Choose a weekday for weekly schedules.']);
        }
        if ($data['frequency'] === 'monthly' && ! isset($data['day_of_month'])) {
            throw ValidationException::withMessages(['day_of_month' => 'Choose a day for monthly schedules.']);
        }
    }
}
