<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_adjustments', function (Blueprint $table): void {
            $table->unsignedBigInteger('reverses_adjustment_id')->nullable()->after('invoice_id');
            $table->unique(['tenant_id', 'reverses_adjustment_id'], 'bill_adjustment_reversal_uq');
            $table->foreign(['tenant_id', 'reverses_adjustment_id'], 'bill_adjustment_reverses_fk')
                ->references(['tenant_id', 'id'])->on('billing_adjustments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_adjustments', function (Blueprint $table): void {
            $table->dropForeign('bill_adjustment_reverses_fk');
            $table->dropUnique('bill_adjustment_reversal_uq');
            $table->dropColumn('reverses_adjustment_id');
        });
    }
};
