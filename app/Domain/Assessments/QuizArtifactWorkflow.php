<?php

namespace App\Domain\Assessments;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\QuizAttempt;
use App\Models\QuizFileArtifact;
use App\Models\QuizQuestionVersion;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class QuizArtifactWorkflow
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** @var array<string,string> */
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.oasis.opendocument.text' => 'odt',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AdmissionDocumentScanner $scanner,
        private readonly AuditRecorder $audit,
        private readonly QuizAssessmentWorkflow $assessments,
    ) {}

    public function uploadQuestionAsset(int $questionVersionId, UploadedFile $file, User $actor): QuizFileArtifact
    {
        $membership = $this->membership();
        abort_unless($membership->hasPermission('assessment.bank.manage'), 403);
        $version = QuizQuestionVersion::query()->with('question.bank')->findOrFail($questionVersionId);
        $this->assertQuestionBankScope($version, $membership);
        if ($version->status !== 'draft') {
            throw ValidationException::withMessages(['file' => 'Question assets can only be attached to a draft question version. Create a reviewed revision first.']);
        }

        $artifact = $this->storeAndScan($file, $actor, 'question_asset', (int) $version->getKey(), null);
        if ($artifact->scan_status === 'clean') {
            $content = $version->content;
            $assetIds = array_values(array_unique(array_map('intval', (array) ($content['asset_ids'] ?? []))));
            $assetIds[] = (int) $artifact->getKey();
            $version->forceFill(['content' => [...$content, 'asset_ids' => array_values(array_unique($assetIds))]])->save();
        }

        return $artifact;
    }

    public function uploadLearnerResponse(int $attemptId, int $questionVersionId, UploadedFile $file, int $expectedVersion, User $actor): QuizFileArtifact
    {
        $membership = $this->membership();
        abort_unless($membership->hasPermission('assessment.attempt.respond'), 403);
        $attempt = QuizAttempt::query()->where('membership_id', $membership->getKey())->findOrFail($attemptId);
        if ((int) $membership->user_id !== (int) $actor->getKey() || $attempt->status !== 'in_progress' || ($attempt->expires_at && $attempt->expires_at->isPast())) {
            abort(409, 'This attempt cannot accept a file response.');
        }
        $snapshot = collect($attempt->question_snapshot)->firstWhere('question_version_id', $questionVersionId);
        if (! $snapshot || ($snapshot['question_type'] ?? null) !== 'file_response') {
            throw new NotFoundHttpException;
        }

        $artifact = $this->storeAndScan($file, $actor, 'learner_response', $questionVersionId, $attemptId);
        if ($artifact->scan_status === 'clean') {
            $this->assessments->saveResponse($attemptId, $questionVersionId, ['artifact_id' => (int) $artifact->getKey()], $expectedVersion, $actor);
        }

        return $artifact;
    }

    /** @return array{string,string,string} */
    public function authorizedDownload(int $artifactId, User $actor): array
    {
        $artifact = QuizFileArtifact::query()->findOrFail($artifactId);
        abort_unless($artifact->scan_status === 'clean', 404);
        $membership = $this->membership();
        if ((int) $artifact->tenant_id !== (int) $membership->tenant_id || ! $this->canView($artifact, $membership, $actor)) {
            throw new NotFoundHttpException;
        }

        $expectedPattern = '/\Aassessments\/'.(int) $membership->tenant_id.'\/quarantine\/[a-f0-9-]{36}\.(pdf|jpg|png|webp|txt|doc|docx|odt)\z/iD';
        if (preg_match($expectedPattern, $artifact->private_path) !== 1 || ! Storage::disk('local')->exists($artifact->private_path)) {
            throw new NotFoundHttpException;
        }
        $absolutePath = Storage::disk('local')->path($artifact->private_path);
        $actualHash = hash_file('sha256', $absolutePath);
        if (! is_string($actualHash) || ! hash_equals($artifact->sha256, $actualHash)) {
            $artifact->forceFill(['scan_status' => 'infected', 'result_code' => 'content_hash_mismatch'])->save();
            $this->audit->record('assessment.artifact.access_revoked', $actor, (int) $membership->tenant_id, $artifact, ['artifact_id' => $artifact->getKey(), 'reason' => 'content_hash_mismatch']);
            throw new NotFoundHttpException;
        }

        $this->audit->record('assessment.artifact.downloaded', $actor, (int) $membership->tenant_id, $artifact, ['artifact_id' => $artifact->getKey(), 'purpose' => $artifact->purpose]);

        return [$absolutePath, $artifact->original_name, $artifact->mime_type];
    }

    /** @return array<int,list<array{id:int,name:string,url:string}>> */
    public function learnerAssets(QuizAttempt $attempt): array
    {
        $versionIds = collect($attempt->question_snapshot)->pluck('question_version_id')->map(fn ($id): int => (int) $id)->all();
        if ($versionIds === []) {
            return [];
        }

        return QuizFileArtifact::query()->where('purpose', 'question_asset')->where('scan_status', 'clean')->whereIn('question_version_id', $versionIds)
            ->get(['id', 'question_version_id', 'original_name'])->groupBy('question_version_id')
            ->map(fn ($artifacts): array => $artifacts->map(fn (QuizFileArtifact $artifact): array => [
                'id' => (int) $artifact->getKey(), 'name' => $artifact->original_name,
                'url' => route('tenant.assessments.artifacts.download', [$this->context->tenant()->slug, $artifact->getKey()]),
            ])->all())->all();
    }

    public function attachLearnerDownloadUrls(QuizAttempt $attempt): void
    {
        foreach ($attempt->responses as $response) {
            $payload = (array) ($response->response_payload ?? []);
            $artifactId = (int) ($payload['artifact_id'] ?? 0);
            if ($artifactId > 0) {
                $artifact = QuizFileArtifact::query()->whereKey($artifactId)->where('attempt_id', $attempt->getKey())->where('scan_status', 'clean')->first();
                if ($artifact) {
                    $response->setAttribute('file_artifact', ['name' => $artifact->original_name, 'url' => route('tenant.assessments.artifacts.download', [$this->context->tenant()->slug, $artifact->getKey()])]);
                }
            }
        }
    }

    private function storeAndScan(UploadedFile $file, User $actor, string $purpose, int $questionVersionId, ?int $attemptId): QuizFileArtifact
    {
        $mime = (string) $file->getMimeType();
        if (! isset(self::MIME_EXTENSIONS[$mime]) || ! $file->isValid() || (int) $file->getSize() < 1 || (int) $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Upload a supported document or image no larger than 10 MB.']);
        }
        $tenantId = (int) $this->membership()->tenant_id;
        $extension = self::MIME_EXTENSIONS[$mime];
        $filename = (string) Str::uuid().'.'.$extension;
        $directory = 'assessments/'.$tenantId.'/quarantine';
        $path = Storage::disk('local')->putFileAs($directory, $file, $filename);
        if (! is_string($path)) {
            throw new \RuntimeException('The assessment artifact could not be stored in private quarantine.');
        }
        $absolutePath = Storage::disk('local')->path($path);
        $hash = hash_file('sha256', $absolutePath);
        if (! is_string($hash)) {
            Storage::disk('local')->delete($path);
            throw new \RuntimeException('The assessment artifact integrity hash could not be calculated.');
        }
        $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', basename($file->getClientOriginalName())) ?: 'assessment-upload.'.$extension;
        $originalName = mb_substr($originalName, 0, 255);
        $artifact = DB::transaction(function () use ($tenantId, $questionVersionId, $attemptId, $purpose, $actor, $originalName, $mime, $file, $hash, $path): QuizFileArtifact {
            $artifact = QuizFileArtifact::query()->create([
                'question_version_id' => $questionVersionId, 'attempt_id' => $attemptId, 'purpose' => $purpose,
                'uploaded_by' => $actor->getKey(), 'original_name' => $originalName, 'mime_type' => $mime,
                'size_bytes' => (int) $file->getSize(), 'sha256' => $hash, 'private_path' => $path,
                'scan_status' => 'quarantined',
            ]);
            $this->audit->record('assessment.artifact.quarantined', $actor, $tenantId, $artifact, [
                'artifact_id' => $artifact->getKey(), 'purpose' => $purpose, 'mime_type' => $mime,
                'size_bytes' => (int) $file->getSize(), 'sha256' => $hash,
            ]);

            return $artifact;
        }, 3);

        $artifact->forceFill(['scan_status' => 'scanning', 'scanner' => $this->scanner->name()])->save();
        $scan = $this->scanner->scan($absolutePath);
        $status = match ($scan->status) {
            'clean' => 'clean', 'rejected' => 'infected', default => 'unavailable'
        };
        $artifact->forceFill([
            'scan_status' => $status, 'scanner' => $this->scanner->name(), 'signature_version' => $this->scanner->name(),
            'result_code' => $scan->code, 'scanned_at' => now(),
        ])->save();
        $this->audit->record('assessment.artifact.scanned', $actor, $tenantId, $artifact, [
            'artifact_id' => $artifact->getKey(), 'status' => $status, 'result_code' => $scan->code,
            'scanner' => $this->scanner->name(), 'signature_version' => $this->scanner->name(),
        ]);

        return $artifact->fresh();
    }

    private function canView(QuizFileArtifact $artifact, TenantMembership $membership, User $actor): bool
    {
        $questionVersionId = (int) $artifact->question_version_id;
        $isLearner = DB::table('quiz_attempt_responses as responses')->join('quiz_attempts as attempts', function ($join): void {
            $join->on('attempts.id', '=', 'responses.attempt_id')->on('attempts.tenant_id', '=', 'responses.tenant_id');
        })->where('responses.tenant_id', $membership->tenant_id)->where('responses.question_version_id', $questionVersionId)->where('attempts.membership_id', $membership->getKey())->exists();
        if ((int) $artifact->attempt_id > 0) {
            $isLearner = $isLearner && (int) $artifact->attempt_id === (int) DB::table('quiz_attempts')->where('tenant_id', $membership->tenant_id)->where('membership_id', $membership->getKey())->whereExists(function ($query) use ($artifact): void {
                $query->selectRaw('1')->from('quiz_attempt_responses')->whereColumn('quiz_attempt_responses.attempt_id', 'quiz_attempts.id')->where('quiz_attempt_responses.question_version_id', $artifact->question_version_id);
            })->where('id', $artifact->attempt_id)->value('id');
        }
        if ($isLearner) {
            return true;
        }

        if (! $membership->hasPermission($artifact->purpose === 'learner_response' ? 'assessment.grade.manage' : 'assessment.bank.manage')) {
            return false;
        }
        $version = QuizQuestionVersion::query()->with('question.bank')->find($questionVersionId);
        if (! $version || (int) $version->question?->bank?->institution_id !== (int) $membership->institution_id) {
            return false;
        }
        if ($artifact->purpose === 'question_asset') {
            return $membership->campus_id === null || $membership->hasPermission('assessment.bank.share') || (int) $version->question?->bank?->campus_id === (int) $membership->campus_id;
        }

        $sectionIds = DB::table('course_section_instructors')->where('tenant_id', $membership->tenant_id)->where('user_id', $actor->getKey())->pluck('section_id');

        return DB::table('quiz_attempts as attempts')->join('quiz_sections as quiz_sections', function ($join): void {
            $join->on('quiz_sections.quiz_id', '=', 'attempts.quiz_id')->on('quiz_sections.tenant_id', '=', 'attempts.tenant_id');
        })->where('attempts.tenant_id', $membership->tenant_id)->where('attempts.id', $artifact->attempt_id)->whereIn('quiz_sections.section_id', $sectionIds)->exists()
            || $membership->hasPermission('assessment.quiz.manage-all');
    }

    private function assertQuestionBankScope(QuizQuestionVersion $version, TenantMembership $membership): void
    {
        $bank = $version->question?->bank;
        if (! $bank || (int) $bank->institution_id !== (int) $membership->institution_id
            || ($membership->campus_id !== null && (int) $bank->campus_id !== (int) $membership->campus_id && ! $membership->hasPermission('assessment.bank.share'))) {
            throw new NotFoundHttpException;
        }
    }

    private function membership(): TenantMembership
    {
        return $this->context->membership();
    }
}
