<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sis_student_promotion_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('student_id');
            $table->string('status', 16)->default('active');
            $table->string('reason_code', 48);
            $table->string('reason', 500);
            $table->string('evidence_reference', 160)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('released_at')->nullable();
            $table->string('release_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'sis_promo_hold_tenant_id_uq');
            $table->index(['tenant_id', 'institution_id', 'student_id', 'status'], 'sis_promo_hold_active_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'sis_promo_hold_inst_fk')
                ->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'sis_promo_hold_student_fk')
                ->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
        });

        Schema::table('sis_registry_settings', function (Blueprint $table): void {
            $table->boolean('promotion_requires_approval')->default(false);
        });

        DB::transaction(function (): void {
            $now = now();
            $permissions = [
                'sis.promotion.approve' => 'Independently approve or reject promotion decisions when the institution requires review.',
                'sis.promotion.holds.manage' => 'Place and release documented student progression holds.',
                'sis.promotion.correct' => 'Record an authorized compensating correction to a student promotion.',
            ];
            foreach ($permissions as $key => $description) {
                DB::table('permissions')->updateOrInsert(
                    ['key' => $key],
                    ['description' => $description, 'scope' => 'tenant', 'created_at' => $now, 'updated_at' => $now],
                );
            }
            $permissionIds = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
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
            }
        });
    }

    public function down(): void
    {
        Schema::table('sis_registry_settings', function (Blueprint $table): void {
            $table->dropColumn('promotion_requires_approval');
        });
        Schema::dropIfExists('sis_student_promotion_holds');
    }
};
