<?php

namespace App\Models;

class AdmissionDocumentVersion extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Uploaded admissions document versions are immutable.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Uploaded admissions document versions are retained for audit.');
        });
    }
}
