<?php

namespace App\Domain\Academic\Timetabling;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

final class LocalScheduleTime
{
    public static function toUtc(string $date, string $time, string $timezone): DateTimeImmutable
    {
        $zone = new DateTimeZone($timezone);
        $local = $date.' '.$time;
        $naive = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $local, new DateTimeZone('UTC'));
        if (! $naive) {
            throw ValidationException::withMessages(['starts_at_local' => 'Enter a valid local date and time.']);
        }
        $transitions = $zone->getTransitions($naive->getTimestamp() - 86400, $naive->getTimestamp() + 86400) ?: [];
        $offsets = array_values(array_unique(array_map(static fn (array $transition): int => (int) $transition['offset'], $transitions)));
        $matches = [];
        foreach ($offsets as $offset) {
            $candidate = $naive->modify(($offset >= 0 ? '-' : '+').abs($offset).' seconds')->setTimezone($zone);
            if ($candidate->format('Y-m-d H:i') === $local) {
                $matches[$candidate->getTimestamp()] = $candidate;
            }
        }
        if (count($matches) !== 1) {
            throw ValidationException::withMessages(['starts_at_local' => count($matches) === 0
                ? 'This local time does not exist because of a daylight-saving transition.'
                : 'This local time occurs twice because of a daylight-saving transition; choose an unambiguous time.']);
        }

        return array_values($matches)[0]->setTimezone(new DateTimeZone('UTC'));
    }
}
