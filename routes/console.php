<?php

use App\Domain\Academic\Attendance\AttendanceOutboxProcessor;
use App\Domain\Admissions\AdmissionOutboxProcessor;
use App\Domain\Assessments\QuizAssessmentWorkflow;
use App\Domain\Assessments\QuizOutboxProcessor;
use App\Domain\Assignments\AssignmentOutboxProcessor;
use App\Domain\Assignments\AssignmentWorkflow;
use App\Domain\Enrollment\CourseEnrollmentWorkflow;
use App\Domain\Learning\CourseDeliveryActivityRetentionProcessor;
use App\Domain\Learning\CourseDeliveryMediaRetentionProcessor;
use App\Domain\Sis\SisPrivateDocumentRetentionProcessor;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admissions:outbox:dispatch', function (AdmissionOutboxProcessor $processor): int {
    $result = $processor->dispatchAvailable();
    $this->info(sprintf(
        'Admission outbox: claimed=%d processed=%d retried=%d failed=%d',
        $result['claimed'], $result['processed'], $result['retried'], $result['failed'],
    ));

    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Deliver durable admission outbox events to internal consumers');

Artisan::command('attendance:outbox:dispatch', function (AttendanceOutboxProcessor $processor): int {
    $result = $processor->dispatchAvailable();
    $this->info(sprintf(
        'Attendance outbox: claimed=%d delivered=%d retried=%d failed=%d',
        $result['claimed'], $result['delivered'], $result['retried'], $result['failed'],
    ));

    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Dispatch durable attendance events to internal authorized consumers');

Artisan::command('assignments:outbox:dispatch', function (TenantContext $context): int {
    $totals = ['claimed' => 0, 'delivered' => 0, 'retried' => 0, 'failed' => 0];
    Tenant::query()->orderBy('id')->chunkById(100, function ($tenants) use ($context, &$totals): void {
        foreach ($tenants as $tenant) {
            $context->enterProvisioning($tenant);
            try {
                $result = app(AssignmentOutboxProcessor::class)->dispatchAvailable();
                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + $result[$key];
                }
            } finally {
                $context->clear();
            }
        }
    });
    $this->info(sprintf('Assignment outbox: claimed=%d delivered=%d retried=%d failed=%d', $totals['claimed'], $totals['delivered'], $totals['retried'], $totals['failed']));

    return $totals['failed'] > 0 ? 1 : 0;
})->purpose('Deliver durable assignment events to the in-app notification center');

Artisan::command('assessments:outbox:dispatch', function (TenantContext $context): int {
    $totals = ['claimed' => 0, 'delivered' => 0, 'retried' => 0, 'failed' => 0];
    Tenant::query()->orderBy('id')->chunkById(100, function ($tenants) use ($context, &$totals): void {
        foreach ($tenants as $tenant) {
            $context->enterProvisioning($tenant);
            try {
                $result = app(QuizOutboxProcessor::class)->dispatchAvailable();
                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + $result[$key];
                }
            } finally {
                $context->clear();
            }
        }
    });
    $this->info(sprintf('Assessment outbox: claimed=%d delivered=%d retried=%d failed=%d', $totals['claimed'], $totals['delivered'], $totals['retried'], $totals['failed']));

    return $totals['failed'] > 0 ? 1 : 0;
})->purpose('Deliver quiz publication, submission, and result notifications to authorized in-app recipients');

Artisan::command('assessments:availability:process', function (TenantContext $context): int {
    $published = 0;
    $closed = 0;
    Tenant::query()->orderBy('id')->chunkById(100, function ($tenants) use ($context, &$published, &$closed): void {
        foreach ($tenants as $tenant) {
            $context->enterProvisioning($tenant);
            try {
                $counts = app(QuizAssessmentWorkflow::class)->processAvailabilityTransitions();
                $published += $counts['published'];
                $closed += $counts['closed'];
            } finally {
                $context->clear();
            }
        }
    });
    $this->info(sprintf('Assessment lifecycle: published=%d closed=%d', $published, $closed));

    return 0;
})->purpose('Publish scheduled quizzes and close assessments whose institution-local availability window ended');

