<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $form_template_id
 * @property string $status
 * @property array<string, mixed> $definition
 */
class AdmissionFormVersion extends AdmissionsRecord
{
    protected function casts(): array
    {
        return ['definition' => 'array', 'effective_from' => 'datetime', 'effective_until' => 'datetime', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getOriginal('status') === 'published') {
                throw new \LogicException('Published admissions form versions are immutable.');
            }
        });
    }

    /** @return BelongsTo<AdmissionFormTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AdmissionFormTemplate::class, 'form_template_id');
    }
}
