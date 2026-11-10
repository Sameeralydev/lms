<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_incident_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('incident_id');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('private_path', 500)->unique();
            $table->string('original_name', 180);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 16)->default('quarantined');
            $table->string('result_code', 64)->nullable();
            $table->string('scanner', 120)->nullable();
            $table->dateTime('scanned_at')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'incident_id'], 'exam_incident_evidence_incident_fk')->references(['tenant_id', 'id'])->on('exam_incidents')->cascadeOnDelete();
            $table->index(['tenant_id', 'incident_id', 'scan_status'], 'exam_incident_evidence_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_incident_evidence');
    }
};