Artisan::command('assessments:attempts:expire', function (TenantContext $context): int {
    $finalized = 0;
    $candidates = DB::table('quiz_attempts as attempt')->join('tenant_memberships as membership', function ($join): void {
        $join->on('membership.id', '=', 'attempt.membership_id')->on('membership.tenant_id', '=', 'attempt.tenant_id');
    })->where('attempt.status', 'in_progress')->whereNotNull('attempt.expires_at')->where('attempt.expires_at', '<=', now())
        ->orderBy('attempt.id')->limit(500)->get(['attempt.id', 'attempt.tenant_id', 'attempt.membership_id', 'membership.user_id']);
    foreach ($candidates as $candidate) {
        $tenant = Tenant::query()->find((int) $candidate->tenant_id);
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $candidate->tenant_id)->find((int) $candidate->membership_id);
        $user = User::query()->find((int) $candidate->user_id);
        if (! $tenant || ! $membership || ! $user || $membership->status !== 'active') {
            continue;
        }
        $context->set($tenant, $membership);
        try {
            $attempt = app(QuizAssessmentWorkflow::class)->submitAttempt((int) $candidate->id, $user, true);
            if ($attempt->status !== 'in_progress') {
                $finalized++;
            }
        } catch (Throwable $error) {
            report($error);
        } finally {
            $context->clear();
        }
    }
    $this->info(sprintf('Expired quiz attempts finalized: %d', $finalized));

    return 0;
})->purpose('Finalize expired quiz attempts using server deadlines and idempotent submission locking');

Artisan::command('assignments:availability:process', function (TenantContext $context): int {
    $published = 0;
    $closed = 0;
    Tenant::query()->orderBy('id')->chunkById(100, function ($tenants) use ($context, &$published, &$closed): void {
        foreach ($tenants as $tenant) {
            $context->enterProvisioning($tenant);
            try {
                $counts = app(AssignmentWorkflow::class)->processAvailabilityTransitions();
                $published += $counts['published'];
                $closed += $counts['closed'];
            } finally {
                $context->clear();
            }
        }
    });
    $this->info(sprintf('Assignment lifecycle: published=%d closed=%d', $published, $closed));

    return 0;
})->purpose('Publish scheduled assignments and close those whose late windows have ended');

Artisan::command('assignments:integrity:expire-responses', function (TenantContext $context): int {
    $total = 0;
    Tenant::query()->orderBy('id')->chunkById(100, function ($tenants) use ($context, &$total): void {
        foreach ($tenants as $tenant) {
            $context->enterProvisioning($tenant);
            try {
                $total += app(AssignmentWorkflow::class)->processOverdueIntegrityResponses();
            } finally {
                $context->clear();
            }
        }
    });
    $this->info(sprintf('Assignment integrity: overdue responses advanced=%d', $total));

    return 0;
})->purpose('Advance overdue integrity response cases for authorized independent human review');

Artisan::command('sis:documents:apply-retention', function (SisPrivateDocumentRetentionProcessor $processor): int {
    $result = $processor->run();
    $this->info(sprintf(
        'SIS document retention: archived=%d purged=%d skipped=%d',
        $result['archived'], $result['purged'], $result['skipped'],
    ));

    return $result['skipped'] > 0 ? 1 : 0;
})->purpose('Apply verified tenant SIS document archive and purge policies');

Artisan::command('learning:activity:apply-retention', function (CourseDeliveryActivityRetentionProcessor $processor): int {
    $deleted = $processor->run();
    $this->info(sprintf('Learning activity retention: deleted=%d', $deleted));

    return 0;
})->purpose('Delete learner activity responses whose configured retention period has expired');

Artisan::command('learning:media:apply-retention', function (CourseDeliveryMediaRetentionProcessor $processor): int {
    $result = $processor->run();
    $this->info(sprintf('Learning media retention: archived=%d purged=%d skipped=%d', $result['archived'], $result['purged'], $result['skipped']));

    return $result['skipped'] > 0 ? 1 : 0;
})->purpose('Archive eligible private learning media and purge only when no published revision references it');

Artisan::command('enrollment:waitlist:expire', function (TenantContext $context): int {
    $total = 0;
    Tenant::query()->orderBy('id')->chunkById(100, function ($tenants) use ($context, &$total): void {
        foreach ($tenants as $tenant) {
            $context->enterProvisioning($tenant);
            try {
                $total += app(CourseEnrollmentWorkflow::class)->expireWaitlistOffers();
            } finally {
                $context->clear();
            }
        }
    });
    $this->info(sprintf('Enrollment waitlist: expired=%d', $total));

    return 0;
})->purpose('Expire waitlist offers and release seats to the next eligible learner');

Schedule::command('enrollment:waitlist:expire')->everyMinute()->withoutOverlapping();

Schedule::command('admissions:outbox:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('attendance:outbox:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('assignments:outbox:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('assignments:availability:process')->everyMinute()->withoutOverlapping();
Schedule::command('assignments:integrity:expire-responses')->everyMinute()->withoutOverlapping();
Schedule::command('assessments:outbox:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('assessments:availability:process')->everyMinute()->withoutOverlapping();
Schedule::command('assessments:attempts:expire')->everyMinute()->withoutOverlapping();
Schedule::command('sis:documents:apply-retention')->daily()->withoutOverlapping();
Schedule::command('learning:activity:apply-retention')->daily()->withoutOverlapping();
Schedule::command('learning:media:apply-retention')->daily()->withoutOverlapping();
