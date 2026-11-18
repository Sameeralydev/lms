<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_fee_applicability_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('academic_program_id')->nullable();
            $table->unsignedBigInteger('academic_period_id')->nullable();
            $table->unsignedBigInteger('fee_structure_id');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'bill_fee_rule_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'effective_from'], 'bill_fee_rule_scope_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'bill_fee_rule_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'bill_fee_rule_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_program_id'], 'bill_fee_rule_program_fk')->references(['tenant_id', 'id'])->on('academic_programs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_period_id'], 'bill_fee_rule_period_fk')->references(['tenant_id', 'id'])->on('academic_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'fee_structure_id'], 'bill_fee_rule_structure_fk')->references(['tenant_id', 'id'])->on('billing_fee_structures')->restrictOnDelete();
        });

        Schema::table('billing_fee_assignments', function (Blueprint $table): void {
            $table->unsignedBigInteger('applicability_rule_id')->nullable()->after('fee_structure_id');
            $table->index(['tenant_id', 'applicability_rule_id'], 'bill_assignment_rule_idx');
            $table->foreign(['tenant_id', 'applicability_rule_id'], 'bill_assignment_rule_fk')->references(['tenant_id', 'id'])->on('billing_fee_applicability_rules')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_fee_assignments', function (Blueprint $table): void {
            $table->dropForeign('bill_assignment_rule_fk');
            $table->dropIndex('bill_assignment_rule_idx');
            $table->dropColumn('applicability_rule_id');
        });
        Schema::dropIfExists('billing_fee_applicability_rules');
    }
};
