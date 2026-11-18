<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_installment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('plan_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 32);
            $table->unsignedInteger('version');
            $table->string('request_key', 100)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->text('reason')->nullable();
            $table->json('snapshot');
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'request_key'], 'bill_installment_event_request_uq');
            $table->index(['tenant_id', 'plan_id', 'version'], 'bill_installment_event_version_idx');
            $table->foreign(['tenant_id', 'plan_id'], 'bill_installment_event_plan_fk')
                ->references(['tenant_id', 'id'])->on('billing_installment_plans')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_installment_events');
    }
};
