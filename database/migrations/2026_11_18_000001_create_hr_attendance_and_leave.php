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
        Schema::create('hr_leave_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 100);
            $table->unsignedSmallInteger('max_calendar_days')->nullable();
            $table->boolean('requires_approval')->default(true);
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'hr_leave_type_tenant_id_uq');
            $table->unique(['tenant_id', 'code'], 'hr_leave_type_code_uq');
            $table->index(['tenant_id', 'status', 'name'], 'hr_leave_type_status_idx');
        });

        Schema::create('hr_leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('staff_record_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('leave_type_id');
            $table->date('start_on');
            $table->date('end_on');
            $table->unsignedSmallInteger('calendar_days');
            $table->text('reason')->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'hr_leave_request_tenant_id_uq');
            $table->index(['tenant_id', 'status', 'start_on'], 'hr_leave_review_idx');
            $table->index(['tenant_id', 'campus_id', 'status', 'start_on'], 'hr_leave_campus_review_idx');
            $table->index(['tenant_id', 'staff_record_id', 'start_on', 'end_on'], 'hr_leave_staff_range_idx');
            $table->foreign(['tenant_id', 'staff_record_id'])->references(['tenant_id', 'id'])->on('hr_staff_records')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'])->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'leave_type_id'])->references(['tenant_id', 'id'])->on('hr_leave_types')->restrictOnDelete();
        });

        Schema::create('hr_staff_attendance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('staff_record_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->date('work_date');
            $table->dateTime('clock_in_at')->nullable();
            $table->dateTime('clock_out_at')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'staff_record_id', 'work_date'], 'hr_staff_attendance_day_uq');
            $table->index(['tenant_id', 'campus_id', 'work_date'], 'hr_staff_attendance_campus_date_idx');
            $table->foreign(['tenant_id', 'staff_record_id'])->references(['tenant_id', 'id'])->on('hr_staff_records')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'])->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        DB::transaction(function (): void {
            $catalog = PermissionCatalog::tenantPermissions();
            $keys = ['hr.attendance.manage', 'hr.leave.manage'];
            $rows = array_map(static fn (string $key): array => [
                'key' => $key, 'description' => $catalog[$key], 'scope' => 'tenant',
                'created_at' => now(), 'updated_at' => now(),
            ], $keys);
            DB::table('permissions')->upsert($rows, ['key'], ['description', 'scope', 'updated_at']);
            $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id', 'key');
            $grants = [
                'tenant.owner' => $keys,
                'tenant.admin' => $keys,
                'campus.admin' => ['hr.attendance.manage'],
                'hr.manager' => $keys,
            ];
            foreach (DB::table('roles')->where('scope', 'tenant')->get(['id', 'key']) as $role) {
                foreach ($grants[$role->key] ?? [] as $key) {
                    DB::table('permission_role')->insertOrIgnore([
                        'role_id' => $role->id, 'permission_id' => $ids[$key],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_staff_attendance');
        Schema::dropIfExists('hr_leave_requests');
        Schema::dropIfExists('hr_leave_types');
        // Permission catalog entries are intentionally retained on rollback.
    }
};
