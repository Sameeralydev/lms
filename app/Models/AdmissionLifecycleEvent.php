<?php

namespace App\Models;

class AdmissionLifecycleEvent extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['safe_metadata' => 'array', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Admissions lifecycle history is immutable.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Admissions lifecycle history is retained for audit.');
        });
    }
}
