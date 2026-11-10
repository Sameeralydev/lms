<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sis_registry_settings', function (Blueprint $table): void {
            $table->boolean('suspend_membership_on_student_suspension')->default(true);
        });

        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->unsignedBigInteger('sis_student_suspension_student_id')->nullable();
            $table->timestamp('sis_student_suspended_at')->nullable();
            $table->string('sis_student_suspension_reason_code', 48)->nullable();
            $table->index(
                ['tenant_id', 'sis_student_suspension_student_id', 'status'],
                'tenant_memberships_sis_suspend_idx',
            );
            $table->foreign(['tenant_id', 'sis_student_suspension_student_id'], 'tenant_memberships_sis_suspend_fk')
                ->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->dropForeign('tenant_memberships_sis_suspend_fk');
            $table->dropIndex('tenant_memberships_sis_suspend_idx');
            $table->dropColumn([
                'sis_student_suspension_student_id',
                'sis_student_suspended_at',
                'sis_student_suspension_reason_code',
            ]);
        });

        Schema::table('sis_registry_settings', function (Blueprint $table): void {
            $table->dropColumn('suspend_membership_on_student_suspension');
        });
    }
};
