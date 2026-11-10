<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_invitations', function (Blueprint $table): void {
            $table->unsignedBigInteger('sis_student_id')->nullable()->after('institution_id');
            $table->index(['tenant_id', 'sis_student_id', 'status', 'expires_at'], 'tenant_invite_sis_lookup_idx');
            $table->foreign(['tenant_id', 'sis_student_id'], 'tenant_invite_sis_student_fk')
                ->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_invitations', function (Blueprint $table): void {
            $table->dropForeign('tenant_invite_sis_student_fk');
            $table->dropIndex('tenant_invite_sis_lookup_idx');
            $table->dropColumn('sis_student_id');
        });
    }
};
