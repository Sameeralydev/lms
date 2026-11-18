<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable|null $next_run_at
 * @property-read EloquentCollection<int,AcademicAnalyticsReportRun> $runs
 * @property-read EloquentCollection<int,TenantMembership> $recipients
 */
final class AcademicAnalyticsReportSchedule extends AcademicRecord
{
    protected $table = 'academic_analytics_report_schedules';

    protected function casts(): array
    {
        return ['next_run_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AcademicAnalyticsSavedReport, $this> */
    public function savedReport(): BelongsTo
    {
        return $this->belongsTo(AcademicAnalyticsSavedReport::class, 'saved_report_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }

    /** @return HasMany<AcademicAnalyticsReportRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AcademicAnalyticsReportRun::class, 'schedule_id');
    }

    /** @return BelongsToMany<TenantMembership, $this> */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(TenantMembership::class, 'academic_analytics_report_schedule_recipients', 'schedule_id', 'membership_id')->withPivot('tenant_id');
    }
}
