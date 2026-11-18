<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array{campus_id:int|null,from:string|null,to:string|null,metrics:list<string>|null,builder?:array{dataset:string,measure:string,dimensions:list<string>,status:string|null,filters:array<string,string>,search:string,sort:string,direction:'asc'|'desc'}} $filters */
final class AcademicAnalyticsSavedReport extends AcademicRecord
{
    protected $table = 'academic_analytics_saved_reports';

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }
}
