<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sis_student_transfer_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->string('direction', 16);
            $table->string('status', 20)->default('pending_review');
            $table->string('external_institution_name', 180);
            $table->string('external_institution_code', 80)->nullable();
            $table->char('external_country_code', 2)->nullable();
            $table->date('requested_effective_on');
            $table->string('request_reason', 500);
            $table->unsignedBigInteger('evidence_document_id');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_decision', 12)->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->string('completion_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'sis_transfer_tenant_id_uq');
            $table->index(['tenant_id', 'student_id', 'status', 'requested_at'], 'sis_transfer_student_status_idx');
            $table->index(['tenant_id', 'direction', 'status', 'requested_at'], 'sis_transfer_review_queue_idx');
            $table->foreign(['tenant_id', 'student_id'], 'sis_transfer_student_fk')
                ->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'evidence_document_id'], 'sis_transfer_evidence_fk')
                ->references(['tenant_id', 'id'])->on('sis_private_documents')->restrictOnDelete();
        });

        DB::transaction(function (): void {
            $now = now();
            DB::table('permissions')->updateOrInsert(
                ['key' => 'sis.transfers.manage'],
                ['description' => 'Submit external student transfer requests and manage their records.', 'scope' => 'tenant', 'created_at' => $now, 'updated_at' => $now],
            );
            DB::table('permissions')->updateOrInsert(
                ['key' => 'sis.transfers.approve'],
                ['description' => 'Independently approve or reject external student transfer requests.', 'scope' => 'tenant', 'created_at' => $now, 'updated_at' => $now],
            );
            $manageId = DB::table('permissions')->where('key', 'sis.transfers.manage')->value('id');
            $approveId = DB::table('permissions')->where('key', 'sis.transfers.approve')->value('id');

            foreach (DB::table('tenants')->pluck('id') as $tenantId) {
                $tenantId = (int) $tenantId;
                foreach (['tenant.owner', 'tenant.admin'] as $roleKey) {
                    $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('key', $roleKey)->value('id');
                    if ($roleId !== null) {
                        DB::table('permission_role')->insertOrIgnore([
                            'role_id' => $roleId, 'permission_id' => $manageId, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                        DB::table('permission_role')->insertOrIgnore([
                            'role_id' => $roleId, 'permission_id' => $approveId, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }

                $registrarId = DB::table('roles')->where('tenant_id', $tenantId)->where('key', 'sis.registrar')->value('id');
                if ($registrarId !== null) {
                    DB::table('permission_role')->insertOrIgnore([
                        'role_id' => $registrarId, 'permission_id' => $manageId, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Forward-only: transfer records and role grants are not removed by a rollback.
    }
};
