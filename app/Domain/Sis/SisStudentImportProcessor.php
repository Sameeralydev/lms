<?php

namespace App\Domain\Sis;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\Institution;
use App\Models\SisStudent;
use App\Models\SisStudentImportBatch;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class SisStudentImportProcessor
{
    public function process(int $batchId): void
    {
        $candidate = SisStudentImportBatch::query()->withoutGlobalScope('tenant')->find($batchId);
        if (! $candidate) {
            return;
        }

        if (in_array($candidate->status, ['completed', 'completed_with_errors'], true)) {
            if (preg_match('/^[0-9a-f-]{36}$/i', (string) $candidate->batch_reference) === 1) {
                $this->deletePrivateFile('sis-imports/'.(int) $candidate->tenant_id.'/'.$candidate->batch_reference.'.csv');
            }

            return;
        }
        if (! in_array($candidate->status, ['queued', 'processing'], true)) {
            return;
        }

        $tenant = Tenant::query()->find($candidate->tenant_id);
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $candidate->tenant_id)->where('institution_id', $candidate->institution_id)
            ->where('user_id', $candidate->requested_by)->first();
        $actor = $membership ? User::query()->find($membership->user_id) : null;
        $context = app(TenantContext::class);

        if (! $tenant || ! $membership || ! $actor) {
            $this->markFailed($batchId);

            return;
        }

        $context->set($tenant, $membership);
        $privatePath = null;
        try {
            $privatePath = $this->assertPrivatePath((int) $tenant->getKey(), (string) $candidate->batch_reference, $candidate->source_path);
            $disk = Storage::disk('local');
            if (! $disk->exists($privatePath) || hash_file('sha256', $disk->path($privatePath)) !== $candidate->file_sha256) {
                throw new RuntimeException('The private student import file did not match its preview digest.');
            }

            $mapping = $candidate->safe_report['mapping'] ?? [];
            if (! is_array($mapping)) {
                throw new RuntimeException('The student import mapping is invalid.');
            }
            $csv = app(SisStudentCsvImport::class);
            $headers = $csv->headers($disk->path($privatePath));
            if (array_diff(SisStudentCsvImport::REQUIRED_FIELDS, array_keys($mapping)) !== []) {
                throw new RuntimeException('The student import mapping is incomplete.');
            }
            foreach ($mapping as $field => $header) {
                if (! array_key_exists($field, SisStudentCsvImport::FIELD_LABELS) || ! in_array($header, $headers, true)) {
                    throw new RuntimeException('The student import mapping no longer matches the uploaded columns.');
                }
            }

            SisStudentImportBatch::query()->withoutGlobalScope('tenant')->whereKey($batchId)
                ->where('status', 'queued')->update(['status' => 'processing']);

            $chunk = [];
            foreach ($csv->streamRows($disk->path($privatePath), $headers, $mapping) as $row) {
                $chunk[] = $row;
                if (count($chunk) === SisStudentCsvImport::CHUNK_SIZE) {
                    $this->processChunk($batchId, $tenant, $membership, $actor, $chunk);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                $this->processChunk($batchId, $tenant, $membership, $actor, $chunk);
            }

            DB::transaction(function () use ($batchId, $tenant, $membership, $actor, $context, $mapping): void {
                $lockedTenant = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
                $lockedMembership = TenantMembership::query()->withoutGlobalScope('tenant')->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();
                $batch = SisStudentImportBatch::query()->lockForUpdate()->findOrFail($batchId);
                if (in_array($batch->status, ['completed', 'completed_with_errors'], true)) {
                    return;
                }
                $this->assertAuthorized($lockedTenant, $lockedMembership, $context);
                $processed = (int) SisStudent::query()->where('import_batch_id', $batchId)->count();
                $invalid = (int) DB::table('sis_student_import_row_errors')->where('tenant_id', $tenant->getKey())->where('batch_id', $batchId)->count();
                $errors = DB::table('sis_student_import_row_errors')->where('tenant_id', $tenant->getKey())->where('batch_id', $batchId)
                    ->orderBy('source_row')->limit(SisStudentCsvImport::MAX_PREVIEW_ERRORS)->get(['source_row', 'errors'])
                    ->map(static fn (object $error): array => ['row' => (int) $error->source_row, 'errors' => json_decode((string) $error->errors, true) ?: []])->all();
                $status = $invalid === 0 ? 'completed' : 'completed_with_errors';
                $batch->forceFill([
                    'status' => $status, 'processed_count' => $processed, 'valid_count' => $processed,
                    'invalid_count' => $invalid, 'source_path' => null,
                    'safe_report' => ['mapping' => $mapping, 'errors' => $errors, 'errors_truncated' => $invalid > count($errors)],
                ])->save();
                app(AuditRecorder::class)->record($status === 'completed' ? 'sis.student.import_completed' : 'sis.student.import_completed_with_errors', $actor, (int) $lockedTenant->getKey(), $batch, [
                    'row_count' => (int) $batch->row_count, 'processed_count' => $processed,
                    'invalid_count' => $invalid, 'file_sha256' => $batch->file_sha256,
                ]);
            }, 3);
        } catch (Throwable $exception) {
            if ($this->isAuthorizationFailure($exception)) {
                $this->markFailed($batchId);
                $this->deletePrivateFile($privatePath);

                return;
            }

            throw $exception;
        } finally {
            $context->clear();
        }

        $this->deletePrivateFile($privatePath);
    }

    /** @param list<array<string, string|null|int>> $sourceRows */
    private function processChunk(int $batchId, Tenant $tenant, TenantMembership $membership, User $actor, array $sourceRows): void
    {
        $context = app(TenantContext::class);
        DB::transaction(function () use ($batchId, $tenant, $membership, $actor, $sourceRows, $context): void {
            $lockedTenant = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            $lockedMembership = TenantMembership::query()->withoutGlobalScope('tenant')->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();
            $batch = SisStudentImportBatch::query()->lockForUpdate()->findOrFail($batchId);
            if (in_array($batch->status, ['completed', 'completed_with_errors'], true)) {
                return;
            }
            $this->assertAuthorized($lockedTenant, $lockedMembership, $context);
            $institution = Institution::query()->whereKey($batch->institution_id)->firstOrFail();
            if ((int) $institution->tenant_id !== (int) $lockedTenant->getKey()) {
                throw new RuntimeException('The student import institution is outside its tenant.');
            }

            $sourceNumbers = array_map(static fn (array $row): int => (int) $row['source_row'], $sourceRows);
            $alreadyProcessed = SisStudent::query()->where('import_batch_id', $batchId)->whereIn('import_row_number', $sourceNumbers)
                ->pluck('import_row_number')->map(static fn ($row): int => (int) $row)->all();
            $processedSet = array_fill_keys($alreadyProcessed, true);
            $pending = array_values(array_filter($sourceRows, static fn (array $row): bool => ! isset($processedSet[(int) $row['source_row']])));
            $seenReferences = [];
            $validation = app(SisStudentCsvImport::class)->validateChunk($pending, (int) $institution->getKey(), $seenReferences);
            foreach ($validation['errors'] as $error) {
                $this->recordImportRowError((int) $batch->tenant_id, $batchId, $error);
            }

            foreach ($validation['rows'] as $row) {
                $rowNumber = (int) $row['source_row'];
                unset($row['source_row']);
                try {
                    app(SisStudentRegistry::class)->create($row, $actor, $batchId, $rowNumber);
                } catch (QueryException $exception) {
                    if (SisStudent::query()->where('import_batch_id', $batchId)->where('import_row_number', $rowNumber)->exists()) {
                        continue;
                    }
                    $reference = trim((string) ($row['registration_reference'] ?? ''));
                    $referenceExists = $reference !== '' && SisStudent::query()->where('institution_id', $institution->getKey())
                        ->where('registration_reference', $reference)->exists();
                    if (! $referenceExists) {
                        throw $exception;
                    }
                    $this->recordImportRowError((int) $batch->tenant_id, $batchId, ['row' => $rowNumber, 'errors' => ['registration_reference is duplicated']]);
                }
            }

            $batch->forceFill([
                'status' => 'processing',
                'processed_count' => SisStudent::query()->where('import_batch_id', $batchId)->count(),
                'valid_count' => SisStudent::query()->where('import_batch_id', $batchId)->count(),
                'invalid_count' => DB::table('sis_student_import_row_errors')->where('tenant_id', $batch->tenant_id)->where('batch_id', $batchId)->count(),
            ])->save();
        }, 3);
    }

    private function assertAuthorized(Tenant $tenant, TenantMembership $membership, TenantContext $context): void
    {
        if ($tenant->status !== 'active' || $membership->status !== 'active') {
            throw new RuntimeException('The tenant membership is no longer active.');
        }
        $context->set($tenant, $membership);
        if (! $membership->hasPermission('sis.import')) {
            throw new RuntimeException('The requesting membership is no longer authorized to import students.');
        }
    }

    public function markFailed(int $batchId): void
    {
        $batch = SisStudentImportBatch::query()->withoutGlobalScope('tenant')->find($batchId);
        if (! $batch || in_array($batch->status, ['completed', 'completed_with_errors'], true)) {
            return;
        }
        $path = $batch->source_path;
        $batch->forceFill(['status' => 'failed', 'source_path' => null])->save();
        $this->deletePrivateFile(is_string($path) ? $path : null);
    }

    private function isAuthorizationFailure(Throwable $exception): bool
    {
        return $exception instanceof RuntimeException
            && in_array($exception->getMessage(), [
                'The tenant membership is no longer active.',
                'The requesting membership is no longer authorized to import students.',
            ], true);
    }

    private function assertPrivatePath(int $tenantId, string $batchReference, mixed $path): string
    {
        $expected = 'sis-imports/'.$tenantId.'/'.$batchReference.'.csv';
        if (! is_string($path) || $path !== $expected || preg_match('/^[0-9a-f-]{36}$/i', $batchReference) !== 1) {
            throw new RuntimeException('The student import source path is outside its private tenant quarantine.');
        }

        return $expected;
    }

    private function deletePrivateFile(?string $path): void
    {
        if ($path !== null && preg_match('#\Asis-imports/[0-9]+/[0-9a-f-]{36}\.csv\z#i', $path) === 1) {
            Storage::disk('local')->delete($path);
        }
    }

    /** @param array{row:int,errors:list<string>} $error */
    private function recordImportRowError(int $tenantId, int $batchId, array $error): int
    {
        return DB::table('sis_student_import_row_errors')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'batch_id' => $batchId,
            'source_row' => (int) $error['row'],
            'errors' => json_encode($error['errors'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
