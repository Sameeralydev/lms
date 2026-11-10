<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_grade_scale_bands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('policy_id');
            $table->string('band_key', 32);
            $table->string('label', 48);
            $table->unsignedSmallInteger('minimum_percentage_basis_points');
            $table->decimal('grade_points', 5, 3)->nullable();
            $table->boolean('passing')->nullable();
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'grade_scale_band_tenant_id_uq');
            $table->unique(['tenant_id', 'policy_id', 'band_key'], 'grade_scale_band_key_uq');
            $table->unique(['tenant_id', 'policy_id', 'minimum_percentage_basis_points'], 'grade_scale_band_threshold_uq');
            $table->index(['tenant_id', 'policy_id', 'minimum_percentage_basis_points'], 'grade_scale_band_resolve_idx');
            $table->foreign(['tenant_id', 'policy_id'], 'grade_scale_band_policy_fk')
                ->references(['tenant_id', 'id'])->on('academic_grade_policies')->restrictOnDelete();
        });

        Schema::table('academic_grade_publication_entries', function (Blueprint $table): void {
            $table->string('scale_result', 48)->nullable();
            $table->decimal('grade_points', 5, 3)->nullable();
            $table->boolean('passed')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('academic_grade_publication_entries', function (Blueprint $table): void {
            $table->dropColumn(['scale_result', 'grade_points', 'passed']);
        });

        Schema::dropIfExists('academic_grade_scale_bands');
    }
};
