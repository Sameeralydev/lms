<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sis_identity_reconciliation_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('current_user_id');
            $table->foreignId('proposed_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->string('reason_code', 48);
            $table->string('request_reason', 500);
            $table->string('decision_reason', 500)->nullable();
            $table->dateTime('requested_at');
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->unsignedBigInteger('pending_student_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'pending_student_id'], 'sis_identity_pending_student_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'requested_at'], 'sis_identity_case_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'sis_identity_case_inst_fk')
                ->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'sis_identity_case_student_fk')
                ->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign('current_user_id', 'sis_identity_case_current_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'pending_student_id'], 'sis_identity_case_pending_fk')
                ->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
        });

        DB::transaction(function (): void {
            $now = now();
            $permissions = [
                'sis.identity.reconcile.request' => 'Request a reviewed correction to a student identity link.',
                'sis.identity.reconcile.approve' => 'Approve or reject student identity-link reconciliation.',
            ];
            foreach ($permissions as $key => $description) {
                DB::table('permissions')->updateOrInsert(
                    ['key' => $key],
                    ['description' => $description, 'scope' => 'tenant', 'created_at' => $now, 'updated_at' => $now],
                );
            }
            $permissionIds = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id', 'key');
            foreach (DB::table('tenants')->pluck('id') as $tenantId) {
                foreach (['tenant.owner', 'tenant.admin'] as $roleKey) {
                    $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('key', $roleKey)->value('id');
                    if ($roleId !== null) {
                        foreach ($permissionIds as $permissionId) {
                            DB::table('permission_role')->insertOrIgnore([
                                'role_id' => $roleId, 'permission_id' => $permissionId,
                                'created_at' => $now, 'updated_at' => $now,
                            ]);
                        }
                    }
                }
                $registrarId = DB::table('roles')->where('tenant_id', $tenantId)->where('key', 'sis.registrar')->value('id');
                if ($registrarId !== null) {
                    DB::table('permission_role')->insertOrIgnore([
                        'role_id' => $registrarId,
                        'permission_id' => $permissionIds['sis.identity.reconcile.request'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sis_identity_reconciliation_cases');
    }
};
