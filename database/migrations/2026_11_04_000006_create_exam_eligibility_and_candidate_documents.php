<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_eligibility_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('name', 120);
            $table->boolean('require_active_student')->default(true);
            $table->boolean('require_confirmed_enrollment')->default(true);
            $table->boolean('active')->default(true);
            $table->foreign(['tenant_id', 'institution_id'])->references(['tenant_id', 'id'])->on('institutions')->cascadeOnDelete();
            $table->unique(['tenant_id', 'institution_id'], 'exam_eligibility_policy_institution_uq');
            $table->timestamps();
        });

        Schema::create('exam_eligibility_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('cycle_id');
            $table->string('rule_key', 48);
            $table->text('student_reason');
            $table->string('status', 16)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->foreign(['tenant_id', 'candidate_id'])->references(['tenant_id', 'id'])->on('exam_candidates')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'cycle_id'])->references(['tenant_id', 'id'])->on('exam_cycles')->cascadeOnDelete();
            $table->index(['tenant_id', 'candidate_id', 'cycle_id', 'status'], 'exam_eligibility_exception_lookup_idx');
            $table->timestamps();
        });

        Schema::create('exam_accommodation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('candidate_id');
            $table->string('accommodation_type', 32);
            $table->unsignedSmallInteger('extra_time_minutes')->default(0);
            $table->longText('confidential_details')->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->unsignedBigInteger('accommodation_id')->nullable();
            $table->foreign(['tenant_id', 'candidate_id'])->references(['tenant_id', 'id'])->on('exam_candidates')->cascadeOnDelete();
            $table->index(['tenant_id', 'candidate_id', 'status'], 'exam_accommodation_request_status_idx');
            $table->timestamps();
        });

        Schema::create('exam_admit_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedSmallInteger('revision')->default(1);
            $table->char('verification_hash', 64)->unique();
            $table->longText('verification_code_encrypted');
            $table->json('safe_snapshot');
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('issued_at');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->foreign(['tenant_id', 'candidate_id'])->references(['tenant_id', 'id'])->on('exam_candidates')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'session_id'])->references(['tenant_id', 'id'])->on('exam_sessions')->cascadeOnDelete();
            $table->unique(['tenant_id', 'candidate_id', 'session_id', 'revision'], 'exam_admit_document_revision_uq');
            $table->index(['tenant_id', 'session_id', 'revoked_at'], 'exam_admit_document_session_idx');
            $table->timestamps();
        });

        $permissions = [
            'exams.eligibility.manage' => 'Configure and review examination eligibility policies and exceptions.',
            'exams.accommodation.request-own' => 'Request a confidential accommodation for the signed-in student.',
            'exams.admit.issue' => 'Issue and revoke examination admit documents.',
        ];
        foreach ($permissions as $key => $description) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['description' => $description, 'scope' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            foreach (['tenant.owner', 'tenant.admin', 'academic.admin', 'exam.controller'] as $roleKey) {
                $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('key', $roleKey)->value('id');
                if ($roleId) {
                    foreach (DB::table('permissions')->whereIn('key', ['exams.eligibility.manage', 'exams.admit.issue'])->pluck('id') as $permissionId) {
                        DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
            $studentRole = DB::table('roles')->where('tenant_id', $tenantId)->where('key', 'student')->value('id');
            $requestPermission = DB::table('permissions')->where('key', 'exams.accommodation.request-own')->value('id');
            if ($studentRole && $requestPermission) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $studentRole, 'permission_id' => $requestPermission, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_admit_documents');
        Schema::dropIfExists('exam_accommodation_requests');
        Schema::dropIfExists('exam_eligibility_exceptions');
        Schema::dropIfExists('exam_eligibility_policies');
        DB::table('permissions')->whereIn('key', ['exams.eligibility.manage', 'exams.accommodation.request-own', 'exams.admit.issue'])->delete();
    }
};
