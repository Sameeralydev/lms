<?php

namespace App\Domain\Learning;

use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\CourseDeliveryBlock;
use App\Models\CourseDeliveryLesson;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Handles private learning media uploads and fail-closed malware scanning. */
final class CourseDeliveryMediaWorkflow
{
    /** @var array<string,array{extension:string,kind:string}> */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => ['extension' => 'pdf', 'kind' => 'document'],
        'image/jpeg' => ['extension' => 'jpg', 'kind' => 'image'],
        'image/png' => ['extension' => 'png', 'kind' => 'image'],
        'image/webp' => ['extension' => 'webp', 'kind' => 'image'],
        'audio/mpeg' => ['extension' => 'mp3', 'kind' => 'audio'],
        'audio/ogg' => ['extension' => 'ogg', 'kind' => 'audio'],
        'audio/wav' => ['extension' => 'wav', 'kind' => 'audio'],
        'audio/x-wav' => ['extension' => 'wav', 'kind' => 'audio'],
        'video/mp4' => ['extension' => 'mp4', 'kind' => 'video'],
        'video/webm' => ['extension' => 'webm', 'kind' => 'video'],
        'text/plain' => ['extension' => 'txt', 'kind' => 'resource'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly CourseDeliveryWorkflow $delivery,
        private readonly AdmissionDocumentScanner $scanner,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array{alt_text:?string,license_label:string,attribution:?string,transcript:?string} $metadata */
    public function upload(User $actor, CourseDeliveryLesson $lesson, UploadedFile $file, array $metadata, ?int $replacesAssetId = null): CourseDeliveryBlock
    {
        $lesson = CourseDeliveryLesson::query()->with('workspace')->findOrFail($lesson->getKey());
        $workspace = $lesson->workspace;
        $this->delivery->assertCanManage($actor, $workspace);
        abort_unless(in_array($workspace->status, ['draft', 'published'], true)
            && in_array($lesson->status, ['draft', 'changes_requested'], true), 409, 'Media can only be added to an editable lesson draft.');

        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $fileType = self::ALLOWED_MIME_TYPES[$mime] ?? null;
        if ($fileType === null) {
            abort(422, 'This file type is not supported for learning media.');
        }
        if ($fileType['kind'] === 'image' && trim((string) ($metadata['alt_text'] ?? '')) === '') {
            abort(422, 'Provide alternative text for an image.');
        }

        $replacedAsset = $replacesAssetId === null ? null : $lesson->blocks()
            ->whereKey($replacesAssetId)->where('block_type', 'media_asset')->firstOrFail();
        if ($replacedAsset !== null) {
            $hasNewerVersion = $lesson->blocks()->where('block_type', 'media_asset')->get(['content'])
                ->contains(fn (CourseDeliveryBlock $candidate): bool => (int) ($candidate->content['replaces_asset_id'] ?? 0) === (int) $replacedAsset->getKey());
            abort_unless(! $hasNewerVersion, 409, 'Replace the latest media version to keep the version history linear.');
        }
        $previousVersion = (int) ($replacedAsset?->content['asset_version'] ?? 1);

        $tenant = $this->context->tenant();
        $reference = (string) Str::uuid();
        $directory = 'learning/'.$tenant->public_id.'/'.$workspace->public_id.'/lessons/'.$lesson->getKey();
        $quarantinePath = $directory.'/quarantine/'.$reference.'.'.$fileType['extension'];
        $stored = $file->storeAs(dirname($quarantinePath), basename($quarantinePath), 'local');
        if (! is_string($stored) || $stored !== $quarantinePath) {
            throw new RuntimeException('The learning media file could not be placed in private quarantine.');
        }

        $safeName = basename((string) $file->getClientOriginalName());
        $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $safeName) ?: 'learning-resource.'.$fileType['extension'];
        $content = [
            'asset_reference' => $reference,
            'asset_version' => $previousVersion + ($replacedAsset === null ? 0 : 1),
            'replaces_asset_id' => $replacedAsset?->getKey(),
            'private_path' => $quarantinePath,
            'original_name' => mb_substr($safeName, 0, 180),
            'mime_type' => $mime,
            'media_type' => $fileType['kind'],
            'size_bytes' => (int) ($file->getSize() ?: 0),
            'sha256' => hash_file('sha256', Storage::disk('local')->path($quarantinePath)) ?: null,
            'storage_status' => 'active',
            'scan_status' => 'quarantined',
            'scanner' => null,
            'scanned_at' => null,
            'alt_text' => $metadata['alt_text'] ?? null,
            'license_label' => $metadata['license_label'],
            'attribution' => $metadata['attribution'] ?? null,
            'transcript' => $metadata['transcript'] ?? null,
        ];

        try {
            $asset = DB::transaction(function () use ($actor, $lesson, $content): CourseDeliveryBlock {
                $locked = CourseDeliveryLesson::query()->with('workspace')->lockForUpdate()->findOrFail($lesson->getKey());
                $this->delivery->assertCanManage($actor, $locked->workspace);
                abort_unless(in_array($locked->workspace->status, ['draft', 'published'], true)
                    && in_array($locked->status, ['draft', 'changes_requested'], true), 409);
                $sortOrder = (int) CourseDeliveryBlock::query()->where('lesson_id', $locked->getKey())->max('sort_order') + 1;

                return CourseDeliveryBlock::query()->create([
                    'lesson_id' => $locked->getKey(), 'sort_order' => $sortOrder,
                    'block_type' => 'media_asset', 'content' => $content,
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($quarantinePath);
            throw $exception;
        }

        $absolutePath = Storage::disk('local')->path($quarantinePath);
        $status = 'unavailable';
        try {
            $result = $this->scanner->scan($absolutePath);
            $status = $result->status;
        } catch (Throwable) {
        }
        $content['scanner'] = $this->scanner->name();
        $content['scanned_at'] = now()->toIso8601String();

        if ($status === 'clean') {
            $cleanPath = $directory.'/clean/'.$reference.'.'.$fileType['extension'];
            if (Storage::disk('local')->move($quarantinePath, $cleanPath)) {
                $content['private_path'] = $cleanPath;
                $content['scan_status'] = 'clean';
            } else {
                $content['scan_status'] = 'quarantined';
                $status = 'unavailable';
            }
        } elseif ($status === 'rejected') {
            $content['scan_status'] = 'rejected';
        } else {
            $content['scan_status'] = 'quarantined';
            $status = 'unavailable';
        }

        DB::transaction(function () use ($actor, $asset, $content, $status, $mime): void {
            $lockedAsset = CourseDeliveryBlock::query()->where('block_type', 'media_asset')->lockForUpdate()->findOrFail($asset->getKey());
            $lockedAsset->forceFill(['content' => $content])->save();
            $this->audit->record('learning.delivery.media.scanned', $actor, (int) $lockedAsset->tenant_id, $lockedAsset, [
                'scan_status' => $status, 'scanner' => $content['scanner'], 'mime_type' => $mime,
                'size_bytes' => $content['size_bytes'], 'sha256' => $content['sha256'],
            ]);
            if ($content['replaces_asset_id'] !== null) {
                $this->audit->record('learning.delivery.media.version_created', $actor, (int) $lockedAsset->tenant_id, $lockedAsset, [
                    'replaces_asset_id' => (int) $content['replaces_asset_id'],
                    'asset_version' => (int) $content['asset_version'], 'scan_status' => $status,
                ]);
            }
        });

        return $asset->refresh();
    }

    /** @param array<string, mixed> $snapshotMetadata */
    public function reuseTemplateAsset(User $actor, int $sourceAssetId, int $expectedSourceWorkspaceId, CourseDeliveryLesson $destinationLesson, array $snapshotMetadata): CourseDeliveryBlock
    {
        $source = CourseDeliveryBlock::query()->where('block_type', 'media_asset')->findOrFail($sourceAssetId);
        $sourceLesson = CourseDeliveryLesson::query()->with('workspace')->findOrFail($source->lesson_id);
        abort_unless((int) $sourceLesson->workspace_id === $expectedSourceWorkspaceId, 404);
        $this->delivery->assertCanManage($actor, $destinationLesson->workspace);
        $sourceContent = $source->content;
        $path = $sourceContent['private_path'] ?? null;
        $tenant = $this->context->tenant();
        $expectedPrefix = 'learning/'.$tenant->public_id.'/';
        if (($sourceContent['scan_status'] ?? null) !== 'clean' || ! is_string($path)
            || ! str_starts_with($path, $expectedPrefix) || str_contains($path, '..') || str_contains($path, '\\')
            || ! Storage::disk('local')->exists($path)) {
            throw ValidationException::withMessages(['template' => 'A referenced media asset is not verified clean and available for reuse.']);
        }
        $absolute = Storage::disk('local')->path($path);
        $actualHash = hash_file('sha256', $absolute);
        if (! is_string($actualHash) || ! is_string($sourceContent['sha256'] ?? null)
            || ! hash_equals($sourceContent['sha256'], $actualHash)) {
            throw ValidationException::withMessages(['template' => 'A referenced media asset changed after scanning and cannot be reused.']);
        }
        $file = new UploadedFile($absolute, (string) ($snapshotMetadata['original_name'] ?? 'course-resource'), (string) ($snapshotMetadata['mime_type'] ?? 'application/octet-stream'), UPLOAD_ERR_OK, true);
        $copy = $this->upload($actor, $destinationLesson, $file, [
            'alt_text' => is_string($snapshotMetadata['alt_text'] ?? null) ? $snapshotMetadata['alt_text'] : null,
            'license_label' => is_string($snapshotMetadata['license_label'] ?? null) ? $snapshotMetadata['license_label'] : 'Reuse from institutional course template',
            'attribution' => is_string($snapshotMetadata['attribution'] ?? null) ? $snapshotMetadata['attribution'] : null,
            'transcript' => is_string($snapshotMetadata['transcript'] ?? null) ? $snapshotMetadata['transcript'] : null,
        ]);
        if (($copy->content['scan_status'] ?? null) === 'rejected') {
            throw ValidationException::withMessages(['template' => 'The scanner rejected a template asset; no usable asset was copied.']);
        }

        return $copy;
    }

    public function revoke(User $actor, CourseDeliveryLesson $lesson, int $assetId): void
    {
        $lesson = CourseDeliveryLesson::query()->with('workspace')->findOrFail($lesson->getKey());
        $this->delivery->assertCanManage($actor, $lesson->workspace);
        DB::transaction(function () use ($actor, $lesson, $assetId): void {
            $asset = $lesson->blocks()->whereKey($assetId)->where('block_type', 'media_asset')->lockForUpdate()->firstOrFail();
            $content = $asset->content;
            if (($content['scan_status'] ?? null) === 'revoked') {
                return;
            }
            $content['scan_status'] = 'revoked';
            $content['revoked_at'] = now()->toIso8601String();
            $asset->forceFill(['content' => $content])->save();
            $this->audit->record('learning.delivery.media.access_revoked', $actor, (int) $asset->tenant_id, $asset, [
                'lesson_id' => (int) $lesson->getKey(), 'asset_version' => (int) ($content['asset_version'] ?? 1),
            ]);
        }, 3);
    }
}
