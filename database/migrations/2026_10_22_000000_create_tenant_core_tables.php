<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name', 180);
            $table->string('slug', 100)->unique();
            $table->string('status', 24)->index();
            $table->string('suspension_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('institutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->restrictOnDelete();
            $table->string('official_name', 180);
            $table->string('display_name', 140);
            $table->string('code', 40)->nullable();
            $table->string('type', 40);
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('website', 255)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 16)->default('en');
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('tenant_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('domain', 253)->unique();
            $table->string('verification_token', 80);
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 24)->default('pending');
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('campuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 180);
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['institution_id', 'code']);
            $table->index(['tenant_id', 'status', 'name']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'institution_id'])->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('tenant_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'status', 'institution_id']);
            $table->foreign(['tenant_id', 'institution_id'])->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'])->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        Schema::create('tenant_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('key', 100);
            $table->json('value');
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('tenant_brandings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->restrictOnDelete();
            $table->string('display_name', 140)->nullable();
            $table->string('logo_path', 500)->nullable();
            $table->string('favicon_path', 500)->nullable();
            $table->char('primary_color', 7)->default('#155EEF');
            $table->char('accent_color', 7)->default('#06B6D4');
            $table->string('theme', 40)->default('executive-navy');
            $table->timestamps();
        });

        Schema::create('tenant_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('email')->index();
            $table->string('role_key', 80);
            $table->string('token_hash', 64)->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'expires_at']);
            $table->foreign(['tenant_id', 'institution_id'])->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'])->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 120)->index();
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 80)->nullable();
            $table->string('request_id', 80)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['tenant_id', 'occurred_at']);
        });

        Schema::create('user_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('key', 80);
            $table->json('value');
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('tenant_invitations');
        Schema::dropIfExists('tenant_brandings');
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('campuses');
        Schema::dropIfExists('tenant_domains');
        Schema::dropIfExists('institutions');
        Schema::dropIfExists('tenants');
    }
};
