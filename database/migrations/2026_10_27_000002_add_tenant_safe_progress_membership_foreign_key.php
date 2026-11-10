<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureMembershipTenantIdentityKey();

        if (! Schema::hasTable('course_delivery_progress')) {
            throw new RuntimeException('Course delivery progress must exist before its membership constraint is added.');
        }

        $existing = array_values(array_filter(
            Schema::getForeignKeys('course_delivery_progress'),
            static fn (array $foreignKey): bool => $foreignKey['name'] === 'delivery_progress_member_fk',
        ));
        if ($existing !== []) {
            $foreignKey = $existing[0];
            if (count($existing) !== 1
                || $foreignKey['columns'] !== ['tenant_id', 'membership_id']
                || $foreignKey['foreign_table'] !== 'tenant_memberships'
                || $foreignKey['foreign_columns'] !== ['tenant_id', 'id']) {
                throw new RuntimeException('The existing course delivery membership foreign key differs from its migration definition.');
            }

            return;
        }

        Schema::table('course_delivery_progress', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'membership_id'], 'delivery_progress_member_fk')
                ->references(['tenant_id', 'id'])->on('tenant_memberships')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('course_delivery_progress')) {
            return;
        }

        if (Schema::hasForeignKey('course_delivery_progress', 'delivery_progress_member_fk')) {
            Schema::table('course_delivery_progress', static fn (Blueprint $table) => $table->dropForeign('delivery_progress_member_fk'));
        }
        // Keep this valid tenant-scoped candidate key; other future composite relations may use it.
    }

    private function ensureMembershipTenantIdentityKey(): void
    {
        $indexes = Schema::getIndexes('tenant_memberships');
        $named = array_values(array_filter($indexes, static fn (array $index): bool => $index['name'] === 'tenant_memberships_tenant_id_id_uq'));
        if ($named !== []) {
            if (count($named) !== 1 || $named[0]['columns'] !== ['tenant_id', 'id'] || ! $named[0]['unique']) {
                throw new RuntimeException('The tenant membership composite key name is already used by an incompatible index.');
            }

            return;
        }

        foreach ($indexes as $index) {
            if ($index['columns'] === ['tenant_id', 'id'] && $index['unique']) {
                throw new RuntimeException('An equivalent tenant membership index exists under an unapproved name.');
            }
        }

        Schema::table('tenant_memberships', static function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'tenant_memberships_tenant_id_id_uq');
        });
    }
};
