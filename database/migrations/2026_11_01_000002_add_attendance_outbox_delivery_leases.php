<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_outbox_events', function (Blueprint $table): void {
            $table->dateTime('locked_at')->nullable()->after('available_at');
            $table->index(['status', 'locked_at'], 'att_outbox_lease_idx');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_outbox_events', function (Blueprint $table): void {
            $table->dropIndex('att_outbox_lease_idx');
            $table->dropColumn('locked_at');
        });
    }
};
