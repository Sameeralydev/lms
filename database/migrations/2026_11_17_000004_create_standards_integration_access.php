<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_api_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->char('token_hash', 64)->unique();
            $table->string('token_prefix', 24);
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'revoked_at', 'expires_at'], 'integration_credential_active_idx');
        });

        Schema::create('integration_external_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id');
            $table->uuid('sourced_id');
            $table->timestamps();
            $table->unique(['tenant_id', 'entity_type', 'entity_id'], 'integration_external_entity_uq');
            $table->unique(['tenant_id', 'sourced_id'], 'integration_external_source_uq');
            $table->index(['tenant_id', 'entity_type', 'sourced_id'], 'integration_external_lookup_idx');
        });

        PermissionCatalog::ensure();
        DB::transaction(function (): void {
            $permissionIds = DB::table('permissions')->whereIn('key', ['integrations.view', 'integrations.manage'])->pluck('id', 'key');
            $view = $permissionIds->only(['integrations.view'])->values()->all();
            $manage = $permissionIds->values()->all();
            foreach (DB::table('roles')->where('scope', 'tenant')->whereIn('key', ['tenant.owner', 'tenant.admin', 'academic.admin'])->get(['id', 'key']) as $role) {
                $ids = $role->key === 'academic.admin' ? $view : $manage;
                foreach ($ids as $permissionId) {
                    DB::table('permission_role')->insertOrIgnore([
                        'permission_id' => $permissionId,
                        'role_id' => $role->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_external_identifiers');
        Schema::dropIfExists('integration_api_credentials');
        $ids = DB::table('permissions')->whereIn('key', ['integrations.view', 'integrations.manage'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('key', ['integrations.view', 'integrations.manage'])->delete();
    }
};
