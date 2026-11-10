<?php

namespace App\Domain\Examinations;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ScanExamAppealEvidence;
use App\Models\ExamAppealEvidence;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ExaminationAppealEvidenceWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AcademicAccess $access, private readonly AuditRecorder $audit) {}

    public function upload(int $appealId, UploadedFile $file, User $actor): int
    {
        $membership = $this->context->membership();
        $tenantId = (int) $membership->tenant_id;
        $appeal = $this->appeal($tenantId, $appealId);
        $result = DB::table('exam_results')->where('tenant_id', $tenantId)->where('id', $appeal->result_id)->first(['session_id', 'candidate_id', 'version']);
        abort_unless($result !== null, 404);
        $session = DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('id', $result->session_id)->firstOrFail();
        $this->access->assertCampus((int) $session->campus_id);
        $this->assertUploader($appeal, $membership, $actor);

        $extension = strtolower((string) $file->guessExtension());
        if (! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true) || $file->getSize() > 10 * 1024 * 1024) {
            throw new HttpException(422, 'Appeal evidence must be a PDF or image up to 10 MB.');
        }

        $relativePath = 'examinations/'.$tenantId.'/appeals/'.$appealId.'/quarantine/'.Str::uuid().'.'.$extension;
        $storedPath = $file->storeAs(dirname($relativePath), basename($relativePath), 'local');
        if ($storedPath !== $relativePath) {
            throw new \RuntimeException('Appeal evidence could not be stored in private quarantine.');
        }
        $absolutePath = Storage::disk('local')->path($relativePath);
        $sha256 = hash_file('sha256', $absolutePath);
        if (! is_string($sha256)) {
            Storage::disk('local')->delete($relativePath);
            throw new \RuntimeException('Appeal evidence could not be fingerprinted.');
        }

        try {
            return DB::transaction(function () use ($appealId, $file, $actor, $tenantId, $relativePath, $sha256, $membership): int {
                $locked = DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->where('id', $appealId)->lockForUpdate()->first();
                abort_unless($locked !== null && $locked->request_type === 'appeal', 404);
                $this->assertUploader($locked, $membership, $actor);
                abort_unless($locked->status !== 'closed', 409, 'Appeal evidence cannot be added after final closure; reopen the appeal through the authorized workflow first.');

                $id = (int) ExamAppealEvidence::query()->create([
                    'tenant_id' => $tenantId,
                    'appeal_id' => $appealId,
                    'uploaded_by' => $actor->getKey(),
                    'private_path' => $relativePath,
                    'original_name' => mb_substr(strip_tags(basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 180),
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                    'sha256' => $sha256,
                    'scan_status' => 'quarantined',
                ])->getKey();
                DB::table('exam_appeal_history')->insert([
                    'tenant_id' => $tenantId, 'appeal_id' => $appealId, 'action' => 'evidence_quarantined',
                    'from_status' => (string) $locked->status, 'to_status' => (string) $locked->status,
                    'result_version' => (int) $locked->result_version, 'actor_user_id' => $actor->getKey(),
                    'safe_details' => json_encode(['evidence_id' => $id, 'sha256' => $sha256, 'size_bytes' => (int) $file->getSize()], JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
                $this->audit->record('exams.appeal.evidence.quarantined', $actor, $tenantId, null, ['appeal_id' => $appealId, 'evidence_id' => $id, 'sha256' => $sha256, 'size_bytes' => (int) $file->getSize()]);
                ScanExamAppealEvidence::dispatch($tenantId, $id)->afterCommit();

                return $id;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($relativePath);
            throw $exception;
        }
    }

    public function download(int $evidenceId, User $actor): BinaryFileResponse
    {
        $membership = $this->context->membership();
        $tenantId = (int) $membership->tenant_id;
        $evidence = ExamAppealEvidence::query()->where('tenant_id', $tenantId)->findOrFail($evidenceId);
        $appeal = $this->appeal($tenantId, (int) $evidence->appeal_id);
        $this->assertUploader($appeal, $membership, $actor);
        abort_unless($evidence->scan_status === 'clean', 404, 'Evidence remains unavailable until the approved malware scanner marks it clean.');
        $prefix = 'examinations/'.$tenantId.'/appeals/'.$evidence->appeal_id.'/quarantine/';
        abort_unless(str_starts_with($evidence->private_path, $prefix) && Storage::disk('local')->exists($evidence->private_path), 404);
        $path = Storage::disk('local')->path($evidence->private_path);
        $actual = hash_file('sha256', $path);
        abort_unless(is_string($actual) && hash_equals($evidence->sha256, $actual), 404);

        DB::table('exam_appeal_history')->insert([
            'tenant_id' => $tenantId, 'appeal_id' => $evidence->appeal_id, 'action' => 'evidence_accessed',
            'from_status' => (string) $appeal->status, 'to_status' => (string) $appeal->status,
            'result_version' => (int) $appeal->result_version, 'actor_user_id' => $actor->getKey(),
            'safe_details' => json_encode(['evidence_id' => (int) $evidence->getKey()], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
        $this->audit->record('exams.appeal.evidence.accessed', $actor, $tenantId, $evidence, ['appeal_id' => (int) $evidence->appeal_id, 'evidence_id' => (int) $evidence->getKey()]);

        return response()->download($path, $evidence->original_name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function appeal(int $tenantId, int $appealId): object
    {
        $appeal = DB::table('exam_recheck_requests as requests')
            ->join('exam_results as results', function ($join): void {
                $join->on('results.tenant_id', '=', 'requests.tenant_id')->on('results.id', '=', 'requests.result_id');
            })
            ->join('exam_candidates as candidates', function ($join): void {
                $join->on('candidates.tenant_id', '=', 'results.tenant_id')->on('candidates.id', '=', 'results.candidate_id');
            })
            ->join('sis_students as students', function ($join): void {
                $join->on('students.tenant_id', '=', 'candidates.tenant_id')->on('students.id', '=', 'candidates.student_id');
            })
            ->where('requests.tenant_id', $tenantId)->where('requests.id', $appealId)->where('requests.request_type', 'appeal')
            ->select('requests.*', 'students.user_id as candidate_user_id')->first();
        abort_unless($appeal !== null && (int) $appeal->student_user_id === (int) $appeal->candidate_user_id, 404);

        return $appeal;
    }

    private function assertUploader(object $appeal, object $membership, User $actor): void
    {
        abort_unless((int) $appeal->student_user_id === (int) $actor->getKey() || $membership->hasPermission('exams.recheck.review'), 403);
        abort_unless($membership->status === 'active' && $membership->revoked_at === null, 403);
    }
}
