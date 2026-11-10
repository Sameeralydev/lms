<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_guardian_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('application_id');
            $table->foreignId('applicant_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('guardian_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guardian_name', 180);
            $table->string('guardian_email', 254);
            $table->string('relationship', 48);
            $table->char('token_hash', 64)->nullable()->unique('adm_guardian_token_hash_uq');
            $table->string('status', 32)->default('invited');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('email_verified_at')->nullable();
            $table->foreignId('relationship_verified_by')->nullable();
            $table->dateTime('relationship_verified_at')->nullable();
            $table->string('verification_method', 32)->nullable();
            $table->foreignId('revoked_by')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            // Explicit compact identifiers stay within MySQL's 64-character limit.
            $table->foreign('relationship_verified_by', 'adm_guardian_verified_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('revoked_by', 'adm_guardian_revoked_by_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->foreign(['tenant_id', 'application_id'], 'adm_guardian_app_fk')
                ->references(['tenant_id', 'id'])->on('admission_applications')->restrictOnDelete();
            $table->unique(['tenant_id', 'id'], 'adm_guardian_tenant_id_uq');
            $table->index(['tenant_id', 'application_id', 'status'], 'adm_guardian_app_status_idx');
            $table->index(['tenant_id', 'guardian_user_id', 'status'], 'adm_guardian_user_status_idx');
            $table->index(['tenant_id', 'guardian_email', 'status'], 'adm_guardian_email_status_idx');
        });

        DB::transaction(function (): void {
            $now = now();
            DB::table('permissions')->upsert([[
                'key' => 'admissions.guardians.verify',
                'description' => 'Verify a guardian relationship through the institution process.',
                'scope' => 'tenant',
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['key'], ['description', 'scope', 'updated_at']);
            $permissionId = DB::table('permissions')->where('key', 'admissions.guardians.verify')->value('id');
            if ($permissionId === null) {
                return;
            }

            foreach (DB::table('tenants')->pluck('id') as $tenantId) {
                $roleIds = DB::table('roles')->where('tenant_id', $tenantId)->whereIn('key', ['tenant.owner', 'tenant.admin'])
                    ->where('scope', 'tenant')->where('is_system', true)->pluck('id');
                foreach ($roleIds as $roleId) {
                    DB::table('permission_role')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_guardian_authorizations');
        // Permission rows are retained so rollback cannot remove grants used elsewhere.
    }
};
