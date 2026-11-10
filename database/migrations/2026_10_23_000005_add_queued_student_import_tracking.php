<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sis_student_import_batches', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'sis_import_tenant_id_uq');
            $table->string('source_path', 500)->nullable()->after('safe_report');
            $table->unsignedInteger('processed_count')->default(0)->after('invalid_count');
        });

        Schema::table('sis_students', function (Blueprint $table): void {
            $table->unsignedBigInteger('import_batch_id')->nullable()->after('admission_offer_id');
            $table->unsignedInteger('import_row_number')->nullable()->after('import_batch_id');
            $table->unique(['tenant_id', 'import_batch_id', 'import_row_number'], 'sis_student_import_row_uq');
            $table->foreign(['tenant_id', 'import_batch_id'], 'sis_student_import_batch_fk')
                ->references(['tenant_id', 'id'])->on('sis_student_import_batches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sis_students', function (Blueprint $table): void {
            $table->dropForeign('sis_student_import_batch_fk');
            $table->dropUnique('sis_student_import_row_uq');
            $table->dropColumn(['import_batch_id', 'import_row_number']);
        });

        Schema::table('sis_student_import_batches', function (Blueprint $table): void {
            $table->dropUnique('sis_import_tenant_id_uq');
            $table->dropColumn(['source_path', 'processed_count']);
        });
    }
};
