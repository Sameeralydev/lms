# Phase 09 Database Design

Migration: `database/migrations/2026_10_31_000000_create_academic_timetabling_foundation.php`.

The foundation defines eleven tables:

1. `academic_scheduling_settings`
2. `academic_buildings`
3. `academic_rooms`
4. `academic_room_blocks`
5. `academic_calendar_exceptions`
6. `academic_instructor_availabilities`
7. `academic_timetables`
8. `academic_timetable_meetings`
9. `academic_timetable_reviews`
10. `academic_timetable_changes`
11. `academic_timetable_snapshots`

Ownership is keyed to the existing tenant and institution records. Composite foreign keys bind facilities to tenant/campus, and a room building must belong to that same campus. Scheduling records use explicit period, campus, instructor, room, section, status, and date indexes. Unique constraints cover campus facility codes, timetable versions, and immutable publication snapshots.

The migration has been exercised by the SQLite PHPUnit environment. No MySQL schema was modified or inspected for Phase 09. MySQL 8.4 constraint behavior, indexes, locking, concurrent scheduling, and the ownership lifecycle remain unverified until a dedicated Phase 09 test schema, account, private environment file, and guarded runner are provisioned and validated.
