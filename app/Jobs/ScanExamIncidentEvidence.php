<?php

namespace App\Jobs;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExamIncidentEvidence;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ScanExamIncidentEvidence implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $tenantId, public readonly int $evidenceId) {}

    public function handle(AdmissionDocumentScanner $scanner, TenantContext $context, AuditRecorder $audit): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $context->enterProvisioning($tenant);
        try {
            $evidence = ExamIncidentEvidence::query()->where('tenant_id', $this->tenantId)->findOrFail($this->evidenceId);
            $expectedPrefix = 'examinations/'.$this->tenantId.'/incidents/'.$evidence->incident_id.'/quarantine/';
            if (! str_starts_with($evidence->private_path, $expectedPrefix)
                || preg_match('/\Aexaminations\/'.$this->tenantId.'\/incidents\/'.$evidence->incident_id.'\/quarantine\/[a-f0-9-]{36}\.(pdf|jpg|jpeg|png)\z/iD', $evidence->private_path) !== 1
                || ! Storage::disk('local')->exists($evidence->private_path)) {
                $this->complete('rejected', 'private_path_invalid', $scanner->name(), $audit);

                return;
            }
            $hash = hash_file('sha256', Storage::disk('local')->path($evidence->private_path));
            if (! is_string($hash) || ! hash_equals($evidence->sha256, $hash)) {
                $this->complete('rejected', 'content_hash_mismatch', $scanner->name(), $audit);

                return;
            }
            ExamIncidentEvidence::query()->whereKey($this->evidenceId)->where('scan_status', 'quarantined')->update(['scan_status' => 'scanning', 'scanner' => $scanner->name(), 'updated_at' => now()]);
            $result = $scanner->scan(Storage::disk('local')->path($evidence->private_path));
            $this->complete($result->status === 'rejected' ? 'infected' : $result->status, $result->code, $scanner->name(), $audit);
        } finally {
            $context->clear();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if (! $tenant) {
            return;
        }
        $context = app(TenantContext::class);
        $context->enterProvisioning($tenant);
        try {
            ExamIncidentEvidence::query()->where('tenant_id', $this->tenantId)->whereKey($this->evidenceId)->whereIn('scan_status', ['quarantined', 'scanning'])->update(['scan_status' => 'unavailable', 'result_code' => 'scanner_job_failed', 'scanned_at' => now(), 'updated_at' => now()]);
        } finally {
            $context->clear();
        }
    }

    private function complete(string $status, ?string $code, string $scanner, AuditRecorder $audit): void
    {
        DB::transaction(function () use ($status, $code, $scanner, $audit): void {
            $evidence = ExamIncidentEvidence::query()->where('tenant_id', $this->tenantId)->lockForUpdate()->find($this->evidenceId);
            if ($evidence === null || ! in_array($evidence->scan_status, ['quarantined', 'scanning', 'unavailable'], true)) {
                return;
            }
            $evidence->forceFill(['scan_status' => $status, 'result_code' => $code, 'scanner' => $scanner, 'scanned_at' => now()])->save();
            $audit->record('exams.incident.evidence.scanned', null, $this->tenantId, $evidence, ['incident_id' => (int) $evidence->incident_id, 'evidence_id' => (int) $evidence->getKey(), 'status' => $status, 'result_code' => $code, 'scanner' => $scanner]);
        }, 3);
    }
}
