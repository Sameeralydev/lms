<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_scope_key')->storedAs('COALESCE(tenant_id, 0)');
            $table->unique(['tenant_scope_key', 'key'], 'roles_scope_key_key_unique');
            $table->unique(['id', 'tenant_id'], 'roles_id_tenant_id_unique');
        });

        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id'], 'tenant_memberships_id_tenant_id_unique');
        });

        Schema::table('membership_role', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('membership_id')->constrained()->restrictOnDelete();
        });

        DB::statement('UPDATE membership_role SET tenant_id = (SELECT tenant_id FROM tenant_memberships WHERE tenant_memberships.id = membership_role.membership_id)');

        Schema::table('membership_role', function (Blueprint $table): void {
            $table->foreign(['membership_id', 'tenant_id'], 'membership_role_membership_tenant_fk')
                ->references(['id', 'tenant_id'])->on('tenant_memberships')->cascadeOnDelete();
            $table->foreign(['role_id', 'tenant_id'], 'membership_role_role_tenant_fk')
                ->references(['id', 'tenant_id'])->on('roles')->restrictOnDelete();
            $table->index(['tenant_id', 'role_id'], 'membership_role_tenant_role_index');
        });
    }

    public function down(): void
    {
        Schema::table('membership_role', function (Blueprint $table): void {
            $table->dropForeign('membership_role_membership_tenant_fk');
            $table->dropForeign('membership_role_role_tenant_fk');
            $table->dropIndex('membership_role_tenant_role_index');
            $table->dropColumn('tenant_id');
        });

        Schema::table('tenant_memberships', function (Blueprint $table): void {
            $table->dropUnique('tenant_memberships_id_tenant_id_unique');
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_scope_key_key_unique');
            $table->dropUnique('roles_id_tenant_id_unique');
            $table->dropColumn('tenant_scope_key');
        });
    }
};
