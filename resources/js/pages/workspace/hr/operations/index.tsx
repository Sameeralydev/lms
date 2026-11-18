import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type LeaveType = { id: number; code: string; name: string; max_calendar_days: number | null; requires_approval: boolean; status: 'active' | 'inactive' };
type LeaveRequest = {
    id: number; staff: string | null; leave_type: string | null; start_on: string; end_on: string;
    calendar_days: number; reason: string | null; status: string; decision_reason: string | null; decider: string | null; own: boolean; can_cancel: boolean;
};
type Attendance = { id: number; staff: string | null; work_date: string; clock_in_at: string | null; clock_out_at: string | null; clock_in_time: string | null; clock_out_time: string | null };
type Props = {
    tenant: { slug: string };
    staff: { id: number; status: string } | null;
    today: string;
    ownAttendance: { clock_in_at: string | null; clock_out_at: string | null; clock_in_time: string | null; clock_out_time: string | null } | null;
    attendance: Attendance[];
    leaveRequests: LeaveRequest[];
    leaveTypes: LeaveType[];
    canManageLeave: boolean;
    canManageAttendance: boolean;
    canRequestLeave: boolean;
    canClock: boolean;
};

function LeaveDecision({ tenant, item }: { tenant: string; item: LeaveRequest }) {
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const submit = (decision: 'approved' | 'rejected') => {
        setProcessing(true);
        router.patch(`/t/${tenant}/hr/operations/leave-requests/${item.id}/decision`, { decision, decision_reason: reason }, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };
    return <div className="mt-3 flex flex-wrap items-end gap-2">
        <label className="min-w-48 flex-1 text-sm">Decision note
            <input value={reason} onChange={(event) => setReason(event.target.value)} className="border-border mt-1 min-h-10 w-full rounded-lg border px-3" maxLength={500} />
        </label>
        <button type="button" disabled={processing} onClick={() => submit('approved')} className="bg-brand-700 text-white min-h-10 rounded-lg px-3 text-sm font-semibold disabled:opacity-50">Approve</button>
        <button type="button" disabled={processing || !reason.trim()} onClick={() => submit('rejected')} className="border-border min-h-10 rounded-lg border px-3 text-sm font-semibold disabled:opacity-50">Reject</button>
    </div>;
}

export default function HrOperations({ tenant, staff, today, ownAttendance, attendance, leaveRequests, leaveTypes, canManageLeave, canManageAttendance, canRequestLeave, canClock }: Props) {
    const base = `/t/${tenant.slug}/hr/operations`;
    const typeForm = useForm({ code: '', name: '', max_calendar_days: '', requires_approval: true });
    const leaveForm = useForm({ leave_type_id: '', start_on: '', end_on: '', reason: '' });

    return <WorkspaceLayout title="HR operations" area="tenant">
        <Head title="HR attendance and leave" />
        <PageHeader eyebrow="Human resources" title="Attendance & leave" description="Record your daily attendance and submit leave requests under your institution’s configured leave types." />

        <div className="mt-6 grid gap-5 xl:grid-cols-2">
            <section className="border-border bg-surface rounded-2xl border p-5">
                <h2 className="text-lg font-semibold">My attendance</h2>
                {!staff ? <p className="text-text-muted mt-3 text-sm">Your account does not have an active staff record.</p> : <>
                    <p className="text-text-muted mt-2 text-sm">Attendance times use the institution’s configured timezone.</p>
                    <div className="mt-4 flex flex-wrap gap-3">
                        <button disabled={!canClock || Boolean(ownAttendance?.clock_in_at)} onClick={() => router.post(`${base}/attendance/clock-in`, {}, { preserveScroll: true })} className="bg-brand-700 text-white min-h-11 rounded-lg px-4 text-sm font-semibold disabled:opacity-50">Clock in</button>
                        <button disabled={!canClock || !ownAttendance?.clock_in_at || Boolean(ownAttendance?.clock_out_at)} onClick={() => router.post(`${base}/attendance/clock-out`, {}, { preserveScroll: true })} className="border-border min-h-11 rounded-lg border px-4 text-sm font-semibold disabled:opacity-50">Clock out</button>
                    </div>
                    {ownAttendance && <p className="text-text-muted mt-3 text-sm">Today ({today}): {ownAttendance.clock_in_time ?? 'Not clocked in'} · {ownAttendance.clock_out_time ?? 'No clock-out yet'} (institution local time)</p>}
                </>}
            </section>

            <section className="border-border bg-surface rounded-2xl border p-5">
                <h2 className="text-lg font-semibold">Request leave</h2>
                {!canRequestLeave ? <p className="text-text-muted mt-3 text-sm">An active staff record is required to request leave.</p> : <form onSubmit={(event) => { event.preventDefault(); leaveForm.post(`${base}/leave-requests`, { preserveScroll: true, onSuccess: () => leaveForm.reset('leave_type_id', 'start_on', 'end_on', 'reason') }); }} className="mt-4 grid gap-3 sm:grid-cols-2">
                    <label className="text-sm sm:col-span-2">Leave type
                        <select required value={leaveForm.data.leave_type_id} onChange={(event) => leaveForm.setData('leave_type_id', event.target.value)} className="border-border mt-1 min-h-11 w-full rounded-lg border px-3">
                            <option value="">Choose a leave type</option>{leaveTypes.filter((type) => type.status === 'active').map((type) => <option key={type.id} value={type.id}>{type.name}{type.max_calendar_days ? ` · up to ${type.max_calendar_days} calendar days` : ''}</option>)}
                        </select>
                        {leaveForm.errors.leave_type_id && <span role="alert" className="text-danger block text-xs">{leaveForm.errors.leave_type_id}</span>}
                    </label>
                    <label className="text-sm">Start date<input required type="date" value={leaveForm.data.start_on} onChange={(event) => leaveForm.setData('start_on', event.target.value)} className="border-border mt-1 min-h-11 w-full rounded-lg border px-3" />{leaveForm.errors.start_on && <span role="alert" className="text-danger block text-xs">{leaveForm.errors.start_on}</span>}</label>
                    <label className="text-sm">End date<input required type="date" value={leaveForm.data.end_on} onChange={(event) => leaveForm.setData('end_on', event.target.value)} className="border-border mt-1 min-h-11 w-full rounded-lg border px-3" />{leaveForm.errors.end_on && <span role="alert" className="text-danger block text-xs">{leaveForm.errors.end_on}</span>}</label>
                    <label className="text-sm sm:col-span-2">Reason<textarea value={leaveForm.data.reason} onChange={(event) => leaveForm.setData('reason', event.target.value)} rows={2} maxLength={2000} className="border-border mt-1 w-full rounded-lg border px-3 py-2" /></label>
                    <p className="text-text-muted text-xs sm:col-span-2">The system counts calendar days. It does not calculate a leave balance or infer workdays.</p>
                    <button disabled={leaveForm.processing || leaveTypes.length === 0} className="bg-brand-700 text-white min-h-11 rounded-lg px-4 text-sm font-semibold disabled:opacity-50 sm:col-span-2">{leaveForm.processing ? 'Submitting…' : 'Submit request'}</button>
                </form>}
            </section>
        </div>

        {canManageLeave && <section className="border-border bg-surface mt-5 rounded-2xl border p-5">
            <h2 className="text-lg font-semibold">Configure leave type</h2>
            <p className="text-text-muted mt-1 text-sm">Set institution-specific request limits and whether review is required. No entitlement or payroll calculation is applied.</p>
            <form onSubmit={(event) => { event.preventDefault(); typeForm.post(`${base}/leave-types`, { preserveScroll: true, onSuccess: () => typeForm.reset() }); }} className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label className="text-sm">Code<input required value={typeForm.data.code} onChange={(event) => typeForm.setData('code', event.target.value)} maxLength={40} className="border-border mt-1 min-h-11 w-full rounded-lg border px-3" /></label>
                <label className="text-sm">Name<input required value={typeForm.data.name} onChange={(event) => typeForm.setData('name', event.target.value)} maxLength={100} className="border-border mt-1 min-h-11 w-full rounded-lg border px-3" /></label>
                <label className="text-sm">Maximum calendar days per request<input type="number" min={1} max={366} value={typeForm.data.max_calendar_days} onChange={(event) => typeForm.setData('max_calendar_days', event.target.value)} className="border-border mt-1 min-h-11 w-full rounded-lg border px-3" /></label>
                <label className="flex min-h-11 items-center gap-2 self-end text-sm"><input type="checkbox" checked={typeForm.data.requires_approval} onChange={(event) => typeForm.setData('requires_approval', event.target.checked)} />Requires review</label>
                <button disabled={typeForm.processing} className="bg-brand-700 text-white min-h-11 rounded-lg px-4 text-sm font-semibold disabled:opacity-50 sm:col-span-2 lg:col-span-4">Save leave type</button>
                {Object.values(typeForm.errors).map((error, index) => <p key={index} role="alert" className="text-danger text-sm sm:col-span-2 lg:col-span-4">{error}</p>)}
            </form>
            {leaveTypes.length > 0 && <ul className="mt-4 grid gap-2 sm:grid-cols-2">{leaveTypes.map((type) => <li key={type.id} className="border-border flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm"><span>{type.code}: {type.name}{type.requires_approval ? ' · review required' : ' · auto-approved by policy'} · {type.status}</span><button type="button" onClick={() => router.patch(`${base}/leave-types/${type.id}/status`, { status: type.status === 'active' ? 'inactive' : 'active' }, { preserveScroll: true })} className="text-brand-700 min-h-9 px-2 text-xs font-semibold underline">{type.status === 'active' ? 'Deactivate' : 'Reactivate'}</button></li>)}</ul>}
        </section>}

        <section className="border-border bg-surface mt-5 rounded-2xl border p-5">
            <h2 className="text-lg font-semibold">Leave requests</h2>
            {leaveRequests.length === 0 ? <p className="text-text-muted mt-3 text-sm">No leave requests are recorded.</p> : <ul className="mt-4 grid gap-3">{leaveRequests.map((item) => <li key={item.id} className="border-border rounded-xl border p-4">
                <div className="flex flex-wrap items-start justify-between gap-3"><div><h3 className="font-semibold">{item.leave_type} · {item.staff}</h3><p className="text-text-muted mt-1 text-sm">{item.start_on} to {item.end_on} · {item.calendar_days} calendar day{item.calendar_days === 1 ? '' : 's'}</p>{item.reason && <p className="mt-2 text-sm">{item.reason}</p>}{item.decision_reason && <p className="text-text-muted mt-2 text-sm">Decision note: {item.decision_reason}</p>}</div><span className="border-border rounded-full border px-3 py-1 text-xs font-semibold capitalize">{item.status}</span></div>
                {canManageLeave && item.status === 'pending' && <LeaveDecision tenant={tenant.slug} item={item} />}
                {item.can_cancel && <button type="button" onClick={() => router.patch(`${base}/leave-requests/${item.id}/cancel`, {}, { preserveScroll: true })} className="text-brand-700 mt-3 min-h-10 text-sm font-semibold underline">Cancel request</button>}
            </li>)}</ul>}
        </section>

            {canManageAttendance && <section className="border-border bg-surface mt-5 rounded-2xl border p-5">
            <h2 className="text-lg font-semibold">Staff attendance</h2>
            {attendance.length === 0 ? <p className="text-text-muted mt-3 text-sm">No attendance entries are recorded.</p> : <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[36rem] text-left text-sm"><thead><tr className="border-border border-b"><th className="py-2 pr-3">Staff member</th><th className="py-2 pr-3">Work date</th><th className="py-2 pr-3">Clock in</th><th className="py-2">Clock out</th></tr></thead><tbody>{attendance.map((entry) => <tr key={entry.id} className="border-border border-b last:border-0"><td className="py-3 pr-3">{entry.staff}</td><td className="py-3 pr-3">{entry.work_date}</td><td className="py-3 pr-3">{entry.clock_in_time ?? '—'}</td><td className="py-3">{entry.clock_out_time ?? '—'}</td></tr>)}</tbody></table></div>}
        </section>}
    </WorkspaceLayout>;
}
