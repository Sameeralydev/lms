<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property int $version_number @property int $size_bytes @property Carbon|null $expires_at */
class SisPrivateDocumentVersion extends AcademicRecord
{
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'size_bytes' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Uploaded SIS document versions are immutable.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Uploaded SIS document versions are retained for audit.');
        });
    }

    /** @return BelongsTo<SisPrivateDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SisPrivateDocument::class, 'document_id');
    }
}
