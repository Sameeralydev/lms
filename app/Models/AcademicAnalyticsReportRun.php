<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AcademicAnalyticsReportRun extends AcademicRecord
{
    protected $table = 'academic_analytics_report_runs';

    protected function casts(): array
    {
        return ['scheduled_for' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AcademicAnalyticsReportSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AcademicAnalyticsReportSchedule::class, 'schedule_id');
    }
}
