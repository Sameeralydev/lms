<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_gpa_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('policy_key', 64);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('name', 160);
            $table->string('repeat_rule', 16)->default('highest');
            $table->boolean('exclude_pass_fail')->default(true);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'gpa_policy_tenant_id_uq');
            $table->unique(['tenant_id', 'institution_id', 'policy_key', 'version'], 'gpa_policy_version_uq');
            $table->index(['tenant_id', 'institution_id', 'status'], 'gpa_policy_lookup_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'gpa_policy_institution_fk')
                ->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('academic_gpa_standing_bands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('policy_id');
            $table->string('band_key', 32);
            $table->string('label', 80);
            $table->decimal('minimum_gpa', 5, 3);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'gpa_standing_band_tenant_id_uq');
            $table->unique(['tenant_id', 'policy_id', 'band_key'], 'gpa_standing_band_key_uq');
            $table->unique(['tenant_id', 'policy_id', 'minimum_gpa'], 'gpa_standing_band_min_uq');
            $table->index(['tenant_id', 'policy_id', 'minimum_gpa'], 'gpa_standing_resolve_idx');
            $table->foreign(['tenant_id', 'policy_id'], 'gpa_standing_band_policy_fk')
                ->references(['tenant_id', 'id'])->on('academic_gpa_policies')->restrictOnDelete();
        });

        Schema::create('academic_gpa_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('policy_id');
            $table->unsignedBigInteger('academic_period_id')->nullable();
            $table->string('calculation_type', 16);
            $table->string('scope_key', 32);
            $table->unsignedInteger('snapshot_number');
            $table->decimal('gpa', 5, 3);
            $table->decimal('quality_points', 14, 3);
            $table->decimal('attempted_credits', 10, 3);
            $table->string('academic_standing', 80)->nullable();
            $table->string('source_hash', 64);
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->foreignId('calculated_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('calculated_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'gpa_snapshot_tenant_id_uq');
            $table->unique(['tenant_id', 'student_id', 'policy_id', 'scope_key', 'snapshot_number'], 'gpa_snapshot_version_uq');
            $table->index(['tenant_id', 'student_id', 'academic_period_id', 'calculation_type'], 'gpa_snapshot_student_lookup_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'gpa_snapshot_institution_fk')
                ->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'gpa_snapshot_student_fk')
                ->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'policy_id'], 'gpa_snapshot_policy_fk')
                ->references(['tenant_id', 'id'])->on('academic_gpa_policies')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_period_id'], 'gpa_snapshot_period_fk')
                ->references(['tenant_id', 'id'])->on('academic_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'supersedes_id'], 'gpa_snapshot_supersedes_fk')
                ->references(['tenant_id', 'id'])->on('academic_gpa_snapshots')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_gpa_snapshots');
        Schema::dropIfExists('academic_gpa_standing_bands');
        Schema::dropIfExists('academic_gpa_policies');
    }
};
