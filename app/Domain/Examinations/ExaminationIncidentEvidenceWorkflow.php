<?php

namespace App\Domain\Examinations;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ScanExamIncidentEvidence;
use App\Models\ExamIncidentEvidence;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ExaminationIncidentEvidenceWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AcademicAccess $access, private readonly AuditRecorder $audit) {}

    public function upload(int $incidentId, UploadedFile $file, User $actor): int
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('exams.incidents.report') || $membership->hasPermission('exams.incidents.review'), 403);
        $tenantId = (int) $membership->tenant_id;
        $incident = DB::table('exam_incidents')->where('tenant_id', $tenantId)->where('id', $incidentId)->first();
        abort_unless($incident !== null, 404);
        $session = DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('id', $incident->session_id)->firstOrFail();
        $this->access->assertCampus((int) $session->campus_id);
        abort_unless((int) $incident->reported_by === (int) $actor->getKey() || $membership->hasPermission('exams.incidents.review'), 403);
        abort_unless(in_array($incident->status, ['reported', 'reviewed', 'escalated', 'investigating'], true), 409, 'Evidence cannot be added after the incident is resolved or closed.');

        $extension = strtolower($file->guessExtension() ?: '');
        if (! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            throw new HttpException(422, 'Evidence must be a PDF or image file.');
        }
        $relativePath = 'examinations/'.$tenantId.'/incidents/'.$incidentId.'/quarantine/'.Str::uuid().'.'.$extension;
        $storedPath = $file->storeAs(dirname($relativePath), basename($relativePath), 'local');
        if ($storedPath !== $relativePath) {
            throw new \RuntimeException('Incident evidence could not be stored in private quarantine.');
        }
        $absolutePath = Storage::disk('local')->path($relativePath);
        $sha256 = hash_file('sha256', $absolutePath);
        if (! is_string($sha256)) {
            Storage::disk('local')->delete($relativePath);
            throw new \RuntimeException('Incident evidence could not be fingerprinted.');
        }
        try {
            $id = DB::transaction(function () use ($incidentId, $file, $actor, $tenantId, $relativePath, $sha256): int {
                $locked = DB::table('exam_incidents')->where('tenant_id', $tenantId)->where('id', $incidentId)->lockForUpdate()->first();
                abort_unless($locked !== null, 404);
                abort_unless(in_array($locked->status, ['reported', 'reviewed', 'escalated', 'investigating'], true), 409, 'Evidence cannot be added after the incident is resolved or closed.');
                $id = (int) ExamIncidentEvidence::query()->create([
                    'tenant_id' => $tenantId,
                    'incident_id' => $incidentId,
                    'uploaded_by' => $actor->getKey(),
                    'private_path' => $relativePath,
                    'original_name' => mb_substr(strip_tags(basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 180),
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                    'sha256' => $sha256,
                    'scan_status' => 'quarantined',
                ])->getKey();
                $this->audit->record('exams.incident.evidence.quarantined', $actor, $tenantId, null, ['incident_id' => $incidentId, 'evidence_id' => $id, 'sha256' => $sha256, 'size_bytes' => (int) $file->getSize()]);
                ScanExamIncidentEvidence::dispatch($tenantId, $id)->afterCommit();

                return $id;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($relativePath);
            throw $exception;
        }

        return $id;
    }

    public function download(int $evidenceId, User $actor): BinaryFileResponse
    {
        $membership = $this->context->membership();
        $tenantId = (int) $membership->tenant_id;
        $evidence = ExamIncidentEvidence::query()->where('tenant_id', $tenantId)->findOrFail($evidenceId);
        $incident = DB::table('exam_incidents')->where('tenant_id', $tenantId)->where('id', $evidence->incident_id)->first();
        abort_unless($incident !== null, 404);
        $session = DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('id', $incident->session_id)->firstOrFail();
        $this->access->assertCampus((int) $session->campus_id);
        abort_unless((int) $incident->reported_by === (int) $actor->getKey() || $membership->hasPermission('exams.incidents.review'), 403);
        abort_unless($evidence->scan_status === 'clean', 404, 'Evidence is unavailable until an approved scanner marks it clean.');
        $prefix = 'examinations/'.$tenantId.'/incidents/'.$evidence->incident_id.'/quarantine/';
        abort_unless(str_starts_with($evidence->private_path, $prefix) && Storage::disk('local')->exists($evidence->private_path), 404);
        $path = Storage::disk('local')->path($evidence->private_path);
        $actual = hash_file('sha256', $path);
        abort_unless(is_string($actual) && hash_equals($evidence->sha256, $actual), 404);
        $this->audit->record('exams.incident.evidence.accessed', $actor, $tenantId, $evidence, ['incident_id' => (int) $incident->id, 'evidence_id' => (int) $evidence->getKey()]);

        return response()->download($path, $evidence->original_name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }
}
