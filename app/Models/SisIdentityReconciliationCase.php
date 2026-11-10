<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $tenant_id
 * @property int $institution_id
 * @property int $student_id
 * @property int $current_user_id
 * @property int $proposed_user_id
 * @property int $requested_by
 * @property int|null $reviewed_by
 * @property string $status
 * @property Carbon $requested_at
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $applied_at
 */
class SisIdentityReconciliationCase extends Model
{
    use ScopedToTenant;

    protected $fillable = [
        'tenant_id', 'institution_id', 'student_id', 'current_user_id', 'proposed_user_id',
        'requested_by', 'reviewed_by', 'status', 'reason_code', 'request_reason',
        'decision_reason', 'requested_at', 'reviewed_at', 'applied_at', 'pending_student_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SisStudent, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(SisStudent::class);
    }

    /** @return BelongsTo<User, $this> */
    public function currentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function proposedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
