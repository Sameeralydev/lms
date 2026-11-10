<?php

namespace App\Domain\Learning;

use App\Domain\Audit\AuditRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class CourseDeliveryMediaRetentionProcessor
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array{archived:int,purged:int,skipped:int} */
    public function run(): array
    {
        $counts = ['archived' => 0, 'purged' => 0, 'skipped' => 0];
        $archiveDays = max(1, (int) config('learning.media_archive_after_days', 365));
        $purgeDays = max($archiveDays + 1, (int) config('learning.media_purge_after_days', 2555));
        $archiveCutoff = now()->subDays($archiveDays);
        $purgeCutoff = now()->subDays($purgeDays);

        DB::table('course_delivery_blocks')->where('block_type', 'media_asset')->orderBy('id')->chunkById(100, function ($assets) use (&$counts, $archiveCutoff, $purgeCutoff): void {
            foreach ($assets as $asset) {
                $content = json_decode((string) $asset->content, true);
                if (! is_array($content)) {
                    $counts['skipped']++;

                    continue;
                }
                try {
                    $this->cleanupArchivedSource($asset, $content);
                    if (($content['storage_status'] ?? 'active') === 'active'
                        && in_array(($content['scan_status'] ?? null), ['clean', 'revoked'], true)
                        && CarbonDate::olderThan($asset->created_at, $archiveCutoff)) {
                        $content = $this->archive($asset, $content);
                        $counts['archived']++;
                    }
                    if (($content['storage_status'] ?? null) === 'archived'
                        && CarbonDate::olderThan($content['archived_at'] ?? null, $purgeCutoff)) {
                        if ($this->hasPublishedReference((int) $asset->tenant_id, (int) $asset->lesson_id, (int) $asset->id)) {
                            $counts['skipped']++;

                            continue;
                        }
                        $this->purge($asset, $content);
                        $counts['purged']++;
                    }
                } catch (RuntimeException) {
                    $counts['skipped']++;
                }
            }
        });

        return $counts;
    }

    /** @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    private function archive(object $asset, array $content): array
    {
        $sourcePath = $content['private_path'] ?? null;
        $sha256 = $content['sha256'] ?? null;
        $extension = is_string($sourcePath) ? strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) : '';
        if (! is_string($sourcePath) || str_contains($sourcePath, '..') || str_contains($sourcePath, '\\')
            || ! in_array($extension, ['pdf', 'jpg', 'png', 'webp', 'mp3', 'ogg', 'wav', 'mp4', 'webm', 'txt'], true)
            || ! is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1) {
            throw new RuntimeException('Learning media source path or digest is invalid.');
        }
        $source = Storage::disk('local');
        $archive = Storage::disk('local_archive');
        if (! $source->exists($sourcePath)) {
            throw new RuntimeException('Learning media source is missing.');
        }
        if (! hash_equals($sha256, (string) hash_file('sha256', $source->path($sourcePath)))) {
            throw new RuntimeException('Learning media source digest changed.');
        }
        $archivePath = 'learning-media/'.$asset->tenant_id.'/'.$asset->lesson_id.'/'.$asset->id.'-'.$sha256.'.'.$extension;
        if (! $archive->exists($archivePath)) {
            $input = $source->readStream($sourcePath);
            if (! is_resource($input)) {
                throw new RuntimeException('Learning media source could not be streamed to archive storage.');
            }
            try {
                if (! $archive->writeStream($archivePath, $input)) {
                    throw new RuntimeException('Learning media archive write failed.');
                }
            } finally {
                fclose($input);
            }
        }
        if (! hash_equals($sha256, (string) hash_file('sha256', $archive->path($archivePath)))) {
            throw new RuntimeException('Learning media archive digest verification failed.');
        }
        $content['storage_status'] = 'archived';
        $content['archived_path'] = $archivePath;
        $content['archived_at'] = now()->toIso8601String();
        DB::table('course_delivery_blocks')->where('tenant_id', $asset->tenant_id)->where('id', $asset->id)->update([
            'content' => json_encode($content, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
        $this->audit->record('learning.delivery.media.archived', null, (int) $asset->tenant_id, null, [
            'asset_id' => (int) $asset->id, 'lesson_id' => (int) $asset->lesson_id, 'asset_version' => (int) ($content['asset_version'] ?? 1),
        ]);
        $source->delete($sourcePath);

        return $content;
    }

    /** @param array<string,mixed> $content */
    private function purge(object $asset, array $content): void
    {
        $path = $content['archived_path'] ?? null;
        $sha256 = $content['sha256'] ?? null;
        if (! is_string($path) || ! is_string($sha256)
            || $path !== 'learning-media/'.$asset->tenant_id.'/'.$asset->lesson_id.'/'.$asset->id.'-'.$sha256.'.'.strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            throw new RuntimeException('Learning media archive path is outside its owned asset namespace.');
        }
        $archive = Storage::disk('local_archive');
        if ($archive->exists($path)) {
            if (! hash_equals($sha256, (string) hash_file('sha256', $archive->path($path)))) {
                throw new RuntimeException('Learning media archive digest changed before purge.');
            }
            if (! $archive->delete($path)) {
                throw new RuntimeException('Learning media archive file could not be purged.');
            }
        }
        $content['storage_status'] = 'purged';
        $content['scan_status'] = 'purged';
        $content['archived_path'] = null;
        $content['purged_at'] = now()->toIso8601String();
        DB::table('course_delivery_blocks')->where('tenant_id', $asset->tenant_id)->where('id', $asset->id)->update([
            'content' => json_encode($content, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
        $this->audit->record('learning.delivery.media.purged', null, (int) $asset->tenant_id, null, [
            'asset_id' => (int) $asset->id, 'lesson_id' => (int) $asset->lesson_id, 'asset_version' => (int) ($content['asset_version'] ?? 1),
        ]);
    }

    /** @param array<string,mixed> $content */
    private function cleanupArchivedSource(object $asset, array $content): void
    {
        if (($content['storage_status'] ?? null) !== 'archived') {
            return;
        }
        $sourcePath = $content['private_path'] ?? null;
        if (! is_string($sourcePath) || str_contains($sourcePath, '..') || str_contains($sourcePath, '\\')) {
            throw new RuntimeException('Archived media duplicate path is invalid.');
        }
        $source = Storage::disk('local');
        if ($source->exists($sourcePath)) {
            $sha256 = (string) ($content['sha256'] ?? '');
            if ($sha256 === '' || ! hash_equals($sha256, (string) hash_file('sha256', $source->path($sourcePath)))) {
                throw new RuntimeException('Archived media duplicate does not match its immutable digest.');
            }
            $source->delete($sourcePath);
        }
    }

    private function hasPublishedReference(int $tenantId, int $lessonId, int $assetId): bool
    {
        foreach (DB::table('course_delivery_lesson_revisions')->where('tenant_id', $tenantId)->where('lesson_id', $lessonId)->orderBy('id')->cursor() as $revision) {
            $snapshot = json_decode((string) $revision->lesson_snapshot, true);
            foreach (is_array($snapshot['blocks'] ?? null) ? $snapshot['blocks'] : [] as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'media_asset'
                    && (int) ($block['content']['asset_id'] ?? 0) === $assetId) {
                    return true;
                }
            }
        }

        return false;
    }
}

/** Small helper keeps date parsing fail-closed for malformed rows. */
final class CarbonDate
{
    public static function olderThan(?string $value, Carbon $cutoff): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        try {
            return Carbon::parse($value)->lessThanOrEqualTo($cutoff);
        } catch (\Throwable) {
            return false;
        }
    }
}
