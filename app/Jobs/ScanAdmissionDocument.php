<?php

namespace App\Jobs;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Tenancy\TenantContext;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentScanEvent;
use App\Models\AdmissionDocumentVersion;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

final class ScanAdmissionDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $documentId,
        public readonly int $versionId,
        public readonly int $scanEventId,
    ) {}

    public function handle(AdmissionDocumentScanner $scanner, TenantContext $context): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $context->enterProvisioning($tenant);

        try {
            $attempt = min(max($this->attempts(), 1), 65535);
            $event = AdmissionDocumentScanEvent::query()
                ->where('document_version_id', $this->versionId)
                ->where('attempt_number', $attempt)
                ->first();
            if (! $event && $attempt === 1) {
                $event = AdmissionDocumentScanEvent::query()->find($this->scanEventId);
            }
            $event ??= AdmissionDocumentScanEvent::query()->create([
                'document_version_id' => $this->versionId, 'attempt_number' => $attempt, 'status' => 'pending',
            ]);
            if ($event->status !== 'pending') {
                return;
            }
            $version = AdmissionDocumentVersion::query()->findOrFail($this->versionId);
            if ((int) $version->tenant_id !== $this->tenantId || ! str_starts_with($version->private_path, 'admissions/'.$this->tenantId.'/')) {
                $this->complete($context, (int) $event->getKey(), 'rejected', 'path_scope_invalid', $scanner->name());

                return;
            }

            $event->forceFill(['started_at' => now(), 'scanner' => $scanner->name()])->save();
            $result = $scanner->scan(Storage::disk('local')->path($version->private_path));
            $this->complete($context, (int) $event->getKey(), $result->status, $result->code, $scanner->name());
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
            $latestEventId = AdmissionDocumentScanEvent::query()->where('document_version_id', $this->versionId)->orderByDesc('attempt_number')->value('id');
            if ($latestEventId !== null) {
                $this->complete($context, (int) $latestEventId, 'unavailable', 'scanner_job_failed', null);
            }
        } finally {
            $context->clear();
        }
    }

    private function complete(TenantContext $context, int $eventId, string $status, ?string $resultCode, ?string $scanner): void
    {
        $event = AdmissionDocumentScanEvent::query()->find($eventId);
        $document = AdmissionDocument::query()->find($this->documentId);
        if (! $event || ! $document || (int) $document->tenant_id !== $this->tenantId) {
            return;
        }

        $event->forceFill([
            'status' => $status, 'result_code' => $resultCode,
            'scanner' => $scanner ?? $event->scanner, 'completed_at' => now(),
        ])->save();

        $version = AdmissionDocumentVersion::query()->find($this->versionId);
        if ($version && (int) $version->tenant_id === $this->tenantId && (int) $document->current_version === (int) $version->version_number) {
            $document->forceFill(['scan_status' => $status])->save();
        }
    }
}
