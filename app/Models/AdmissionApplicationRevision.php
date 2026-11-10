<?php

namespace App\Models;

class AdmissionApplicationRevision extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['answers' => 'array', 'submitted_snapshot' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Admissions answer revisions are immutable.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Admissions answer revisions are retained for history.');
        });
    }
}
