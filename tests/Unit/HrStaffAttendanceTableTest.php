<?php

namespace Tests\Unit;

use App\Models\HrStaffAttendance;
use PHPUnit\Framework\TestCase;

final class HrStaffAttendanceTableTest extends TestCase
{
    public function test_attendance_model_uses_the_table_created_by_its_migration(): void
    {
        $this->assertSame('hr_staff_attendance', (new HrStaffAttendance)->getTable());
    }
}
