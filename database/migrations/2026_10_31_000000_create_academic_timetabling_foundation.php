<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_scheduling_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->json('teaching_days');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->unsignedSmallInteger('slot_minutes')->default(50);
            $table->json('break_windows')->nullable();
            $table->unsignedSmallInteger('max_weekly_teaching_minutes')->default(2400);
            $table->string('timezone', 64);
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'campus_id'], 'sched_setting_scope_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'sched_setting_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'sched_setting_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        Schema::create('academic_buildings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id');
            $table->string('code', 32);
            $table->string('name', 140);
            $table->text('address')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'sched_building_tenant_id_uq');
            $table->unique(['tenant_id', 'campus_id', 'id'], 'sched_building_tenant_campus_id_uq');
            $table->unique(['tenant_id', 'campus_id', 'code'], 'sched_building_code_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'sched_building_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'sched_building_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        Schema::create('academic_rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id');
            $table->unsignedBigInteger('building_id')->nullable();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->string('room_type', 24)->default('classroom');
            $table->unsignedSmallInteger('capacity');
            $table->json('features')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'sched_room_tenant_id_uq');
            $table->unique(['tenant_id', 'campus_id', 'code'], 'sched_room_code_uq');
            $table->index(['tenant_id', 'campus_id', 'status', 'capacity'], 'sched_room_avail_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'sched_room_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'sched_room_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id', 'building_id'], 'sched_room_building_fk')->references(['tenant_id', 'campus_id', 'id'])->on('academic_buildings')->restrictOnDelete();
        });

        Schema::create('academic_room_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('room_id');
            $table->dateTime('starts_at_utc');
            $table->dateTime('ends_at_utc');
            $table->string('block_type', 20)->default('maintenance');
            $table->string('reason', 500);
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'room_id', 'starts_at_utc', 'ends_at_utc', 'status'], 'sched_room_block_dates_idx');
            $table->foreign(['tenant_id', 'room_id'], 'sched_room_block_room_fk')->references(['tenant_id', 'id'])->on('academic_rooms')->restrictOnDelete();
        });

        Schema::create('academic_calendar_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->string('name', 120);
            $table->string('exception_type', 16)->default('closure');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
            $table->index(['tenant_id', 'institution_id', 'starts_on', 'ends_on'], 'sched_exception_dates_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'sched_exception_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'sched_exception_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        Schema::create('academic_instructor_availabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->json('weekdays');
            $table->date('effective_from');
            $table->date('effective_until');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('availability_type', 16)->default('unavailable');
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'effective_from', 'effective_until'], 'sched_instructor_avail_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'sched_avail_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'sched_avail_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        Schema::create('academic_timetables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedBigInteger('academic_period_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('supersedes_timetable_id')->nullable();
            $table->string('name', 140);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->string('timezone', 64);
            $table->boolean('independent_review_required')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'sched_timetable_tenant_id_uq');
            $table->unique(['tenant_id', 'academic_period_id', 'campus_id', 'version'], 'sched_timetable_version_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'academic_period_id'], 'sched_timetable_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'sched_timetable_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_year_id'], 'sched_timetable_year_fk')->references(['tenant_id', 'id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_period_id'], 'sched_timetable_period_fk')->references(['tenant_id', 'id'])->on('academic_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'sched_timetable_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'supersedes_timetable_id'], 'sched_timetable_supersedes_fk')->references(['tenant_id', 'id'])->on('academic_timetables')->restrictOnDelete();
        });

        Schema::create('academic_timetable_meetings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('timetable_id');
            $table->unsignedBigInteger('section_id');
            $table->foreignId('instructor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->json('weekdays');
            $table->time('starts_at_local');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('delivery_mode', 16)->default('in_person');
            $table->string('online_location', 500)->nullable();
            $table->string('status', 16)->default('scheduled');
            $table->string('series_key', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'starts_on', 'ends_on', 'status'], 'sched_meeting_dates_idx');
            $table->index(['tenant_id', 'instructor_user_id', 'starts_on', 'ends_on'], 'sched_meeting_teacher_idx');
            $table->index(['tenant_id', 'room_id', 'starts_on', 'ends_on'], 'sched_meeting_room_idx');
            $table->unique(['tenant_id', 'id'], 'sched_meeting_tenant_id_uq');
            $table->foreign(['tenant_id', 'timetable_id'], 'sched_meeting_timetable_fk')->references(['tenant_id', 'id'])->on('academic_timetables')->restrictOnDelete();
            $table->foreign(['tenant_id', 'section_id'], 'sched_meeting_section_fk')->references(['tenant_id', 'id'])->on('course_sections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'room_id'], 'sched_meeting_room_fk')->references(['tenant_id', 'id'])->on('academic_rooms')->restrictOnDelete();
        });

        Schema::create('academic_timetable_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('timetable_id');
            $table->unsignedInteger('version');
            $table->foreignId('reviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 16);
            $table->text('note');
            $table->timestamps();
            $table->index(['tenant_id', 'timetable_id', 'version', 'created_at'], 'sched_review_history_idx');
            $table->foreign(['tenant_id', 'timetable_id'], 'sched_review_timetable_fk')->references(['tenant_id', 'id'])->on('academic_timetables')->restrictOnDelete();
        });

        Schema::create('academic_timetable_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('timetable_id');
            $table->unsignedBigInteger('meeting_id');
            $table->date('occurrence_date');
            $table->string('change_type', 16);
            $table->time('starts_at_local')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->foreignId('instructor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->text('reason');
            $table->string('status', 16)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at'], 'sched_change_review_idx');
            $table->index(['tenant_id', 'meeting_id', 'occurrence_date', 'status'], 'sched_change_occurrence_idx');
            $table->foreign(['tenant_id', 'timetable_id'], 'sched_change_timetable_fk')->references(['tenant_id', 'id'])->on('academic_timetables')->restrictOnDelete();
            $table->foreign(['tenant_id', 'meeting_id'], 'sched_change_meeting_fk')->references(['tenant_id', 'id'])->on('academic_timetable_meetings')->restrictOnDelete();
            $table->foreign(['tenant_id', 'room_id'], 'sched_change_room_fk')->references(['tenant_id', 'id'])->on('academic_rooms')->restrictOnDelete();
        });

        Schema::create('academic_timetable_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('timetable_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('sha256', 64);
            $table->dateTime('published_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'timetable_id', 'version'], 'sched_snapshot_version_uq');
            $table->foreign(['tenant_id', 'timetable_id'], 'sched_snapshot_timetable_fk')->references(['tenant_id', 'id'])->on('academic_timetables')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_timetable_changes');
        Schema::dropIfExists('academic_timetable_snapshots');
        Schema::dropIfExists('academic_timetable_reviews');
        Schema::dropIfExists('academic_timetable_meetings');
        Schema::dropIfExists('academic_timetables');
        Schema::dropIfExists('academic_instructor_availabilities');
        Schema::dropIfExists('academic_calendar_exceptions');
        Schema::dropIfExists('academic_room_blocks');
        Schema::dropIfExists('academic_rooms');
        Schema::dropIfExists('academic_buildings');
        Schema::dropIfExists('academic_scheduling_settings');
    }
};
