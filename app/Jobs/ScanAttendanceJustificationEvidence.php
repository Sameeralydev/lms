<?php

namespace App\Jobs;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Tenancy\TenantContext;
use App\Models\AttendanceJustificationEvidence;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

final class ScanAttendanceJustificationEvidence implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $tenantId, public readonly int $evidenceId) {}

    public function handle(AdmissionDocumentScanner $scanner, TenantContext $context): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $context->enterProvisioning($tenant);
        try {
            $evidence = AttendanceJustificationEvidence::query()->whereKey($this->evidenceId)->firstOrFail();
            $expectedPrefix = 'attendance/'.$this->tenantId.'/quarantine/';
            if (! str_starts_with($evidence->private_path, $expectedPrefix)
                || preg_match('/\Aattendance\/'.preg_quote((string) $this->tenantId, '/').'\/quarantine\/[a-f0-9-]{36}\.(pdf|jpg|png)\z/iD', $evidence->private_path) !== 1
                || ! Storage::disk('local')->exists($evidence->private_path)) {
                $evidence->forceFill(['scan_status' => 'rejected', 'result_code' => 'private_path_invalid', 'scanner' => $scanner->name(), 'scanned_at' => now()])->save();

                return;
            }
            $absolutePath = Storage::disk('local')->path($evidence->private_path);
            if (! hash_equals($evidence->sha256, (string) hash_file('sha256', $absolutePath))) {
                $evidence->forceFill(['scan_status' => 'rejected', 'result_code' => 'digest_mismatch', 'scanner' => $scanner->name(), 'scanned_at' => now()])->save();

                return;
            }
            $result = $scanner->scan($absolutePath);
            $evidence->forceFill([
                'scan_status' => $result->status, 'result_code' => $result->code,
                'scanner' => $scanner->name(), 'scanned_at' => now(),
            ])->save();
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
            AttendanceJustificationEvidence::query()->whereKey($this->evidenceId)->where('scan_status', 'quarantined')
                ->update(['scan_status' => 'unavailable', 'result_code' => 'scanner_job_failed', 'scanned_at' => now(), 'updated_at' => now()]);
        } finally {
            $context->clear();
        }
    }
}
