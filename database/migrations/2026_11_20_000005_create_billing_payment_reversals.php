<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'billing.payments.reverse.request', 'billing.payments.reverse.approve', 'billing.payments.reverse.execute',
    ];

    public function up(): void
    {
        Schema::create('billing_payment_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('payment_id');
            $table->string('reversal_number', 48);
            $table->string('request_key', 80);
            $table->string('status', 24);
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('requested_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('executed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'payment_reversal_tenant_id_uq');
            $table->unique(['tenant_id', 'reversal_number'], 'payment_reversal_number_uq');
            $table->unique(['tenant_id', 'request_key'], 'payment_reversal_request_key_uq');
            $table->index(['tenant_id', 'status', 'requested_at'], 'payment_reversal_status_idx');
            $table->index(['tenant_id', 'payment_id', 'status'], 'payment_reversal_payment_idx');
            $table->foreign(['tenant_id', 'payment_id'], 'payment_reversal_payment_fk')->references(['tenant_id', 'id'])->on('billing_payments')->restrictOnDelete();
        });

        DB::transaction(function (): void {
            $catalog = PermissionCatalog::tenantPermissions();
            $rows = [];
            foreach (self::PERMISSIONS as $key) {
                if (! isset($catalog[$key])) {
                    throw new RuntimeException("Payment reversal permission {$key} is missing from PermissionCatalog.");
                }
                $rows[] = ['key' => $key, 'description' => $catalog[$key], 'scope' => 'tenant', 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('permissions')->upsert($rows, ['key'], ['description', 'scope', 'updated_at']);
            $ids = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id', 'key');
            foreach (DB::table('roles')->where('scope', 'tenant')->where('is_system', true)->whereIn('key', ['tenant.owner', 'tenant.admin', 'finance.manager'])->get(['id']) as $role) {
                foreach ($ids as $permissionId) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payment_reversals');
        // Permission records are retained to avoid revoking independently assigned grants.
    }
};
