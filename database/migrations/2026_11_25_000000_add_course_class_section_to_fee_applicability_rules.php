<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_fee_applicability_rules', function (Blueprint $table): void {
            $table->unsignedBigInteger('academic_course_id')->nullable();
            $table->unsignedBigInteger('academic_class_id')->nullable();
            $table->unsignedBigInteger('academic_section_id')->nullable();
            $table->index(['tenant_id', 'institution_id', 'status', 'academic_course_id', 'academic_class_id', 'academic_section_id'], 'bill_fee_rule_entity_scope_idx');
            $table->foreign(['tenant_id', 'academic_course_id'], 'bill_fee_rule_course_fk')->references(['tenant_id', 'id'])->on('academic_courses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_class_id'], 'bill_fee_rule_class_fk')->references(['tenant_id', 'id'])->on('academic_classes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_section_id'], 'bill_fee_rule_section_fk')->references(['tenant_id', 'id'])->on('academic_sections')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_fee_applicability_rules', function (Blueprint $table): void {
            $table->dropForeign('bill_fee_rule_section_fk');
            $table->dropForeign('bill_fee_rule_class_fk');
            $table->dropForeign('bill_fee_rule_course_fk');
            $table->dropIndex('bill_fee_rule_entity_scope_idx');
            $table->dropColumn(['academic_course_id', 'academic_class_id', 'academic_section_id']);
        });
    }
};
