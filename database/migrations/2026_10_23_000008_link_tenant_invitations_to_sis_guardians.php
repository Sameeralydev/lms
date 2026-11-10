<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sis_student_guardians', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'sis_guardian_tenant_id_uq');
        });

        Schema::table('tenant_invitations', function (Blueprint $table): void {
            $table->unsignedBigInteger('sis_student_guardian_id')->nullable()->after('sis_student_id');
            $table->index(['tenant_id', 'sis_student_guardian_id', 'status', 'expires_at'], 'tenant_invite_sis_guardian_idx');
            $table->foreign(['tenant_id', 'sis_student_guardian_id'], 'tenant_invite_sis_guardian_fk')
                ->references(['tenant_id', 'id'])->on('sis_student_guardians')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_invitations', function (Blueprint $table): void {
            $table->dropForeign('tenant_invite_sis_guardian_fk');
            $table->dropIndex('tenant_invite_sis_guardian_idx');
            $table->dropColumn('sis_student_guardian_id');
        });

        Schema::table('sis_student_guardians', function (Blueprint $table): void {
            $table->dropUnique('sis_guardian_tenant_id_uq');
        });
    }
};
