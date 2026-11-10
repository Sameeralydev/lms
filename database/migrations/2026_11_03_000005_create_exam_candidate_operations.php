<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_candidate_accommodations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('candidate_id');
            $table->string('accommodation_type', 32);
            $table->unsignedSmallInteger('revision')->default(1);
            $table->unsignedSmallInteger('extra_time_minutes')->default(0);
            $table->longText('confidential_details')->nullable();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('authorized_at');
            $table->dateTime('expires_at')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'exam_accommodation_tenant_id_uq');
            $table->unique(['tenant_id', 'candidate_id', 'accommodation_type', 'revision'], 'exam_accommodation_revision_uq');
            $table->foreign(['tenant_id', 'candidate_id'], 'exam_accommodation_candidate_fk')->references(['tenant_id', 'id'])->on('exam_candidates')->cascadeOnDelete();
            $table->index(['tenant_id', 'status', 'expires_at'], 'exam_accommodation_expiry_idx');
        });

        Schema::create('exam_invigilator_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('session_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->string('duty_role', 24)->default('invigilator');
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('assigned');
            $table->dateTime('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'session_id', 'user_id'], 'exam_invigilator_session_user_uq');
            $table->foreign(['tenant_id', 'session_id'], 'exam_invigilator_session_fk')->references(['tenant_id', 'id'])->on('exam_sessions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'room_id'], 'exam_invigilator_room_fk')->references(['tenant_id', 'id'])->on('academic_rooms')->restrictOnDelete();
            $table->index(['tenant_id', 'user_id', 'status'], 'exam_invigilator_user_status_idx');
        });

        Schema::create('exam_attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('candidate_id');
            $table->string('attendance_status', 16);
            $table->string('recording_method', 16)->default('manual');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at');
            $table->text('safe_note')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'session_id', 'candidate_id'], 'exam_attendance_candidate_uq');
            $table->unique(['tenant_id', 'id'], 'exam_attendance_tenant_id_uq');
            $table->foreign(['tenant_id', 'session_id'], 'exam_attendance_session_fk')->references(['tenant_id', 'id'])->on('exam_sessions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'candidate_id'], 'exam_attendance_candidate_fk')->references(['tenant_id', 'id'])->on('exam_candidates')->cascadeOnDelete();
            $table->index(['tenant_id', 'session_id', 'attendance_status'], 'exam_attendance_status_idx');
        });

        Schema::create('exam_attendance_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('attendance_record_id');
            $table->unsignedInteger('revision_number');
            $table->string('previous_status', 16)->nullable();
            $table->string('new_status', 16);
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->text('change_reason')->nullable();
            $table->dateTime('created_at');
            $table->unique(['tenant_id', 'attendance_record_id', 'revision_number'], 'exam_attendance_revision_uq');
            $table->foreign(['tenant_id', 'attendance_record_id'], 'exam_attendance_revision_record_fk')->references(['tenant_id', 'id'])->on('exam_attendance_records')->cascadeOnDelete();
        });

        $this->seedExaminationPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attendance_revisions');
        Schema::dropIfExists('exam_attendance_records');
        Schema::dropIfExists('exam_invigilator_assignments');
        Schema::dropIfExists('exam_candidate_accommodations');
    }

    private function seedExaminationPermissions(): void
    {
        $permissions = [
            'exams.view' => 'View examination cycles and schedules within assigned institution scope.',
            'exams.manage' => 'Create examination cycles and draft examination sessions.',
            'exams.approve' => 'Approve examination cycles after review.',
            'exams.publish' => 'Publish approved examination schedules and candidate registration windows.',
            'exams.candidates.manage' => 'Register eligible students for published examination cycles.',
            'exams.paper.manage' => 'Create confidential examination paper drafts and revisions from approved question versions.',
            'exams.paper.review' => 'Independently review examination paper versions.',
            'exams.paper.publish' => 'Publish independently approved examination paper versions.',
            'exams.paper.confidential.view' => 'Preview confidential examination paper content within the institution.',
            'exams.invigilators.manage' => 'Assign and manage examination invigilator duty rosters.',
            'exams.attendance.record' => 'Record candidate attendance in assigned examination sessions.',
            'exams.accommodation.manage' => 'Authorize confidential candidate examination accommodations.',
            'exams.audit.view' => 'View examination governance audit history.',
        ];
        $now = now();
        foreach ($permissions as $key => $description) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['description' => $description, 'scope' => 'tenant', 'created_at' => $now, 'updated_at' => $now]);
        }
        $permissionIds = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id', 'key');
        $tenantIds = DB::table('tenants')->pluck('id');
        foreach ($tenantIds as $tenantId) {
            foreach (['tenant.owner', 'tenant.admin', 'academic.admin'] as $roleKey) {
                $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('key', $roleKey)->value('id');
                if ($roleId !== null) {
                    foreach ($permissionIds as $permissionId) {
                        DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }
            $templates = [
                'exam.invigilator' => ['Examination Invigilator', ['exams.view', 'exams.attendance.record']],
                'exam.controller' => ['Examination Controller', ['exams.view', 'exams.manage', 'exams.candidates.manage', 'exams.invigilators.manage', 'exams.attendance.record', 'exams.accommodation.manage', 'exams.audit.view']],
            ];
            foreach ($templates as $key => [$name, $permissionKeys]) {
                DB::table('roles')->insertOrIgnore(['tenant_id' => $tenantId, 'key' => $key, 'name' => $name, 'scope' => 'tenant', 'description' => null, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
                $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('key', $key)->value('id');
                $ids = DB::table('permissions')->whereIn('key', $permissionKeys)->pluck('id');
                foreach ($ids as $permissionId) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }
};
