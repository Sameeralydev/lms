<?php

namespace App\Domain\Admissions;

use App\Domain\Tenancy\TenantContext;
use App\Models\AdmissionApplication;
use App\Models\AdmissionIntake;
use App\Models\AdmissionReviewerAssignment;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AdmissionsAccess
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  Builder<AdmissionIntake>  $query
     * @return Builder<AdmissionIntake>
     */
    public function scopeIntakes(Builder $query): Builder
    {
        $membership = $this->context->membership();

        return $membership->campus_id === null ? $query : $query->where('campus_id', $membership->campus_id);
    }

    /**
     * @param  Builder<AdmissionApplication>  $query
     * @return Builder<AdmissionApplication>
     */
    public function scopeApplications(Builder $query): Builder
    {
        $membership = $this->context->membership();
        if ($membership->campus_id === null) {
            return $query;
        }

        return $query->whereHas('intake', fn (Builder $intakes) => $intakes->where('campus_id', $membership->campus_id));
    }

    public function assertIntake(AdmissionIntake $intake): void
    {
        $membership = $this->context->membership();
        if ($membership->campus_id !== null && (int) $intake->campus_id !== (int) $membership->campus_id) {
            throw new NotFoundHttpException;
        }
    }

    public function assertApplication(AdmissionApplication $application): void
    {
        $membership = $this->context->membership();
        if ($membership->campus_id !== null && (int) $application->intake()->value('campus_id') !== (int) $membership->campus_id) {
            throw new NotFoundHttpException;
        }
    }

    public function assertAssignedOrAdministrator(AdmissionApplication $application): void
    {
        $membership = $this->context->membership();
        if ($membership->roles()->whereIn('key', ['tenant.owner', 'tenant.admin'])->exists()) {
            return;
        }
        $assigned = AdmissionReviewerAssignment::query()
            ->where('application_id', $application->getKey())
            ->where('reviewer_user_id', $membership->user_id)
            ->where('status', 'assigned')
            ->exists();
        if (! $assigned) {
            throw new NotFoundHttpException;
        }
    }
}
