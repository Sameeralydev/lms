<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_appeal_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('appeal_id');
            $table->string('action', 32);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->unsignedInteger('result_version')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('safe_details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['tenant_id', 'appeal_id'], 'exam_appeal_history_parent_fk')
                ->references(['tenant_id', 'id'])->on('exam_recheck_requests')->restrictOnDelete();
            $table->index(['tenant_id', 'appeal_id', 'id'], 'exam_appeal_history_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_appeal_history');
    }
};
