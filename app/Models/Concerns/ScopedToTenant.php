<?php

namespace App\Models\Concerns;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

trait ScopedToTenant
{
    protected static function bootScopedToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            /** @var TenantContext $context */
            $context = app(TenantContext::class);

            if (! $context->hasTenant()) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where($builder->getModel()->qualifyColumn('tenant_id'), $context->tenant()->getKey());
        });

        static::creating(function (Model $model): void {
            /** @var TenantContext $context */
            $context = app(TenantContext::class);

            if (! $context->hasTenant()) {
                throw new LogicException('Tenant-owned records cannot be created without verified tenant context.');
            }

            if ($model->getAttribute('tenant_id') !== null && (int) $model->getAttribute('tenant_id') !== (int) $context->tenant()->getKey()) {
                throw new LogicException('Tenant-owned records cannot be created for another tenant.');
            }

            $model->setAttribute('tenant_id', $context->tenant()->getKey());
        });
    }
}
