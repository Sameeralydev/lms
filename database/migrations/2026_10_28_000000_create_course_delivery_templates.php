<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_delivery_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('name', 180);
            $table->string('slug', 200);
            $table->text('description')->nullable();
            $table->unsignedInteger('current_version')->default(0);
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'delivery_template_scope_id_uq');
            $table->unique(['tenant_id', 'institution_id', 'slug'], 'delivery_template_slug_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'delivery_template_institution_fk')
                ->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->index(['tenant_id', 'institution_id', 'status', 'updated_at'], 'delivery_template_list_idx');
        });

        Schema::create('course_delivery_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('template_id');
            $table->unsignedInteger('version_number');
            $table->unsignedBigInteger('source_workspace_id');
            $table->json('content_snapshot');
            $table->char('content_checksum', 64);
            $table->foreignId('authored_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'template_id', 'version_number'], 'delivery_template_version_uq');
            $table->unique(['tenant_id', 'template_id', 'version_number', 'id'], 'delivery_template_version_scope_uq');
            $table->foreign(['tenant_id', 'template_id'], 'delivery_template_version_template_fk')
                ->references(['tenant_id', 'id'])->on('course_delivery_templates')->restrictOnDelete();
            $table->foreign(['tenant_id', 'source_workspace_id'], 'delivery_template_source_workspace_fk')
                ->references(['tenant_id', 'id'])->on('course_delivery_workspaces')->restrictOnDelete();
            $table->index(['tenant_id', 'template_id', 'published_at'], 'delivery_template_version_list_idx');
        });

        Schema::create('course_delivery_template_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('institution_id');
            $table->foreignId('shared_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'template_id', 'institution_id'], 'delivery_template_share_uq');
            $table->foreign(['tenant_id', 'template_id'], 'delivery_template_share_template_fk')
                ->references(['tenant_id', 'id'])->on('course_delivery_templates')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'institution_id'], 'delivery_template_share_institution_fk')
                ->references(['tenant_id', 'id'])->on('institutions')->cascadeOnDelete();
            $table->index(['tenant_id', 'institution_id', 'template_id'], 'delivery_template_share_lookup_idx');
        });

        Schema::create('course_delivery_copy_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->char('idempotency_key', 36);
            $table->unsignedBigInteger('template_id');
            $table->unsignedInteger('template_version');
            $table->unsignedBigInteger('destination_workspace_id');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->json('selection');
            $table->json('result_map')->nullable();
            $table->string('status', 16)->default('completed');
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key'], 'delivery_copy_idempotency_uq');
            $table->unique(['tenant_id', 'id'], 'delivery_copy_scope_id_uq');
            $table->foreign(['tenant_id', 'template_id', 'template_version'], 'delivery_copy_template_version_fk')
                ->references(['tenant_id', 'template_id', 'version_number'])->on('course_delivery_template_versions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'destination_workspace_id'], 'delivery_copy_destination_fk')
                ->references(['tenant_id', 'id'])->on('course_delivery_workspaces')->restrictOnDelete();
            $table->index(['tenant_id', 'destination_workspace_id', 'created_at'], 'delivery_copy_destination_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_delivery_copy_operations');
        Schema::dropIfExists('course_delivery_template_shares');
        Schema::dropIfExists('course_delivery_template_versions');
        Schema::dropIfExists('course_delivery_templates');
    }
};
