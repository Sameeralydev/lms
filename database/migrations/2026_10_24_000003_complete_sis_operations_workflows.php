<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sis_registry_settings', function (Blueprint $table): void {
            $table->boolean('lifecycle_requires_approval')->default(false);
            $table->unsignedInteger('document_archive_after_days')->nullable();
            $table->unsignedInteger('document_purge_after_days')->nullable();
        });

        Schema::table('admission_outbox_events', function (Blueprint $table): void {
            $table->dateTime('locked_at')->nullable();
            $table->index(['status', 'available_at', 'locked_at'], 'adm_outbox_claim_idx');
        });

        Schema::table('sis_private_document_versions', function (Blueprint $table): void {
            $table->string('storage_status', 16)->default('active');
            $table->string('archived_path', 500)->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->dateTime('purged_at')->nullable();
            $table->index(['tenant_id', 'storage_status', 'expires_at'], 'sis_doc_retention_due_idx');
        });

        Schema::create('sis_student_import_row_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedInteger('source_row');
            $table->json('errors');
            $table->timestamps();
            $table->unique(['tenant_id', 'batch_id', 'source_row'], 'sis_import_error_row_uq');
            $table->foreign(['tenant_id', 'batch_id'], 'sis_import_error_batch_fk')
                ->references(['tenant_id', 'id'])->on('sis_student_import_batches')->cascadeOnDelete();
        });

        Schema::create('sis_student_lifecycle_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('student_id');
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->dateTime('effective_at');
            $table->string('reason_code', 48);
            $table->string('reason', 500)->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('decision_reason', 500)->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->unsignedBigInteger('pending_student_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'sis_lifecycle_request_tenant_id_uq');
            $table->unique(['tenant_id', 'pending_student_id'], 'sis_lifecycle_pending_student_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'created_at'], 'sis_lifecycle_review_queue_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'sis_lifecycle_request_inst_fk')
                ->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'sis_lifecycle_request_student_fk')
                ->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'pending_student_id'], 'sis_lifecycle_request_pending_fk')
                ->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
        });

        Schema::create('sis_private_document_retention_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('document_version_id');
            $table->string('action', 16);
            $table->string('reason_code', 48);
            $table->char('file_sha256', 64);
            $table->string('storage_path', 500)->nullable();
            $table->dateTime('completed_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'document_version_id', 'action'], 'sis_doc_retention_action_uq');
            $table->index(['tenant_id', 'action', 'completed_at'], 'sis_doc_retention_history_idx');
            $table->foreign(['tenant_id', 'document_version_id'], 'sis_doc_retention_version_fk')
                ->references(['tenant_id', 'id'])->on('sis_private_document_versions')->restrictOnDelete();
        });

        DB::transaction(function (): void {
            $now = now();
            DB::table('permissions')->updateOrInsert(
                ['key' => 'sis.lifecycle.approve'],
                ['description' => 'Independently approve or reject student lifecycle transitions.', 'scope' => 'tenant', 'created_at' => $now, 'updated_at' => $now],
            );
            $permissionId = DB::table('permissions')->where('key', 'sis.lifecycle.approve')->value('id');
            foreach (DB::table('tenants')->pluck('id') as $tenantId) {
                foreach (['tenant.owner', 'tenant.admin'] as $roleKey) {
                    $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('key', $roleKey)->value('id');
                    if ($roleId !== null) {
                        DB::table('permission_role')->insertOrIgnore([
                            'role_id' => $roleId, 'permission_id' => $permissionId,
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sis_private_document_retention_events');
        Schema::dropIfExists('sis_student_lifecycle_requests');
        Schema::dropIfExists('sis_student_import_row_errors');
        Schema::table('sis_private_document_versions', function (Blueprint $table): void {
            $table->dropIndex('sis_doc_retention_due_idx');
            $table->dropColumn(['storage_status', 'archived_path', 'archived_at', 'purged_at']);
        });
        Schema::table('admission_outbox_events', function (Blueprint $table): void {
            $table->dropIndex('adm_outbox_claim_idx');
            $table->dropColumn('locked_at');
        });
        Schema::table('sis_registry_settings', function (Blueprint $table): void {
            $table->dropColumn(['lifecycle_requires_approval', 'document_archive_after_days', 'document_purge_after_days']);
        });
        // Permission removal is intentionally omitted to preserve tenant role/customization state.
    }
};
