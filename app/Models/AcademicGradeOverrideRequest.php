<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicGradeOverrideRequest extends Model
{
    protected $fillable = [
        'tenant_id', 'institution_id', 'gradebook_id', 'entry_id', 'original_points', 'proposed_points', 'reason', 'status',
        'requested_by', 'decided_by', 'decision_reason', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['original_points' => 'decimal:2', 'proposed_points' => 'decimal:2', 'decided_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AcademicGradebook, $this> */
    public function gradebook(): BelongsTo
    {
        return $this->belongsTo(AcademicGradebook::class, 'gradebook_id');
    }

    /** @return BelongsTo<AcademicGradeEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(AcademicGradeEntry::class, 'entry_id');
    }
}
