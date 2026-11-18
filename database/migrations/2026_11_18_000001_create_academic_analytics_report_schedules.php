<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_analytics_report_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('membership_id');
            $table->foreignId('saved_report_id')->nullable()->constrained('academic_analytics_saved_reports')->nullOnDelete();
            $table->string('name', 100);
            $table->string('frequency', 16);
            $table->string('timezone', 64);
            $table->time('local_time');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->timestamp('next_run_at');
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->foreign(['tenant_id', 'institution_id'])->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'membership_id'])->references(['tenant_id', 'id'])->on('tenant_memberships')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'next_run_at'], 'analytics_schedules_due_idx');
        });
        Schema::create('academic_analytics_report_schedule_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('schedule_id')->constrained('academic_analytics_report_schedules')->cascadeOnDelete();
            $table->unsignedBigInteger('membership_id');
            $table->timestamps();
            $table->unique(['schedule_id', 'membership_id'], 'analytics_schedule_recipient_uq');
            $table->foreign(['tenant_id', 'membership_id'])->references(['tenant_id', 'id'])->on('tenant_memberships')->restrictOnDelete();
        });
        Schema::create('academic_analytics_report_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('schedule_id')->constrained('academic_analytics_report_schedules')->restrictOnDelete();
            $table->timestamp('scheduled_for');
            $table->string('status', 16)->default('queued');
            $table->string('storage_path', 255)->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['schedule_id', 'scheduled_for'], 'analytics_schedule_run_once_uq');
            $table->index(['tenant_id', 'schedule_id', 'created_at'], 'analytics_schedule_runs_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_analytics_report_runs');
        Schema::dropIfExists('academic_analytics_report_schedule_recipients');
        Schema::dropIfExists('academic_analytics_report_schedules');
    }
};
