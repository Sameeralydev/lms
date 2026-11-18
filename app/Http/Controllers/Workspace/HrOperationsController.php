<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\HrLeaveRequest;
use App\Models\HrLeaveType;
use App\Models\HrStaffAttendance;
use App\Models\HrStaffRecord;
use App\Models\Institution;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class HrOperationsController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Tenant $tenant): Response
    {
        $membership = $this->context->membership();
        $staff = HrStaffRecord::query()->where('membership_id', $membership->getKey())->first();
        $timezone = (string) (Institution::query()->whereKey($membership->institution_id)->value('timezone') ?: 'UTC');
        $canManageAttendance = $membership->hasPermission('hr.attendance.manage');
        $canManageLeave = $membership->hasPermission('hr.leave.manage');
        abort_unless($staff || $canManageAttendance || $canManageLeave, 403);
        $attendance = HrStaffAttendance::query()->with('staffRecord.membership.user:id,name')
            ->when(! $canManageAttendance, fn (Builder $q) => $q->where('staff_record_id', $staff?->getKey()))
            ->when($canManageAttendance && $membership->campus_id !== null, fn (Builder $q) => $q->where('campus_id', $membership->campus_id))
            ->orderByDesc('work_date')->orderByDesc('clock_in_at')->limit(100)->get()->map(static fn (HrStaffAttendance $entry): array => [
                'id' => (int) $entry->getKey(), 'staff' => $entry->staffRecord?->membership?->user?->name,
                'work_date' => $entry->work_date?->toDateString(), 'clock_in_at' => $entry->clock_in_at?->toIso8601String(),
                'clock_out_at' => $entry->clock_out_at?->toIso8601String(),
                'clock_in_time' => $entry->clock_in_at?->setTimezone($timezone)->format('H:i'), 'clock_out_time' => $entry->clock_out_at?->setTimezone($timezone)->format('H:i'),
            ])->all();
        $today = CarbonImmutable::now($timezone)->toDateString();
        $ownAttendance = $staff ? HrStaffAttendance::query()->where('staff_record_id', $staff->getKey())->whereDate('work_date', $today)->first() : null;

        $leaveQuery = HrLeaveRequest::query()->with(['staffRecord.membership.user:id,name', 'leaveType:id,name', 'decider:id,name'])
            ->when(! $canManageLeave, fn (Builder $q) => $q->where('staff_record_id', $staff?->getKey()))
            ->when($canManageLeave && $membership->campus_id !== null, fn (Builder $q) => $q->where('campus_id', $membership->campus_id));
        $leaveRequests = $leaveQuery->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')->limit(100)->get()->map(static fn (HrLeaveRequest $leave): array => [
                'id' => (int) $leave->getKey(), 'staff' => $leave->staffRecord?->membership?->user?->name,
                'leave_type' => $leave->leaveType?->name, 'start_on' => $leave->start_on?->toDateString(),
                'end_on' => $leave->end_on?->toDateString(), 'calendar_days' => (int) $leave->calendar_days,
                'reason' => $leave->reason, 'status' => $leave->status,
                'decision_reason' => $leave->decision_reason, 'decider' => $leave->decider?->name,
                'own' => (int) $leave->requested_by === (int) $membership->user_id,
                'can_cancel' => (int) $leave->requested_by === (int) $membership->user_id
                    && ($leave->status === 'pending' || ($leave->status === 'approved' && $leave->start_on->isAfter(today()))),
            ])->all();

        $leaveTypes = HrLeaveType::query()->when(! $canManageLeave, fn (Builder $q) => $q->where('status', 'active'))
            ->orderBy('name')->get(['id', 'code', 'name', 'max_calendar_days', 'requires_approval', 'status'])->map(static fn (HrLeaveType $type): array => [
                'id' => (int) $type->getKey(), 'code' => $type->code, 'name' => $type->name,
                'max_calendar_days' => $type->max_calendar_days, 'requires_approval' => $type->requires_approval, 'status' => $type->status,
            ])->all();

        return Inertia::render('workspace/hr/operations/index', [
            'tenant' => ['slug' => $tenant->slug],
            'staff' => $staff ? ['id' => (int) $staff->getKey(), 'status' => $staff->status] : null,
            'today' => $today,
            'ownAttendance' => $ownAttendance ? [
                'clock_in_at' => $ownAttendance->clock_in_at?->toIso8601String(),
                'clock_out_at' => $ownAttendance->clock_out_at?->toIso8601String(),
                'clock_in_time' => $ownAttendance->clock_in_at?->setTimezone($timezone)->format('H:i'),
                'clock_out_time' => $ownAttendance->clock_out_at?->setTimezone($timezone)->format('H:i'),
            ] : null,
            'attendance' => $attendance,
            'leaveRequests' => $leaveRequests,
            'leaveTypes' => $leaveTypes,
            'canManageLeave' => $canManageLeave,
            'canManageAttendance' => $canManageAttendance,
            'canRequestLeave' => $staff?->status === 'active',
            'canClock' => $staff?->status === 'active',
        ]);
    }

    public function storeLeaveType(Request $request, Tenant $tenant, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->context->membership();
        abort_unless($actor->hasPermission('hr.leave.manage'), 403);
        $data = $request->validate([
            'code' => ['required', 'alpha_dash:ascii', 'max:40', Rule::unique('hr_leave_types', 'code')->where('tenant_id', $tenant->getKey())],
            'name' => ['required', 'string', 'max:100'],
            'max_calendar_days' => ['nullable', 'integer', 'between:1,366'],
            'requires_approval' => ['required', 'boolean'],
        ]);
        $type = HrLeaveType::query()->create($data);
        $audit->record('hr.leave_type.created', $request->user(), (int) $tenant->getKey(), $type, ['code' => $type->code]);

        return back()->with('status', 'Leave type created.');
    }

    public function setLeaveTypeStatus(Request $request, Tenant $tenant, int $typeId, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->context->membership();
        abort_unless($actor->hasPermission('hr.leave.manage'), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        $type = HrLeaveType::query()->whereKey($typeId)->firstOrFail();
        $type->forceFill(['status' => $data['status']])->save();
        $audit->record('hr.leave_type.status_changed', $request->user(), (int) $tenant->getKey(), $type, ['status' => $type->status]);

        return back()->with('status', 'Leave type status updated.');
    }

    public function requestLeave(Request $request, Tenant $tenant, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->context->membership();
        $staff = HrStaffRecord::query()->where('membership_id', $actor->getKey())->where('status', 'active')->firstOrFail();
        $timezone = (string) (Institution::query()->whereKey($actor->institution_id)->value('timezone') ?: 'UTC');
        $localToday = CarbonImmutable::now($timezone)->toDateString();
        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', Rule::exists('hr_leave_types', 'id')->where('tenant_id', $tenant->getKey())->where('status', 'active')],
            'start_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$localToday],
            'end_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_on'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $data['start_on'], $timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $data['end_on'], $timezone);
        $days = (int) $start->diffInDays($end) + 1;
        $leave = DB::transaction(function () use ($audit, $data, $days, $end, $request, $staff, $start, $tenant): HrLeaveRequest {
            $lockedStaff = HrStaffRecord::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();
            $type = HrLeaveType::query()->whereKey($data['leave_type_id'])->where('status', 'active')->firstOrFail();
            if ($type->max_calendar_days !== null && $days > $type->max_calendar_days) {
                throw ValidationException::withMessages(['end_on' => 'This request exceeds the configured maximum calendar days for the selected leave type.']);
            }
            $overlap = HrLeaveRequest::query()->where('staff_record_id', $lockedStaff->getKey())
                ->whereIn('status', ['pending', 'approved'])->whereDate('start_on', '<=', $end->toDateString())
                ->whereDate('end_on', '>=', $start->toDateString())->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['start_on' => 'This date range overlaps a pending or approved leave request.']);
            }
            $status = $type->requires_approval ? 'pending' : 'approved';
            $leave = HrLeaveRequest::query()->create([
                'staff_record_id' => $lockedStaff->getKey(), 'campus_id' => $lockedStaff->campus_id,
                'leave_type_id' => $type->getKey(),
                'start_on' => $start->toDateString(), 'end_on' => $end->toDateString(), 'calendar_days' => $days,
                'reason' => $data['reason'] ?? null, 'status' => $status, 'requested_by' => $request->user()->getKey(),
                'decided_at' => $status === 'approved' ? now() : null,
            ]);
            $audit->record('hr.leave.requested', $request->user(), (int) $tenant->getKey(), $leave, [
                'staff_record_id' => $lockedStaff->getKey(), 'leave_type_id' => $type->getKey(), 'calendar_days' => $days, 'status' => $status,
            ]);

            return $leave;
        });

        return back()->with('status', $leave->status === 'approved' ? 'Leave recorded under the configured no-approval policy.' : 'Leave request submitted for review.');
    }

    public function decideLeave(Request $request, Tenant $tenant, int $requestId, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->context->membership();
        abort_unless($actor->hasPermission('hr.leave.manage'), 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'decision_reason' => ['nullable', 'string', 'max:500', Rule::requiredIf(fn (): bool => $request->input('decision') === 'rejected')],
        ]);
        $leave = DB::transaction(function () use ($actor, $audit, $data, $request, $requestId, $tenant): HrLeaveRequest {
            $leave = HrLeaveRequest::query()->with('staffRecord')->whereKey($requestId)->lockForUpdate()->firstOrFail();
            if ($actor->campus_id !== null && (int) $leave->campus_id !== (int) $actor->campus_id) {
                abort(404);
            }
            if ($leave->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'Only pending requests can be decided.']);
            }
            if ((int) $leave->requested_by === (int) $request->user()->getKey()) {
                throw ValidationException::withMessages(['decision' => 'You cannot approve or reject your own leave request.']);
            }
            $leave->forceFill([
                'status' => $data['decision'], 'decided_by' => $request->user()->getKey(), 'decided_at' => now(),
                'decision_reason' => $data['decision_reason'] ?? null,
            ])->save();
            $audit->record('hr.leave.'.$data['decision'], $request->user(), (int) $tenant->getKey(), $leave, [
                'staff_record_id' => $leave->staff_record_id, 'calendar_days' => $leave->calendar_days,
            ]);

            return $leave;
        });

        return back()->with('status', 'Leave request '.$leave->status.'.');
    }

    public function cancelLeave(Request $request, Tenant $tenant, int $requestId, AuditRecorder $audit): RedirectResponse
    {
        $membership = $this->context->membership();
        $timezone = (string) (Institution::query()->whereKey($membership->institution_id)->value('timezone') ?: 'UTC');
        $localToday = CarbonImmutable::now($timezone)->toDateString();
        $leave = DB::transaction(function () use ($audit, $localToday, $request, $requestId, $tenant): HrLeaveRequest {
            $leave = HrLeaveRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $leave->requested_by === (int) $request->user()->getKey(), 404);
            $isPending = $leave->status === 'pending';
            $isFutureApproved = $leave->status === 'approved' && $leave->start_on->toDateString() > $localToday;
            if (! $isPending && ! $isFutureApproved) {
                throw ValidationException::withMessages(['request' => 'Only pending requests or approved leave that has not started can be cancelled.']);
            }
            $previousStatus = $leave->status;
            $leave->forceFill(['status' => 'cancelled'])->save();
            $audit->record('hr.leave.cancelled', $request->user(), (int) $tenant->getKey(), $leave, [
                'staff_record_id' => $leave->staff_record_id, 'calendar_days' => $leave->calendar_days,
                'from_status' => $previousStatus, 'to_status' => 'cancelled',
            ]);

            return $leave;
        });

        return back()->with('status', 'Leave request cancelled.');
    }

    public function clockIn(Request $request, Tenant $tenant, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->context->membership();
        $staff = HrStaffRecord::query()->where('membership_id', $actor->getKey())->where('status', 'active')->firstOrFail();
        $timezone = (string) (Institution::query()->whereKey($actor->institution_id)->value('timezone') ?: 'UTC');
        $today = CarbonImmutable::now($timezone)->toDateString();
        $instant = CarbonImmutable::now('UTC');
        $attendance = DB::transaction(function () use ($audit, $instant, $request, $staff, $tenant, $today): HrStaffAttendance {
            HrStaffRecord::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();
            $entry = HrStaffAttendance::query()->where('staff_record_id', $staff->getKey())->whereDate('work_date', $today)->lockForUpdate()->first();
            if ($entry && $entry->clock_in_at !== null) {
                throw ValidationException::withMessages(['attendance' => 'Clock-in has already been recorded for today.']);
            }
            $entry ??= HrStaffAttendance::query()->make(['staff_record_id' => $staff->getKey(), 'campus_id' => $staff->campus_id, 'work_date' => $today]);
            $entry->forceFill(['clock_in_at' => $instant, 'recorded_by' => $request->user()->getKey()])->save();
            $audit->record('hr.attendance.clocked_in', $request->user(), (int) $tenant->getKey(), $entry, ['staff_record_id' => $staff->getKey(), 'work_date' => $today]);

            return $entry;
        });

        return back()->with('status', 'Clock-in recorded at '.$attendance->clock_in_at?->setTimezone($timezone)->format('H:i').'.');
    }

    public function clockOut(Request $request, Tenant $tenant, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->context->membership();
        $staff = HrStaffRecord::query()->where('membership_id', $actor->getKey())->where('status', 'active')->firstOrFail();
        $timezone = (string) (Institution::query()->whereKey($actor->institution_id)->value('timezone') ?: 'UTC');
        $today = CarbonImmutable::now($timezone)->toDateString();
        $instant = CarbonImmutable::now('UTC');
        $attendance = DB::transaction(function () use ($audit, $instant, $request, $staff, $tenant, $today): HrStaffAttendance {
            $entry = HrStaffAttendance::query()->where('staff_record_id', $staff->getKey())->whereDate('work_date', $today)->lockForUpdate()->firstOrFail();
            if ($entry->clock_in_at === null || $entry->clock_out_at !== null) {
                throw ValidationException::withMessages(['attendance' => 'There is no open attendance session to clock out.']);
            }
            if ($instant->lessThan($entry->clock_in_at)) {
                throw ValidationException::withMessages(['attendance' => 'Clock-out cannot be earlier than clock-in.']);
            }
            $entry->forceFill(['clock_out_at' => $instant])->save();
            $audit->record('hr.attendance.clocked_out', $request->user(), (int) $tenant->getKey(), $entry, ['staff_record_id' => $staff->getKey(), 'work_date' => $today]);

            return $entry;
        });

        return back()->with('status', 'Clock-out recorded at '.$attendance->clock_out_at?->setTimezone($timezone)->format('H:i').'.');
    }
}
