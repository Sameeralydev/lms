<?php

namespace App\Jobs;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\SisPrivateDocument;
use App\Models\SisPrivateDocumentScanEvent;
use App\Models\SisPrivateDocumentVersion;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

final class ScanSisStudentDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $documentId,
        public readonly int $versionId,
        public readonly int $scanEventId,
    ) {}

    public function handle(AdmissionDocumentScanner $scanner, TenantContext $context, AuditRecorder $audit): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $context->enterProvisioning($tenant);

        try {
            $attempt = min(max($this->attempts(), 1), 65535);
            $event = SisPrivateDocumentScanEvent::query()
                ->where('document_version_id', $this->versionId)
                ->where('attempt_number', $attempt)
                ->first();
            if (! $event && $attempt === 1) {
                $event = SisPrivateDocumentScanEvent::query()->find($this->scanEventId);
            }
            $event ??= SisPrivateDocumentScanEvent::query()->create([
                'document_version_id' => $this->versionId, 'attempt_number' => $attempt, 'status' => 'pending',
            ]);
            if ($event->status !== 'pending') {
                return;
            }

            $document = SisPrivateDocument::query()->findOrFail($this->documentId);
            $version = SisPrivateDocumentVersion::query()->findOrFail($this->versionId);
            $studentId = (int) $document->student_id;
            if ((int) $document->tenant_id !== $this->tenantId
                || (int) $version->tenant_id !== $this->tenantId
                || (int) $version->document_id !== $this->documentId
                || ! str_starts_with($version->private_path, 'sis/'.$this->tenantId.'/'.$studentId.'/')
                || ! Storage::disk('local')->exists($version->private_path)) {
                $this->complete($event, $document, $version, 'rejected', 'path_scope_invalid', $scanner->name(), $audit);

                return;
            }

            $event->forceFill(['started_at' => now(), 'scanner' => $scanner->name()])->save();
            $result = $scanner->scan(Storage::disk('local')->path($version->private_path));
            $this->complete($event, $document, $version, $result->status, $result->code, $scanner->name(), $audit);
        } finally {
            $context->clear();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $context = app(TenantContext::class);
        $tenant = Tenant::query()->find($this->tenantId);
        if (! $tenant) {
            return;
        }
        $context->enterProvisioning($tenant);
        try {
            $document = SisPrivateDocument::query()->find($this->documentId);
            $version = SisPrivateDocumentVersion::query()->find($this->versionId);
            $event = SisPrivateDocumentScanEvent::query()->where('document_version_id', $this->versionId)->orderByDesc('attempt_number')->first();
            if ($document && $version && $event) {
                $this->complete($event, $document, $version, 'unavailable', 'scanner_job_failed', $event->scanner, app(AuditRecorder::class));
            }
        } finally {
            $context->clear();
        }
    }

    private function complete(
        SisPrivateDocumentScanEvent $event,
        SisPrivateDocument $document,
        SisPrivateDocumentVersion $version,
        string $status,
        ?string $resultCode,
        ?string $scanner,
        AuditRecorder $audit,
    ): void {
        if ((int) $document->tenant_id !== $this->tenantId
            || (int) $version->tenant_id !== $this->tenantId
            || (int) $event->tenant_id !== $this->tenantId
            || (int) $version->document_id !== $this->documentId) {
            return;
        }

        $event->forceFill([
            'status' => $status, 'result_code' => $resultCode,
            'scanner' => $scanner ?? $event->scanner, 'completed_at' => now(),
        ])->save();

        if ((int) $document->current_version === (int) $version->version_number) {
            $document->forceFill(['scan_status' => $status])->save();
        }

        $audit->record('sis.student.document.scanned', null, $this->tenantId, $document, [
            'document_id' => $document->getKey(), 'version' => $version->version_number,
            'status' => $status, 'result_code' => $resultCode, 'scanner' => $scanner,
        ]);
    }
}
