<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_staff_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('academic_unit_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->string('employee_number', 48);
            $table->string('designation', 120);
            $table->string('employment_type', 24);
            $table->string('status', 24)->default('active');
            $table->date('joined_on');
            $table->date('separated_on')->nullable();
            $table->text('personal_email')->nullable();
            $table->text('personal_phone')->nullable();
            $table->text('emergency_contact_name')->nullable();
            $table->text('emergency_contact_phone')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'employee_number'], 'hr_staff_employee_number_uq');
            $table->unique(['tenant_id', 'membership_id'], 'hr_staff_membership_uq');
            $table->unique(['tenant_id', 'id'], 'hr_staff_tenant_id_uq');
            $table->index(['tenant_id', 'campus_id', 'status'], 'hr_staff_campus_status_idx');
            $table->index(['tenant_id', 'academic_unit_id', 'status'], 'hr_staff_unit_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'hr_staff_institution_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['membership_id', 'tenant_id'], 'hr_staff_membership_fk')->references(['id', 'tenant_id'])->on('tenant_memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'hr_staff_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_unit_id'], 'hr_staff_unit_fk')->references(['tenant_id', 'id'])->on('academic_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'manager_id'], 'hr_staff_manager_fk')->references(['tenant_id', 'id'])->on('hr_staff_records')->restrictOnDelete();
        });

        Schema::create('hr_staff_employment_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('staff_record_id');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->unsignedBigInteger('from_campus_id')->nullable();
            $table->unsignedBigInteger('to_campus_id')->nullable();
            $table->unsignedBigInteger('from_academic_unit_id')->nullable();
            $table->unsignedBigInteger('to_academic_unit_id')->nullable();
            $table->string('from_designation', 120)->nullable();
            $table->string('to_designation', 120)->nullable();
            $table->string('from_employment_type', 24)->nullable();
            $table->string('to_employment_type', 24)->nullable();
            $table->unsignedBigInteger('from_manager_id')->nullable();
            $table->unsignedBigInteger('to_manager_id')->nullable();
            $table->date('effective_on');
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'staff_record_id', 'effective_on'], 'hr_staff_history_date_idx');
            $table->foreign(['tenant_id', 'staff_record_id'], 'hr_staff_history_employee_fk')->references(['tenant_id', 'id'])->on('hr_staff_records')->restrictOnDelete();
            $table->foreign(['tenant_id', 'from_campus_id'], 'hr_staff_history_from_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'to_campus_id'], 'hr_staff_history_to_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'from_academic_unit_id'], 'hr_staff_history_from_unit_fk')->references(['tenant_id', 'id'])->on('academic_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'to_academic_unit_id'], 'hr_staff_history_to_unit_fk')->references(['tenant_id', 'id'])->on('academic_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'from_manager_id'], 'hr_staff_history_from_manager_fk')->references(['tenant_id', 'id'])->on('hr_staff_records')->restrictOnDelete();
            $table->foreign(['tenant_id', 'to_manager_id'], 'hr_staff_history_to_manager_fk')->references(['tenant_id', 'id'])->on('hr_staff_records')->restrictOnDelete();
        });

        DB::transaction(function (): void {
            $catalog = PermissionCatalog::tenantPermissions();
            $keys = ['hr.staff.view', 'hr.staff.view-own', 'hr.staff.manage', 'hr.staff.sensitive.view', 'hr.staff.status.manage'];
            $rows = array_map(static fn (string $key): array => [
                'key' => $key,
                'description' => $catalog[$key],
                'scope' => 'tenant',
                'created_at' => now(),
                'updated_at' => now(),
            ], $keys);
            DB::table('permissions')->upsert($rows, ['key'], ['description', 'scope', 'updated_at']);
            $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id', 'key');
            $grants = [
                'tenant.owner' => $keys,
                'tenant.admin' => $keys,
                'campus.admin' => ['hr.staff.view', 'hr.staff.manage', 'hr.staff.status.manage'],
                'academic.admin' => ['hr.staff.view'],
                'teacher' => ['hr.staff.view-own'],
                'exam.invigilator' => ['hr.staff.view-own'],
                'exam.controller' => ['hr.staff.view-own'],
                'examiner' => ['hr.staff.view-own'],
                'exam.moderator' => ['hr.staff.view-own'],
                'admissions.officer' => ['hr.staff.view-own'],
                'sis.registrar' => ['hr.staff.view-own'],
                'finance.manager' => ['hr.staff.view-own'],
                'hr.manager' => $keys,
            ];
            foreach (DB::table('roles')->where('scope', 'tenant')->get(['id', 'key']) as $role) {
                foreach ($grants[$role->key] ?? [] as $key) {
                    DB::table('permission_role')->insertOrIgnore([
                        'role_id' => $role->id,
                        'permission_id' => $ids[$key],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_staff_employment_history');
        Schema::dropIfExists('hr_staff_records');
    }
};
