<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_analytics_saved_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->string('name', 100);
            $table->json('filters');
            $table->timestamps();

            $table->unique(['tenant_id', 'membership_id', 'name'], 'analytics_saved_report_owner_name_uq');
            $table->index(['tenant_id', 'institution_id', 'membership_id', 'created_at'], 'analytics_saved_report_owner_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'analytics_saved_report_institution_fk')
                ->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'membership_id'], 'analytics_saved_report_membership_fk')
                ->references(['tenant_id', 'id'])->on('tenant_memberships')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'analytics_saved_report_campus_fk')
                ->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('academic_analytics_saved_reports');
    }
};
