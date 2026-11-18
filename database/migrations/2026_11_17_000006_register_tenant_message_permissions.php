<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $keys = ['communications.messages.view', 'communications.messages.send'];
        $catalog = PermissionCatalog::tenantPermissions();
        $rows = array_map(static fn (string $key): array => [
            'key' => $key,
            'description' => $catalog[$key],
            'scope' => 'tenant',
            'created_at' => now(),
            'updated_at' => now(),
        ], $keys);
        DB::table('permissions')->upsert($rows, ['key'], ['description', 'scope', 'updated_at']);
        $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id', 'key');
        $grants = [
            'tenant.owner' => $keys,
            'tenant.admin' => $keys,
            'campus.admin' => $keys,
            'academic.admin' => $keys,
            'teacher' => $keys,
            'student' => $keys,
            'parent.guardian' => $keys,
            'sis.registrar' => $keys,
        ];
        foreach (DB::table('roles')->where('scope', 'tenant')->get(['id', 'key']) as $role) {
            foreach ($grants[$role->key] ?? [] as $key) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $role->id,
                    'permission_id' => $ids[$key],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', ['communications.messages.view', 'communications.messages.send'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('key', ['communications.messages.view', 'communications.messages.send'])->delete();
    }
};
