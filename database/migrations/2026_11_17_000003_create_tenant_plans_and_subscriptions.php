<?php

use App\Domain\Identity\PermissionCatalog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name', 140);
            $table->text('description')->nullable();
            $table->json('entitlements');
            $table->json('limits');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('tenant_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('tenant_plan_id')->nullable()->constrained('tenant_plans')->restrictOnDelete();
            $table->string('status', 24)->default('active')->index();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['tenant_plan_id', 'status']);
        });

        Schema::create('tenant_subscription_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('tenant_plan_id')->nullable()->constrained('tenant_plans')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event_type', 50);
            $table->json('details');
            $table->timestamp('occurred_at');
            $table->index(['tenant_id', 'occurred_at']);
        });

        PermissionCatalog::ensure();
        $permissions = Permission::query()->whereIn('key', ['platform.plans.manage', 'platform.subscriptions.manage'])->pluck('id');
        $platformRoles = Role::query()->where('scope', 'platform')->whereIn('key', ['platform.operations_admin', 'platform.super_admin'])->get();
        foreach ($platformRoles as $role) {
            $role->permissions()->syncWithoutDetaching($permissions);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscription_events');
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('tenant_plans');
        $permissionIds = DB::table('permissions')->whereIn('key', ['platform.plans.manage', 'platform.subscriptions.manage'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('key', ['platform.plans.manage', 'platform.subscriptions.manage'])->delete();
    }
};
