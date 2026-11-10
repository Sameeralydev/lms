<?php

namespace App\Models;

use App\Models\Concerns\HasAuditActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    use HasAuditActor;

    protected $fillable = ['tenant_id', 'actor_user_id', 'event_type', 'subject_type', 'subject_id', 'request_id', 'metadata', 'occurred_at'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Audit events are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Audit events are immutable.');
        });
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
