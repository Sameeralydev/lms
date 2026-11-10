<?php

namespace Tests\Unit;

use App\Domain\Academic\Timetabling\LocalScheduleTime;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalScheduleTimeTest extends TestCase
{
    public function test_unambiguous_local_time_maps_to_a_utc_instant(): void
    {
        $instant = LocalScheduleTime::toUtc('2026-01-15', '09:30', 'Asia/Karachi');
        self::assertSame('2026-01-15T04:30:00+00:00', $instant->format(DATE_ATOM));
    }

    #[DataProvider('invalidDstTimes')]
    public function test_nonexistent_or_ambiguous_dst_times_are_rejected(string $date, string $time): void
    {
        $this->expectException(ValidationException::class);
        LocalScheduleTime::toUtc($date, $time, 'America/New_York');
    }

    /** @return array<string,array{string,string}> */
    public static function invalidDstTimes(): array
    {
        return ['spring gap' => ['2026-03-08', '02:30'], 'autumn fold' => ['2026-11-01', '01:30']];
    }
}
