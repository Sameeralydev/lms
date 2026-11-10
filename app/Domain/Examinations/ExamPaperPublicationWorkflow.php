<?php

namespace App\Domain\Examinations;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExamPaper;
use App\Models\ExamPaperReview;
use App\Models\ExamPaperVersion;
use App\Models\ExamSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ExamPaperPublicationWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AcademicAccess $access,
        private readonly AuditRecorder $audit,
    ) {}

    public function publish(int $versionId, User $actor): ExamPaperVersion
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('exams.paper.publish'), 403);
        $tenantId = (int) $membership->tenant_id;

        return DB::transaction(function () use ($versionId, $actor, $tenantId): ExamPaperVersion {
            $version = ExamPaperVersion::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($versionId);
            $paper = ExamPaper::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($version->paper_id);
            $session = ExamSession::query()->with('cycle')->where('tenant_id', $tenantId)->findOrFail($paper->session_id);
            $this->access->assertCampus((int) $session->campus_id);
            abort_unless($version->status === 'approved', 409, 'Only an independently approved paper version can be published.');
            $review = ExamPaperReview::query()->where('tenant_id', $tenantId)->where('paper_version_id', $version->getKey())->where('decision', 'approved')->orderByDesc('id')->firstOrFail();
            abort_unless((int) $version->created_by !== (int) $actor->getKey() && (int) $review->reviewer_id !== (int) $actor->getKey(), 403, 'The author and paper approver cannot publish this version.');
            if ((int) $version->version_number <= (int) $paper->current_version) {
                throw new ConflictHttpException('A newer or this paper version has already been published.');
            }
            abort_unless($session->status === 'published' && $session->cycle->status === 'published' && Carbon::parse($session->starts_at_utc)->isFuture(), 409, 'Paper publication requires a published cycle and a future published examination session.');

            // The paper row lock serializes competing publications. Retain the immutable previous
            // version as history while only its successor carries the live published status.
            if ((int) $paper->current_version > 0) {
                ExamPaperVersion::query()->where('tenant_id', $tenantId)->where('paper_id', $paper->getKey())
                    ->where('version_number', $paper->current_version)->where('status', 'published')->update(['status' => 'superseded', 'updated_at' => now()]);
            }
            $version->forceFill(['status' => 'published', 'published_at' => now()])->save();
            $paper->forceFill(['current_version' => (int) $version->version_number, 'current_set_code' => $version->set_code, 'status' => 'published', 'published_at' => now()])->save();
            $this->audit->record('exams.paper.version.published', $actor, $tenantId, $version, ['paper_id' => (int) $paper->getKey(), 'version' => (int) $version->version_number, 'set_code' => $version->set_code, 'content_hash' => $version->content_hash]);

            return $version->fresh();
        }, 3);
    }
}
