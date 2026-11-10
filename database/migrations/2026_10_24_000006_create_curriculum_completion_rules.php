<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_curriculum_completion_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('curriculum_version_id');
            $table->unsignedBigInteger('academic_period_id')->nullable();
            $table->string('code', 48);
            $table->string('title', 160);
            $table->string('rule_type', 32);
            $table->decimal('target_value', 10, 3)->nullable();
            $table->string('unit', 24)->nullable();
            $table->boolean('is_required')->default(true);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'curriculum_version_id', 'code'], 'curriculum_rule_code_uq');
            $table->index(['tenant_id', 'curriculum_version_id', 'is_required', 'sort_order'], 'curriculum_rule_plan_idx');
            $table->foreign(['tenant_id', 'curriculum_version_id'], 'curriculum_rule_version_fk')
                ->references(['tenant_id', 'id'])->on('academic_curriculum_versions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_period_id'], 'curriculum_rule_period_fk')
                ->references(['tenant_id', 'id'])->on('academic_periods')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_curriculum_completion_rules');
    }
};
