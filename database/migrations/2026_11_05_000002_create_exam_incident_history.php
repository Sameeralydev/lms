<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_incidents', function (Blueprint $table): void {
            $table->foreignId('assigned_to')->nullable()->after('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('escalated_at')->nullable()->after('reviewed_at');
            $table->dateTime('closed_at')->nullable()->after('escalated_at');
            $table->index(['tenant_id', 'status', 'assigned_to'], 'exam_incidents_assignment_queue_idx');
        });

        Schema::create('exam_incident_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('incident_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('decision', 24)->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->text('safe_notes')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'incident_id'], 'exam_incident_history_incident_fk')
                ->references(['tenant_id', 'id'])->on('exam_incidents')->restrictOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'incident_id', 'id'], 'exam_incident_history_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_incident_history');
        Schema::table('exam_incidents', function (Blueprint $table): void {
            $table->dropIndex('exam_incidents_assignment_queue_idx');
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn(['escalated_at', 'closed_at']);
        });
    }
};
