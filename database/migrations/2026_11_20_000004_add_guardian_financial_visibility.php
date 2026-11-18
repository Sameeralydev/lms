<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sis_student_guardians', function (Blueprint $table): void {
            $table->boolean('can_view_financial_records')->default(false)->after('can_view_profile');
        });
    }

    public function down(): void
    {
        Schema::table('sis_student_guardians', function (Blueprint $table): void {
            $table->dropColumn('can_view_financial_records');
        });
    }
};
