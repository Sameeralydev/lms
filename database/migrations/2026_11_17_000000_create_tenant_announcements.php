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
        Schema::create('communications_announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('campus_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 180);
            $table->text('body');
            $table->json('audience_roles');
            $table->string('status', 20)->default('published');
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'comm_announcement_tenant_id_uq');
            $table->foreign(['tenant_id', 'campus_id'])->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'published_at'], 'comm_announcement_publish_idx');
            $table->index(['tenant_id', 'campus_id', 'published_at'], 'comm_announcement_campus_idx');
        });

        Schema::create('communications_announcement_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('announcement_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'announcement_id'])->references(['tenant_id', 'id'])->on('communications_announcements')->cascadeOnDelete();
            $table->unique(['tenant_id', 'announcement_id', 'user_id'], 'comm_announcement_user_uq');
            $table->index(['tenant_id', 'user_id', 'read_at'], 'comm_receipt_inbox_idx');
        });

        DB::transaction(function (): void {
            $catalog = PermissionCatalog::tenantPermissions();
            $keys = ['communications.announcements.view', 'communications.announcements.manage'];
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
                'student' => ['communications.announcements.view'],
                'parent.guardian' => ['communications.announcements.view'],
                'admissions.officer' => ['communications.announcements.view'],
                'sis.registrar' => $keys,
                'auditor' => ['communications.announcements.view'],
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communications_announcement_receipts');
        Schema::dropIfExists('communications_announcements');
    }
};
