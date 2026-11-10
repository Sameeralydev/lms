<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admission_documents', function (Blueprint $table): void {
            $table->string('scan_status', 24)->default('pending')->after('status')->index('adm_document_scan_status_idx');
        });

        Schema::create('admission_document_scan_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('status', 24)->default('pending');
            $table->string('scanner', 80)->nullable();
            $table->string('result_code', 80)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'document_version_id', 'attempt_number'], 'adm_doc_scan_attempt_uq');
            $table->index(['tenant_id', 'status', 'created_at'], 'adm_doc_scan_status_idx');
            $table->foreign(['tenant_id', 'document_version_id'], 'adm_doc_scan_version_fk')
                ->references(['tenant_id', 'id'])->on('admission_document_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_document_scan_events');
        Schema::table('admission_documents', function (Blueprint $table): void {
            $table->dropIndex('adm_document_scan_status_idx');
            $table->dropColumn('scan_status');
        });
    }
};
