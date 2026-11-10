<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempt_responses', function (Blueprint $table): void {
            $table->boolean('marked_for_review')->default(false)->after('lock_version');
        });
        Schema::create('quiz_learner_accommodations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quiz_id');
            $table->unsignedBigInteger('membership_id');
            $table->unsignedInteger('extra_time_seconds')->default(0);
            $table->dateTime('availability_ends_at')->nullable();
            $table->string('response_format', 32)->nullable();
            $table->unsignedInteger('break_allowance_seconds')->default(0);
            $table->unsignedSmallInteger('break_count')->default(0);
            $table->longText('confidential_reason')->nullable();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'quiz_id', 'membership_id'], 'qla_quiz_member_uq');
            $table->foreign(['tenant_id', 'quiz_id'], 'qla_quiz_fk')->references(['tenant_id', 'id'])->on('quizzes')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'membership_id'], 'qla_member_fk')->references(['tenant_id', 'id'])->on('tenant_memberships')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_learner_accommodations');
        Schema::table('quiz_attempt_responses', function (Blueprint $table): void {
            $table->dropColumn('marked_for_review');
        });
    }
};
