<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_justifications', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'att_justification_tenant_id_uq');
        });
        Schema::create('attendance_justification_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('justification_id');
            $table->string('private_path', 512)->unique();
            $table->string('sha256', 64);
            $table->string('mime_type', 127);
            $table->unsignedInteger('size_bytes');
            $table->string('scan_status', 16)->default('quarantined');
            $table->string('scanner', 80)->nullable();
            $table->string('result_code', 64)->nullable();
            $table->dateTime('scanned_at')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'att_evidence_tenant_id_uq');
            $table->index(['tenant_id', 'justification_id', 'scan_status'], 'att_evidence_review_idx');
            $table->foreign(['tenant_id', 'justification_id'], 'att_evidence_justification_fk')->references(['tenant_id', 'id'])->on('attendance_justifications')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_justification_evidence');
        Schema::table('attendance_justifications', function (Blueprint $table): void {
            $table->dropUnique('att_justification_tenant_id_uq');
        });
    }
};
