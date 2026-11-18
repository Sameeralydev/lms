<?php

namespace App\Jobs;

use App\Domain\Analytics\AcademicReportBuilder;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Grading\AcademicRecordPdf;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicAnalyticsReportRun;
use App\Models\AcademicAnalyticsReportSchedule;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\AcademicAnalyticsReportReady;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class GenerateAcademicAnalyticsReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $runId) {}

    public function handle(TenantContext $context, AcademicReportBuilder $builder, AcademicRecordPdf $pdf, AuditRecorder $audit): void
    {
        $run = AcademicAnalyticsReportRun::query()->withoutGlobalScope('tenant')->findOrFail($this->runId);
        $claimed = DB::transaction(function (): bool {
            $locked = DB::table('academic_analytics_report_runs')->where('id', $this->runId)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'queued') {
                return false;
            }
            DB::table('academic_analytics_report_runs')->where('id', $this->runId)->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);

            return true;
        });
        if (! $claimed) {
            return;
        }
        $schedule = AcademicAnalyticsReportSchedule::query()->withoutGlobalScope('tenant')->findOrFail($run->schedule_id);
        $owner = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $schedule->tenant_id)->findOrFail($schedule->membership_id);
        $tenant = Tenant::query()->withoutGlobalScope('tenant')->findOrFail($schedule->tenant_id);
        $context->set($tenant, $owner);
        try {
            if ($schedule->status !== 'active' || $owner->status !== 'active' || ! $owner->hasPermission('academic.analytics.view') || ! $owner->hasPermission('academic.analytics.export')) {
                throw new RuntimeException('schedule_not_authorized');
            }
            $reportModel = $schedule->savedReport()->first();
            if ($reportModel === null || (int) $reportModel->membership_id !== (int) $owner->getKey()) {
                throw new RuntimeException('saved_report_unavailable');
            }
            $run->forceFill(['status' => 'running', 'started_at' => now(), 'failure_code' => null])->save();
            $saved = $reportModel->filters;
            $config = $saved['builder'] ?? [];
            $report = $builder->build($owner, [
                'dataset' => $config['dataset'] ?? 'enrollments', 'measure' => $config['measure'] ?? 'records',
                'dimensions' => $config['dimensions'] ?? ['status'], 'campus_id' => $saved['campus_id'] ?? null,
                'from' => $saved['from'] ?? null, 'to' => $saved['to'] ?? null, 'status' => $config['status'] ?? null,
                'filters' => $config['filters'] ?? [], 'search' => $config['search'] ?? null,
                'sort' => $config['sort'] ?? 'total', 'direction' => $config['direction'] ?? 'desc',
            ], true);
            $institutionName = DB::table('institutions')->where('tenant_id', $owner->tenant_id)->where('id', $owner->institution_id)->value('display_name');
            $campusName = $report['campus_id'] === null ? 'All authorized campuses' : (DB::table('campuses')->where('tenant_id', $owner->tenant_id)->where('institution_id', $owner->institution_id)->where('id', $report['campus_id'])->value('name') ?? 'Authorized campus');
            $lines = ['TechMentors.PK - Scheduled academic report', $schedule->name, 'Source: '.AcademicReportBuilder::definitions()[$report['dataset']]['label'], 'Institution: '.($institutionName ?: 'Institution '.$owner->institution_id), 'Campus scope: '.$campusName, 'Generated: '.now()->toDateTimeString(), 'Measure: '.$report['measure_label'], 'Filters: '.json_encode(['status' => $report['status'], ...$report['filters']], JSON_UNESCAPED_UNICODE), 'Matching groups: '.$report['total']];
            foreach ($report['rows'] as $row) {
                $lines[] = implode(' | ', array_map(static fn (string $dimension): string => $dimension.': '.(string) ($row[$dimension] ?? 'Unspecified'), $report['dimensions'])).' | '.$report['measure_label'].': '.$row['total'];
            }
            $path = "analytics/{$schedule->tenant_id}/{$schedule->getKey()}/{$run->getKey()}.pdf";
            if (! Storage::disk('local')->put($path, $pdf->render($lines))) {
                throw new RuntimeException('report_storage_failed');
            }

            $recipientIds = DB::table('academic_analytics_report_schedule_recipients')->where('tenant_id', $schedule->tenant_id)->where('schedule_id', $schedule->getKey())->pluck('membership_id');
            $recipients = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $schedule->tenant_id)->where('institution_id', $schedule->institution_id)->where('status', 'active')->whereIn('id', $recipientIds)->get();
            $authorized = $recipients->filter(fn (TenantMembership $recipient): bool => $recipient->hasPermission('academic.analytics.view') && $recipient->hasPermission('academic.analytics.export'));
            if ($authorized->isEmpty()) {
                throw new RuntimeException('no_authorized_recipients');
            }
            foreach ($authorized as $recipient) {
                $user = User::query()->find($recipient->user_id);
                if ($user !== null && ! $user->notifications()->where('type', AcademicAnalyticsReportReady::class)->where('data->run_id', $run->getKey())->exists()) {
                    $user->notify(new AcademicAnalyticsReportReady((int) $run->getKey(), $tenant->slug, $schedule->name));
                }
            }
            $run->forceFill(['status' => 'completed', 'storage_path' => $path, 'recipient_count' => $authorized->count(), 'finished_at' => now()])->save();
            $audit->record('academic.analytics.schedule.completed', $owner->user, (int) $owner->tenant_id, $run, ['schedule_id' => $schedule->getKey(), 'recipient_count' => $authorized->count()]);
        } catch (Throwable $exception) {
            AcademicAnalyticsReportRun::query()->withoutGlobalScope('tenant')->whereKey($this->runId)->where('status', 'running')->update(['status' => 'queued', 'failure_code' => 'generation_retry', 'updated_at' => now()]);
            throw $exception;
        } finally {
            $context->clear();
        }
    }

    public function failed(Throwable $exception): void
    {
        AcademicAnalyticsReportRun::query()->withoutGlobalScope('tenant')->whereKey($this->runId)->where('status', '!=', 'completed')->update(['status' => 'failed', 'failure_code' => 'generation_failed', 'finished_at' => now()]);
    }
}
