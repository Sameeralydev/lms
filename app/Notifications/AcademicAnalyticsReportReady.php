<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class AcademicAnalyticsReportReady extends Notification
{
    use Queueable;

    public function __construct(public readonly int $runId, public readonly string $tenantSlug, public readonly string $reportName) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, int|string> */
    public function toDatabase(object $notifiable): array
    {
        return ['run_id' => $this->runId, 'tenant_slug' => $this->tenantSlug, 'report_name' => $this->reportName, 'url' => "/t/{$this->tenantSlug}/academics/analytics/schedules/runs/{$this->runId}"];
    }
}
