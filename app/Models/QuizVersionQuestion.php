<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $tenant_id
 * @property int $quiz_version_id
 * @property int $question_version_id
 * @property int $position
 * @property numeric-string $points
 * @property string|null $group_key
 */
class QuizVersionQuestion extends Pivot
{
    protected $table = 'quiz_version_questions';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['position' => 'integer', 'points' => 'decimal:2'];
    }
}
