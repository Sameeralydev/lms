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
        Schema::create('communications_notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 40);
            $table->boolean('in_app_enabled')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'event_type'], 'comm_pref_recipient_event_uq');
            $table->index(['tenant_id', 'event_type', 'in_app_enabled'], 'comm_pref_delivery_idx');
        });

        DB::transaction(function (): void {
            $catalog = PermissionCatalog::tenantPermissions();
            $key = 'communications.preferences.manage-own';
            DB::table('permissions')->upsert([[
                'key' => $key, 'description' => $catalog[$key], 'scope' => 'tenant',
                'created_at' => now(), 'updated_at' => now(),
            ]], ['key'], ['description', 'scope', 'updated_at']);
            $permissionId = DB::table('permissions')->where('key', $key)->value('id');
            $sourceIds = DB::table('permissions')->whereIn('key', [
                'communications.announcements.view', 'communications.messages.view',
            ])->pluck('id');
            $roleIds = DB::table('permission_role')->whereIn('permission_id', $sourceIds)->distinct()->pluck('role_id');
            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleId, 'permission_id' => $permissionId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communications_notification_preferences');
        // Permission and role assignments are retained to avoid undoing later administrative changes.
    }
};
