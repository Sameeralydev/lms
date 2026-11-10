<?php

namespace App\Jobs;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AssignmentArtifact;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ScanAssignmentArtifact implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $tenantId, public readonly int $artifactId) {}

    public function handle(AdmissionDocumentScanner $scanner, TenantContext $context, AuditRecorder $audit): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $context->enterProvisioning($tenant);
        try {
            $artifact = AssignmentArtifact::query()->findOrFail($this->artifactId);
            $expectedPrefix = 'assignments/'.$this->tenantId.'/quarantine/';
            if ((int) $artifact->tenant_id !== $this->tenantId || ! str_starts_with($artifact->private_path, $expectedPrefix)
                || preg_match('/\Aassignments\/'.$this->tenantId.'\/quarantine\/[a-f0-9-]{36}\.(pdf|doc|docx|odt|txt|png|jpg|jpeg)\z/iD', $artifact->private_path) !== 1
                || ! Storage::disk('local')->exists($artifact->private_path)) {
                $this->complete($artifact, 'rejected', 'path_scope_invalid', $scanner->name(), $audit);

                return;
            }
            $claimed = DB::transaction(function () use (&$artifact, $scanner): bool {
                $locked = AssignmentArtifact::query()->lockForUpdate()->findOrFail($this->artifactId);
                if (! in_array($locked->scan_status, ['quarantined', 'unavailable'], true)) {
                    return false;
                }
                $locked->forceFill(['scan_status' => 'scanning', 'scanner' => $scanner->name(), 'result_code' => null, 'scanned_at' => null])->save();
                $artifact = $locked;

                return true;
            }, 3);
            if (! $claimed) {
                return;
            }
            $absolutePath = Storage::disk('local')->path($artifact->private_path);
            $actualHash = hash_file('sha256', $absolutePath);
            if (! is_string($actualHash) || ! hash_equals($artifact->sha256, $actualHash)) {
                $this->complete($artifact, 'rejected', 'content_hash_mismatch', $scanner->name(), $audit);

                return;
            }
            $result = $scanner->scan($absolutePath);
            $this->complete($artifact, $result->status === 'rejected' ? 'infected' : $result->status, $result->code, $scanner->name(), $audit);
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
            $artifact = AssignmentArtifact::query()->find($this->artifactId);
            if ($artifact && (int) $artifact->tenant_id === $this->tenantId) {
                $this->complete($artifact, 'unavailable', 'scanner_job_failed', $artifact->scanner, app(AuditRecorder::class));
            }
        } finally {
            $context->clear();
        }
    }

    private function complete(AssignmentArtifact $artifact, string $status, ?string $code, ?string $scanner, AuditRecorder $audit): void
    {
        if ((int) $artifact->tenant_id !== $this->tenantId || ! in_array($artifact->scan_status, ['scanning', 'quarantined', 'unavailable', 'clean'], true)) {
            return;
        }
        DB::transaction(function () use ($artifact, $status, $code, $scanner, $audit): void {
            $locked = AssignmentArtifact::query()->lockForUpdate()->find($artifact->getKey());
            if (! $locked || ! in_array($locked->scan_status, ['scanning', 'quarantined', 'unavailable', 'clean'], true)) {
                return;
            }
            $locked->forceFill(['scan_status' => $status, 'result_code' => $code, 'scanner' => $scanner ?? $locked->scanner, 'scanned_at' => now()])->save();
            $audit->record('assignments.artifact.scanned', null, $this->tenantId, $locked, [
                'artifact_id' => $locked->getKey(), 'status' => $status, 'result_code' => $code, 'scanner' => $scanner,
            ]);
        }, 3);
    }
}
