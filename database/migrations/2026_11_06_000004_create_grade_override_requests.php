<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_grade_override_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('gradebook_id');
            $table->unsignedBigInteger('entry_id');
            $table->decimal('original_points', 9, 2);
            $table->decimal('proposed_points', 9, 2);
            $table->string('reason', 500);
            $table->string('status', 16)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'grade_override_tenant_id_uq');
            $table->index(['tenant_id', 'gradebook_id', 'status', 'created_at'], 'grade_override_review_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'grade_override_institution_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'gradebook_id'], 'grade_override_book_fk')->references(['tenant_id', 'id'])->on('academic_gradebooks')->restrictOnDelete();
            $table->foreign(['tenant_id', 'entry_id'], 'grade_override_entry_fk')->references(['tenant_id', 'id'])->on('academic_grade_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_grade_override_requests');
    }
};
