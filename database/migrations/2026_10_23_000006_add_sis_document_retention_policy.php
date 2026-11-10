<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sis_registry_settings', function (Blueprint $table): void {
            $table->unsignedInteger('document_retention_days')->nullable()->after('number_width');
        });

        Schema::table('sis_private_document_versions', function (Blueprint $table): void {
            $table->dateTime('expires_at')->nullable()->after('uploaded_by');
            $table->index(['tenant_id', 'expires_at'], 'sis_private_ver_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sis_private_document_versions', function (Blueprint $table): void {
            $table->dropIndex('sis_private_ver_expiry_idx');
            $table->dropColumn('expires_at');
        });

        Schema::table('sis_registry_settings', function (Blueprint $table): void {
            $table->dropColumn('document_retention_days');
        });
    }
};
