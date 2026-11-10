<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array{module_ids:list<int>,lesson_ids:array<int,list<int>>} $selection @property array<string,array<string,int>>|null $result_map */
class CourseDeliveryCopyOperation extends AcademicRecord
{
    protected function casts(): array
    {
        return ['selection' => 'array', 'result_map' => 'array'];
    }

    /** @return BelongsTo<CourseDeliveryWorkspace, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(CourseDeliveryWorkspace::class, 'destination_workspace_id');
    }
}
