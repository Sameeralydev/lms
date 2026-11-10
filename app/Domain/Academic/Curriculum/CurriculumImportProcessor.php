<?php

namespace App\Domain\Academic\Curriculum;

use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicCurriculumImportBatch;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CurriculumImportProcessor
{
    public function process(int $batchId): void
    {
        $batch = AcademicCurriculumImportBatch::query()->withoutGlobalScope('tenant')->find($batchId);
        if (! $batch || $batch->status === 'completed' || ! in_array($batch->status, ['queued', 'processing'], true)) {
            return;
        }

        $tenant = Tenant::query()->find($batch->tenant_id);
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $batch->tenant_id)
            ->where('institution_id', $batch->institution_id)
            ->where('user_id', $batch->requested_by_user_id)
            ->first();
        $actor = $membership ? User::query()->find($membership->user_id) : null;
        if (! $tenant || ! $membership || ! $actor || $membership->status !== 'active') {
            $this->markFailed($batchId, 'requester_unavailable');

            return;
        }

        $context = app(TenantContext::class);
        $context->set($tenant, $membership);
        try {
            if (! $membership->hasPermission('academic.curriculum.manage')) {
                $this->markFailed($batchId, 'requester_unauthorized');

                return;
            }

            DB::transaction(function () use ($batchId): void {
                $locked = AcademicCurriculumImportBatch::query()->lockForUpdate()->findOrFail($batchId);
                if ($locked->status === 'completed' || ! in_array($locked->status, ['queued', 'processing'], true)) {
                    return;
                }
                $locked->forceFill([
                    'status' => 'processing',
                    'attempt_count' => min((int) $locked->attempt_count + 1, 255),
                    'started_at' => $locked->started_at ?? now(),
                    'failure_code' => null,
                ])->save();
            }, 3);

            try {
                app(CurriculumImportService::class)->importBatch($batchId, $actor);
            } catch (ConflictHttpException) {
                $this->markFailed($batchId, 'draft_changed');
            }
        } finally {
            $context->clear();
        }
    }

    public function markFailed(int $batchId, string $failureCode): void
    {
        $query = AcademicCurriculumImportBatch::query()->withoutGlobalScope('tenant')
            ->whereKey($batchId)
            ->whereNotIn('status', ['completed']);
        $values = [
            'status' => 'failed',
            'failure_code' => in_array($failureCode, ['processing_failed', 'draft_changed', 'requester_unavailable', 'requester_unauthorized'], true)
                ? $failureCode
                : 'processing_failed',
            'updated_at' => now(),
        ];
        if ($failureCode !== 'processing_failed') {
            $values['payload'] = null;
        }
        $query->update($values);
    }
}
