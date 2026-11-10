<?php

namespace App\Domain\Sis;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\Institution;
use App\Models\SisPrivateDocumentVersion;
use App\Models\SisRegistrySetting;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class SisPrivateDocumentRetentionProcessor
{
    public function __construct(private readonly TenantContext $context, private readonly AuditRecorder $audit) {}

    /** @return array{archived:int,purged:int,skipped:int} */
    public function run(): array
    {
        $result = ['archived' => 0, 'purged' => 0, 'skipped' => 0];
        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $this->context->enterProvisioning($tenant);
            try {
                foreach (Institution::query()->orderBy('id')->get() as $institution) {
                    $settings = SisRegistrySetting::query()->where('institution_id', $institution->getKey())->first();
                    if (! $settings) {
                        continue;
                    }
                    if ($settings->document_archive_after_days !== null) {
                        $cutoff = now()->subDays((int) $settings->document_archive_after_days);
                        SisPrivateDocumentVersion::query()->whereHas('document.student', fn ($query) => $query->where('institution_id', $institution->getKey()))
                            ->where('storage_status', 'archived')->orderBy('id')->chunkById(100, function ($versions) use (&$result): void {
                                foreach ($versions as $version) {
                                    try {
                                        $this->cleanupArchivedSource($version);
                                    } catch (RuntimeException) {
                                        $result['skipped']++;
                                    }
                                }
                            });
                        SisPrivateDocumentVersion::query()->whereHas('document.student', fn ($query) => $query->where('institution_id', $institution->getKey()))
                            ->whereNotNull('expires_at')->where('storage_status', 'active')->where('expires_at', '<=', $cutoff)
                            ->whereExists(fn ($query) => $query->selectRaw('1')->from('sis_private_document_scan_events as scan')
                                ->whereColumn('scan.tenant_id', 'sis_private_document_versions.tenant_id')
                                ->whereColumn('scan.document_version_id', 'sis_private_document_versions.id')
                                ->where('scan.status', 'clean')
                                ->whereRaw('scan.attempt_number = (select max(last_scan.attempt_number) from sis_private_document_scan_events as last_scan where last_scan.tenant_id = scan.tenant_id and last_scan.document_version_id = scan.document_version_id)'))
                            ->orderBy('id')->chunkById(100, function ($versions) use (&$result): void {
                                foreach ($versions as $version) {
                                    try {
                                        if ($this->archive($version)) {
                                            $result['archived']++;
                                        }
                                    } catch (RuntimeException|QueryException) {
                                        $result['skipped']++;
                                    }
                                }
                            });
                    }

                    if ($settings->document_purge_after_days !== null && $settings->document_archive_after_days !== null) {
                        $cutoff = now()->subDays((int) $settings->document_purge_after_days);
                        SisPrivateDocumentVersion::query()->whereHas('document.student', fn ($query) => $query->where('institution_id', $institution->getKey()))
                            ->whereNotNull('expires_at')->whereIn('storage_status', ['archived', 'purging'])->where('expires_at', '<=', $cutoff)
                            ->orderBy('id')->chunkById(100, function ($versions) use (&$result): void {
                                foreach ($versions as $version) {
                                    try {
                                        if ($this->purge($version)) {
                                            $result['purged']++;
                                        }
                                    } catch (RuntimeException|QueryException) {
                                        $result['skipped']++;
                                    }
                                }
                            });
                    }
                }
            } finally {
                $this->context->clear();
            }
        }

        return $result;
    }

    private function archive(SisPrivateDocumentVersion $candidate): bool
    {
        $version = SisPrivateDocumentVersion::query()->lockForUpdate()->findOrFail($candidate->getKey());
        if ($version->storage_status !== 'active') {
            return false;
        }
        $tenantId = (int) $version->tenant_id;
        $document = $version->document()->firstOrFail();
        $studentId = (int) $document->student_id;
        $sourcePath = (string) $version->private_path;
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (! in_array($extension, ['pdf', 'jpg', 'png'], true)
            || preg_match('#\Asis/'.$tenantId.'/'.$studentId.'/[0-9a-f-]{36}\.(pdf|jpg|png)\z#i', $sourcePath) !== 1) {
            throw new RuntimeException('The SIS document path is outside its tenant private storage.');
        }
        $source = Storage::disk('local');
        $archive = Storage::disk('local_archive');
        if (! $source->exists($sourcePath) || ! hash_equals((string) $version->sha256, (string) hash_file('sha256', $source->path($sourcePath)))) {
            throw new RuntimeException('The SIS document source is missing or does not match its recorded digest.');
        }
        $archivePath = 'sis-archive/'.$tenantId.'/'.$studentId.'/'.$version->getKey().'-'.$version->sha256.'.'.$extension;
        if (! $archive->exists($archivePath)) {
            $input = $source->readStream($sourcePath);
            if (! is_resource($input)) {
                throw new RuntimeException('The SIS document could not be streamed to private archive storage.');
            }
            try {
                $written = $archive->writeStream($archivePath, $input);
            } finally {
                fclose($input);
            }
            if (! $written) {
                throw new RuntimeException('The SIS document archive write failed.');
            }
        }
        if (! $archive->exists($archivePath) || ! hash_equals((string) $version->sha256, (string) hash_file('sha256', $archive->path($archivePath)))) {
            throw new RuntimeException('The archived SIS document failed checksum verification.');
        }

        DB::transaction(function () use ($version, $archivePath, $tenantId): void {
            $locked = SisPrivateDocumentVersion::query()->lockForUpdate()->findOrFail($version->getKey());
            if ($locked->storage_status !== 'active') {
                return;
            }
            DB::table('sis_private_document_versions')->where('tenant_id', $tenantId)->where('id', $locked->getKey())->update([
                'storage_status' => 'archived', 'archived_path' => $archivePath, 'archived_at' => now(), 'updated_at' => now(),
            ]);
            $this->recordRetentionEvent($tenantId, (int) $locked->getKey(), 'archived', 'policy_archive', $locked->sha256, $archivePath);
            $this->audit->record('sis.student.document.archived', null, $tenantId, $locked, [
                'document_id' => $locked->document_id, 'version' => $locked->version_number, 'file_sha256' => $locked->sha256,
            ]);
        }, 3);

        // A crash after the database transition leaves only a private duplicate; the next run retries cleanup.
        $source->delete($sourcePath);

        return true;
    }

    private function purge(SisPrivateDocumentVersion $candidate): bool
    {
        $version = SisPrivateDocumentVersion::query()->lockForUpdate()->findOrFail($candidate->getKey());
        if ($version->storage_status === 'purged') {
            return false;
        }
        $tenantId = (int) $version->tenant_id;
        $path = (string) $version->archived_path;
        if ($path === '' || preg_match('#\Asis-archive/'.$tenantId.'/[0-9]+/[0-9]+-[a-f0-9]{64}\.(pdf|jpg|png)\z#i', $path) !== 1) {
            throw new RuntimeException('The archived SIS document path is outside its tenant archive.');
        }
        $archive = Storage::disk('local_archive');
        if ($archive->exists($path)) {
            if (! hash_equals((string) $version->sha256, (string) hash_file('sha256', $archive->path($path)))) {
                throw new RuntimeException('The archived SIS document is missing or does not match its recorded digest.');
            }
            if ($version->storage_status === 'archived') {
                DB::transaction(function () use ($version, $tenantId): void {
                    $locked = SisPrivateDocumentVersion::query()->lockForUpdate()->findOrFail($version->getKey());
                    if ($locked->storage_status === 'archived') {
                        DB::table('sis_private_document_versions')->where('tenant_id', $tenantId)->where('id', $locked->getKey())
                            ->where('storage_status', 'archived')->update(['storage_status' => 'purging', 'updated_at' => now()]);
                    }
                }, 3);
            }
        } elseif ($version->storage_status === 'archived') {
            throw new RuntimeException('The archived SIS document is missing before an authorized purge transition.');
        } elseif ($version->storage_status !== 'purging') {
            return false;
        }

        if ($archive->exists($path) && ! $archive->delete($path)) {
            throw new RuntimeException('The archived SIS document could not be purged.');
        }

        DB::transaction(function () use ($version, $path, $tenantId): void {
            $locked = SisPrivateDocumentVersion::query()->lockForUpdate()->findOrFail($version->getKey());
            if ($locked->storage_status !== 'purging') {
                return;
            }
            DB::table('sis_private_document_versions')->where('tenant_id', $tenantId)->where('id', $locked->getKey())->update([
                'storage_status' => 'purged', 'archived_path' => null, 'purged_at' => now(), 'updated_at' => now(),
            ]);
            $this->recordRetentionEvent($tenantId, (int) $locked->getKey(), 'purged', 'policy_purge', $locked->sha256, $path);
            $this->audit->record('sis.student.document.purged', null, $tenantId, $locked, [
                'document_id' => $locked->document_id, 'version' => $locked->version_number, 'file_sha256' => $locked->sha256,
            ]);
        }, 3);

        return true;
    }

    private function cleanupArchivedSource(SisPrivateDocumentVersion $version): void
    {
        $tenantId = (int) $version->tenant_id;
        $studentId = (int) $version->document()->value('student_id');
        $sourcePath = (string) $version->private_path;
        if (preg_match('#\Asis/'.$tenantId.'/'.$studentId.'/[0-9a-f-]{36}\.(pdf|jpg|png)\z#i', $sourcePath) !== 1) {
            throw new RuntimeException('The duplicate SIS document path is outside tenant private storage.');
        }
        $source = Storage::disk('local');
        if (! $source->exists($sourcePath)) {
            return;
        }
        if (! hash_equals((string) $version->sha256, (string) hash_file('sha256', $source->path($sourcePath)))) {
            throw new RuntimeException('The duplicate SIS document does not match its immutable digest.');
        }
        $source->delete($sourcePath);
    }

    private function recordRetentionEvent(int $tenantId, int $versionId, string $action, string $reason, string $sha256, string $path): void
    {
        DB::table('sis_private_document_retention_events')->insertOrIgnore([
            'tenant_id' => $tenantId, 'document_version_id' => $versionId, 'action' => $action,
            'reason_code' => $reason, 'file_sha256' => $sha256, 'storage_path' => $path,
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
