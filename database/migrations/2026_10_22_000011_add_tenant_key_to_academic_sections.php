<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_sections', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'acad_section_tenant_id_uq');
        });
    }

    public function down(): void
    {
        Schema::table('academic_sections', function (Blueprint $table): void {
            $table->dropUnique('acad_section_tenant_id_uq');
        });
    }
};
