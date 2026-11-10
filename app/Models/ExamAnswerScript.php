<?php

namespace App\Models;

/** @property int $session_id @property int $candidate_id @property string $private_path @property string $scan_status @property string $sha256 */
class ExamAnswerScript extends AcademicRecord
{
    protected function casts(): array
    {
        return ['scanned_at' => 'immutable_datetime', 'retention_until' => 'immutable_datetime'];
    }
}
