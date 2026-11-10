<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class TenantMembership extends Model
{
    use ScopedToTenant;

    protected $fillable = [
        'tenant_id', 'institution_id', 'user_id', 'campus_id', 'status', 'activated_at', 'revoked_at',
        'sis_student_suspension_student_id', 'sis_student_suspended_at', 'sis_student_suspension_reason_code',
    ];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'revoked_at' => 'datetime', 'sis_student_suspended_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return BelongsToMany<Role, $this, Pivot, 'pivot'> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'membership_role', 'membership_id', 'role_id')
            ->withPivot('assigned_by', 'tenant_id')
            ->withTimestamps();
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return $this->roles()->whereHas('permissions', fn ($query) => $query->where('key', $permission))->exists();
    }
}
