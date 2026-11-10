<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_curriculum_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('curriculum_id');
            $table->unsignedBigInteger('curriculum_version_id');
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('queued');
            $table->string('format', 8);
            $table->char('source_checksum', 64);
            $table->unsignedInteger('expected_lock_version');
            $table->unsignedSmallInteger('total_count');
            $table->unsignedSmallInteger('processed_count')->default(0);
            $table->unsignedSmallInteger('imported_count')->default(0);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('manual_retry_count')->default(0);
            $table->string('failure_code', 48)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'curr_import_batch_tenant_id_uq');
            $table->index(['tenant_id', 'curriculum_id', 'created_at'], 'curr_import_curriculum_idx');
            $table->index(['tenant_id', 'status', 'created_at'], 'curr_import_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'curr_import_institution_fk')
                ->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'curriculum_id'], 'curr_import_curriculum_fk')
                ->references(['tenant_id', 'id'])->on('academic_curricula')->restrictOnDelete();
            $table->foreign(['tenant_id', 'curriculum_id', 'curriculum_version_id'], 'curr_import_version_fk')
                ->references(['tenant_id', 'curriculum_id', 'id'])->on('academic_curriculum_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_curriculum_import_batches');
    }
};
