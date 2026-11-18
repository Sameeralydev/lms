<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communications_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_one_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('member_two_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'comm_conversation_tenant_id_uq');
            $table->unique(['tenant_id', 'member_one_user_id', 'member_two_user_id'], 'comm_conversation_pair_uq');
            $table->index(['tenant_id', 'updated_at'], 'comm_conversation_recent_idx');
        });

        Schema::create('communications_conversation_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('conversation_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->dateTime('last_read_at')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('communications_conversations')->cascadeOnDelete();
            $table->unique(['tenant_id', 'conversation_id', 'user_id'], 'comm_conversation_member_uq');
            $table->index(['tenant_id', 'user_id', 'last_read_at'], 'comm_member_inbox_idx');
        });

        Schema::create('communications_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('conversation_id');
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('communications_conversations')->cascadeOnDelete();
            $table->index(['tenant_id', 'conversation_id', 'created_at'], 'comm_message_thread_idx');
        });

        Schema::create('communications_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->unsignedBigInteger('reference_id');
            $table->unsignedBigInteger('context_id')->nullable();
            $table->string('title', 180);
            $table->string('summary', 500);
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'type', 'reference_id'], 'comm_notification_recipient_ref_uq');
            $table->index(['tenant_id', 'user_id', 'read_at', 'created_at'], 'comm_notification_inbox_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communications_notifications');
        Schema::dropIfExists('communications_messages');
        Schema::dropIfExists('communications_conversation_members');
        Schema::dropIfExists('communications_conversations');
    }
};
