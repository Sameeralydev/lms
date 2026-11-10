<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantInvitation extends Model
{
    /** @var array<string, string> */
    protected $casts = ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];

    protected $fillable = ['tenant_id', 'institution_id', 'sis_student_id', 'sis_student_guardian_id', 'invited_by', 'campus_id', 'email', 'role_key', 'token_hash', 'status', 'expires_at', 'accepted_at'];

    protected $hidden = ['token_hash'];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
