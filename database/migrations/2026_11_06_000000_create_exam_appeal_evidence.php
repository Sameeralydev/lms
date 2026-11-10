<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_appeal_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('appeal_id');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('private_path', 500);
            $table->string('original_name', 180);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 16)->default('quarantined');
            $table->string('result_code', 64)->nullable();
            $table->string('scanner', 120)->nullable();
            $table->dateTime('scanned_at')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'appeal_id'], 'exam_appeal_evidence_parent_fk')
                ->references(['tenant_id', 'id'])->on('exam_recheck_requests')->restrictOnDelete();
            $table->index(['tenant_id', 'appeal_id', 'scan_status'], 'exam_appeal_evidence_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_appeal_evidence');
    }
};
