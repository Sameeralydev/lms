<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('academic_period_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('code', 48);
            $table->string('title', 180);
            $table->string('category', 32)->default('final');
            $table->string('status', 24)->default('draft');
            $table->date('registration_opens_on')->nullable();
            $table->date('registration_closes_on')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'exam_cycle_tenant_id_uq');
            $table->unique(['tenant_id', 'institution_id', 'code'], 'exam_cycle_code_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'starts_on'], 'exam_cycle_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'exam_cycle_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'exam_cycle_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_period_id'], 'exam_cycle_period_fk')->references(['tenant_id', 'id'])->on('academic_periods')->restrictOnDelete();
        });

        Schema::create('exam_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('cycle_id');
            $table->unsignedBigInteger('campus_id');
            $table->unsignedBigInteger('academic_course_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('code', 48);
            $table->string('title', 180);
            $table->string('mode', 16)->default('onsite');
            $table->string('status', 20)->default('draft');
            $table->dateTime('starts_at_utc');
            $table->dateTime('ends_at_utc');
            $table->dateTime('reporting_at_utc')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'exam_session_tenant_id_uq');
            $table->unique(['tenant_id', 'cycle_id', 'code'], 'exam_session_code_uq');
            $table->index(['tenant_id', 'campus_id', 'starts_at_utc', 'status'], 'exam_session_schedule_idx');
            $table->foreign(['tenant_id', 'cycle_id'], 'exam_session_cycle_fk')->references(['tenant_id', 'id'])->on('exam_cycles')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'exam_session_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_course_id'], 'exam_session_course_fk')->references(['tenant_id', 'id'])->on('academic_courses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'room_id'], 'exam_session_room_fk')->references(['tenant_id', 'id'])->on('academic_rooms')->restrictOnDelete();
        });

        Schema::create('exam_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('cycle_id');
            $table->unsignedBigInteger('student_id');
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('candidate_number', 48);
            $table->string('status', 20)->default('registered');
            $table->json('accommodations')->nullable();
            $table->dateTime('registered_at');
            $table->dateTime('withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'exam_candidate_tenant_id_uq');
            $table->unique(['tenant_id', 'cycle_id', 'student_id'], 'exam_candidate_student_uq');
            $table->unique(['tenant_id', 'cycle_id', 'candidate_number'], 'exam_candidate_number_uq');
            $table->index(['tenant_id', 'cycle_id', 'status'], 'exam_candidate_status_idx');
            $table->foreign(['tenant_id', 'cycle_id'], 'exam_candidate_cycle_fk')->references(['tenant_id', 'id'])->on('exam_cycles')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'exam_candidate_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
        });

        Schema::create('exam_seat_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('room_id');
            $table->string('seat_number', 24);
            $table->foreignId('allocated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'session_id', 'candidate_id'], 'exam_seat_candidate_uq');
            $table->unique(['tenant_id', 'session_id', 'room_id', 'seat_number'], 'exam_seat_number_uq');
            $table->foreign(['tenant_id', 'session_id'], 'exam_seat_session_fk')->references(['tenant_id', 'id'])->on('exam_sessions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'candidate_id'], 'exam_seat_candidate_fk')->references(['tenant_id', 'id'])->on('exam_candidates')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'room_id'], 'exam_seat_room_fk')->references(['tenant_id', 'id'])->on('academic_rooms')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_seat_allocations');
        Schema::dropIfExists('exam_candidates');
        Schema::dropIfExists('exam_sessions');
        Schema::dropIfExists('exam_cycles');
    }
};
