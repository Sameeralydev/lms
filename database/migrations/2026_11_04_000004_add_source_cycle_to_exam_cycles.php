<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_cycles', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_cycle_id')->nullable();
            $table->index(['tenant_id', 'source_cycle_id'], 'exam_cycle_source_idx');
            $table->foreign(['tenant_id', 'source_cycle_id'], 'exam_cycle_source_fk')
                ->references(['tenant_id', 'id'])->on('exam_cycles')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exam_cycles', function (Blueprint $table): void {
            $table->dropForeign('exam_cycle_source_fk');
            $table->dropIndex('exam_cycle_source_idx');
            $table->dropColumn('source_cycle_id');
        });
    }
};
