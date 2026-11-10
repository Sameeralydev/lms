<?php

namespace App\Models;

use LogicException;

/** Append-only attendance history. */
class AttendanceEntryRevision extends AcademicRecord
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Attendance entry revisions are immutable.'));
        static::deleting(static fn () => throw new LogicException('Attendance entry revisions are immutable.'));
    }
}
