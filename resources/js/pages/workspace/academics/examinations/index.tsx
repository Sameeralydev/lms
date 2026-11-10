import { router, useForm } from '@inertiajs/react';
import type { FormDataConvertible } from '@inertiajs/core';
import type { FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Cycle = {
    id: number;
    code: string;
    title: string;
    category: string;
    source_cycle_id: number | null;
    status: string;
    registration_opens_on: string | null;
    registration_closes_on: string | null;
    sessions_count: number;
    candidates_count: number;
    period: { id: number; name: string; code: string };
    sessions: {
        id: number;
        code: string;
        title: string;
        mode: string;
        status: string;
        starts_at_utc: string;
        ends_at_utc: string;
        duration_minutes: number;
        campus_id: number;
        course: { id: number; title: string; code: string };
    }[];
    candidates?: {
        id: number;
        candidate_number: string;
        status: string;
        student: { student_number: string; legal_first_name: string; legal_last_name: string };
    }[];
};

type Props = {
    cycles: { data: Cycle[]; links: { url: string | null; label: string; active: boolean }[] };
    periods: { id: number; name: string; code: string }[];
    sourceCycles: { id: number; code: string; title: string; category: string; ends_on: string | null }[];
    campuses: { id: number; name: string }[];
    courses: { id: number; title: string; code: string }[];
    rooms: { id: number; campus_id: number; name: string; code: string; capacity: number }[];
    students: { id: number; student_number: string; legal_first_name: string; legal_last_name: string }[];
    filters: { status?: string };
    categories: string[];
    canManage: boolean;
    canApprove: boolean;
    canPublishExams: boolean;
    canRegister: boolean;
    dateSheets: Record<
        number,
        {
            id: number;
            status: string;
            currentRevision: number;
            revisions: {
                id: number;
                revision: number;
                status: string;
                createdAt: string | null;
                reviewNotes: string | null;
                sessions: {
                    session_id: number;
                    title: string;
                    code: string;
                    starts_at_utc: string | null;
                    course: { title: string | null; code: string | null };
                }[];
            }[];
        } | null
    >;
    seatingPlans: Record<
        number,
        {
            planId: number | null;
            status: string | null;
            currentRevision: number;
            publishedRevision: number;
            revisions: {
                id: number;
                revision: number;
                status: string;
                createdAt: string | null;
                reviewNotes: string | null;
            }[];
            candidates: {
                id: number;
                candidateNumber: string;
                studentName: string;
                seat: { room_id: number; seat_number: string } | null;
            }[];
        } | null
    >;
    paperVersions: {
        session_id: number;
        id: number;
        version_number: number;
        set_code: string;
        title: string;
        total_marks: string;
        duration_minutes: number;
        status: string;
    }[];
    deliveryPaperVersions: {
        session_id: number;
        id: number;
        version_number: number;
        set_code: string;
        title: string;
        total_marks: string;
        duration_minutes: number;
        status: string;
    }[];
    deliveryQuizzes: { id: number; title: string; academic_course_id: number }[];
    sessionDeliveries: {
        session_id: number;
        quiz_id: number;
        quiz_version_id: number;
        paper_version_id: number;
        title: string;
    }[];
    canConfigureDelivery: boolean;
    questionOptions: {
        id: number;
        version_number: number;
        question_type: string;
        topic: string | null;
        difficulty: string;
        content: { prompt?: string };
        default_points: string;
        question: { bank: { name: string } };
    }[];
    canManagePapers: boolean;
    canReviewPapers: boolean;
    canPublishPapers: boolean;
    canPreviewPapers: boolean;
    staffOptions: { id: number; name: string }[];
    invigilatorAssignments: { session_id: number; user_id: number; duty_role: string; name: string }[];
    myInvigilatorAssignments: {
        id: number;
        status: string;
        accepted_at: string | null;
        duty_role: string;
        session_title: string;
        starts_at_utc: string;
        cycle_title: string;
    }[];
    attendanceRecords: {
        session_id: number;
        candidate_id: number;
        attendance_status: string;
        revision: number;
        student_number: string;
    }[];
    canManageInvigilators: boolean;
    canRecordAttendance: boolean;
    canManageAccommodations: boolean;
};

const labels: Record<string, string> = {
    unit_test: 'Unit test',
    class_test: 'Class test',
    monthly: 'Monthly assessment',
    midterm: 'Midterm',
    final: 'Final',
    term_end: 'Term end',
    annual: 'Annual',
    practical: 'Practical',
    laboratory: 'Laboratory',
    oral: 'Oral',
    viva: 'Viva',
    project: 'Project',
    mock: 'Mock',
    supplementary: 'Supplementary',
    makeup: 'Makeup',
    retake: 'Retake',
    institution_defined: 'Institution defined',
};

export default function ExaminationsIndex(props: Props) {
    const root = `${window.location.pathname.replace(/\/$/, '')}`;
    const cycleForm = useForm({
        academic_period_id: '',
        source_cycle_id: '',
        campus_id: '',
        code: '',
        title: '',
        category: 'final',
        registration_opens_on: '',
        registration_closes_on: '',
        starts_on: '',
        ends_on: '',
    });
    const createCycle = (event: FormEvent) => {
        event.preventDefault();
        cycleForm.post(`${root}/cycles`, {
            onSuccess: () => cycleForm.reset('code', 'title', 'source_cycle_id', 'starts_on', 'ends_on'),
        });
    };

    return (
        <WorkspaceLayout area="tenant" title="Examinations">
            <PageHeader
                title="Examinations"
                description="Plan formal examination cycles and date sheets for your institution."
            />
            <InvigilatorDuties root={root} assignments={props.myInvigilatorAssignments} />
            {props.canManage ? (
                <section
                    className="bg-surface border-border mb-6 rounded-xl border p-5"
                    aria-labelledby="new-cycle-title"
                >
                    <h2 id="new-cycle-title" className="text-lg font-semibold">
                        Create examination cycle
                    </h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Formal exams use the academic period and institution records already configured in
                        this workspace.
                    </p>
                    <FormErrors errors={cycleForm.errors} />
                    <form onSubmit={createCycle} className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <label className="grid gap-1 text-sm">
                            Title
                            <input
                                required
                                maxLength={180}
                                className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                value={cycleForm.data.title}
                                onChange={(e) => cycleForm.setData('title', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Code
                            <input
                                required
                                maxLength={48}
                                className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                value={cycleForm.data.code}
                                onChange={(e) => cycleForm.setData('code', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Category
                            <select
                                className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                value={cycleForm.data.category}
                                onChange={(e) => {
                                    const category = e.currentTarget.value;
                                    cycleForm.setData('category', category);
                                    if (!['retake', 'makeup'].includes(category))
                                        cycleForm.setData('source_cycle_id', '');
                                }}
                            >
                                {props.categories.map((category) => (
                                    <option key={category} value={category}>
                                        {labels[category] ?? category}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {['retake', 'makeup'].includes(cycleForm.data.category) ? (
                            <label className="grid gap-1 text-sm">
                                Source examination
                                <select
                                    required
                                    className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                    value={cycleForm.data.source_cycle_id}
                                    onChange={(e) =>
                                        cycleForm.setData('source_cycle_id', e.currentTarget.value)
                                    }
                                >
                                    <option value="">Select a completed examination</option>
                                    {props.sourceCycles.map((source) => (
                                        <option key={source.id} value={source.id}>
                                            {source.title} ({labels[source.category] ?? source.category})
                                        </option>
                                    ))}
                                </select>
                                <span className="text-text-secondary text-xs">
                                    {cycleForm.data.category === 'retake'
                                        ? 'Only learners with a released unsuccessful result in this cycle may be registered.'
                                        : 'Only learners recorded absent with a released result in this cycle may be registered.'}
                                </span>
                            </label>
                        ) : null}
                        <label className="grid gap-1 text-sm">
                            Academic period
                            <select
                                required
                                className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                value={cycleForm.data.academic_period_id}
                                onChange={(e) =>
                                    cycleForm.setData('academic_period_id', e.currentTarget.value)
                                }
                            >
                                <option value="">Select a period</option>
                                {props.periods.map((period) => (
                                    <option key={period.id} value={period.id}>
                                        {period.name} ({period.code})
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Campus scope
                            <select
                                className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                value={cycleForm.data.campus_id}
                                onChange={(e) => cycleForm.setData('campus_id', e.currentTarget.value)}
                            >
                                <option value="">All authorized campuses</option>
                                {props.campuses.map((campus) => (
                                    <option key={campus.id} value={campus.id}>
                                        {campus.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Registration opens
                            <input
                                type="date"
                                className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                value={cycleForm.data.registration_opens_on}
                                onChange={(e) =>
                                    cycleForm.setData('registration_opens_on', e.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Registration closes
                            <input
                                type="date"
                                className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                value={cycleForm.data.registration_closes_on}
                                onChange={(e) =>
                                    cycleForm.setData('registration_closes_on', e.currentTarget.value)
                                }
                            />
                        </label>
                        <div className="flex items-end">
                            <button
                                disabled={cycleForm.processing || props.periods.length === 0}
                                className="bg-brand-primary focus-ring min-h-11 w-full rounded-lg px-4 font-medium text-white disabled:opacity-60"
                            >
                                Create draft cycle
                            </button>
                        </div>
                    </form>
                </section>
            ) : null}

            <section aria-label="Examination cycles" className="grid gap-4">
                {props.cycles.data.length === 0 ? (
                    <div className="bg-surface border-border rounded-xl border p-8 text-center">
                        <h2 className="font-semibold">No examination cycles yet</h2>
                        <p className="text-text-secondary mt-2 text-sm">
                            Cycles created for authorized academic periods will appear here.
                        </p>
                    </div>
                ) : (
                    props.cycles.data.map((cycle) => (
                        <article
                            key={cycle.id}
                            className="bg-surface border-border min-w-0 rounded-xl border p-5"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p className="text-text-secondary text-xs font-semibold tracking-wide uppercase">
                                        {cycle.code} · {cycle.period.name}
                                    </p>
                                    <h2 className="mt-1 text-xl font-semibold">{cycle.title}</h2>
                                    <p className="text-text-secondary mt-1 text-sm">
                                        {labels[cycle.category] ?? cycle.category} · {cycle.status} ·{' '}
                                        {cycle.sessions_count} scheduled sessions · {cycle.candidates_count}{' '}
                                        candidates
                                    </p>
                                </div>
                                {props.canManage && cycle.status !== 'archived' ? (
                                    <div
                                        className="flex flex-wrap gap-2"
                                        aria-label={`${cycle.title} actions`}
                                    >
                                        {cycle.status === 'draft' ? (
                                            <button
                                                className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                                                onClick={() =>
                                                    router.patch(`${root}/cycles/${cycle.id}/status`, {
                                                        to: 'review',
                                                    })
                                                }
                                            >
                                                Submit for review
                                            </button>
                                        ) : null}
                                        {cycle.status === 'review' && props.canApprove ? (
                                            <button
                                                className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                                                onClick={() =>
                                                    router.patch(`${root}/cycles/${cycle.id}/status`, {
                                                        to: 'approved',
                                                    })
                                                }
                                            >
                                                Approve
                                            </button>
                                        ) : null}
                                        {cycle.status === 'approved' && props.canApprove ? (
                                            <button
                                                className="bg-brand-primary focus-ring min-h-10 rounded-lg px-3 text-sm text-white"
                                                onClick={() =>
                                                    router.patch(`${root}/cycles/${cycle.id}/status`, {
                                                        to: 'published',
                                                    })
                                                }
                                            >
                                                Publish
                                            </button>
                                        ) : null}
                                        {cycle.status === 'published' ? (
                                            <button
                                                className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                                                onClick={() =>
                                                    router.patch(`${root}/cycles/${cycle.id}/status`, {
                                                        to: 'active',
                                                    })
                                                }
                                            >
                                                Start cycle
                                            </button>
                                        ) : null}
                                        {cycle.status === 'active' ? (
                                            <button
                                                className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                                                onClick={() =>
                                                    router.patch(`${root}/cycles/${cycle.id}/status`, {
                                                        to: 'completed',
                                                    })
                                                }
                                            >
                                                Complete cycle
                                            </button>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>
                            {cycle.sessions.length ? (
                                <DateSheetPanel
                                    root={root}
                                    cycle={cycle}
                                    dateSheet={props.dateSheets[cycle.id] ?? null}
                                    canManage={props.canManage}
                                    canApprove={props.canApprove}
                                    canPublish={props.canPublishExams}
                                />
                            ) : null}
                            {cycle.sessions.length ? (
                                <div
                                    role="region"
                                    aria-label={`Scrollable schedule for ${cycle.title}`}
                                    tabIndex={0}
                                    className="focus-ring mt-5 overflow-x-auto rounded-lg"
                                >
                                    <table className="w-full min-w-[42rem] text-left text-sm">
                                        <caption className="sr-only">
                                            Scheduled examinations for {cycle.title}
                                        </caption>
                                        <thead>
                                            <tr className="border-border text-text-secondary border-b">
                                                <th className="py-2 pr-3">Session</th>
                                                <th className="py-2 pr-3">Course</th>
                                                <th className="py-2 pr-3">Starts (UTC)</th>
                                                <th className="py-2 pr-3">Duration</th>
                                                <th className="py-2">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {cycle.sessions.map((session) => (
                                                <tr
                                                    key={session.id}
                                                    className="border-border border-b last:border-0"
                                                >
                                                    <td className="py-3 pr-3 font-medium">
                                                        {session.title}
                                                        <span className="text-text-secondary block text-xs">
                                                            {session.code} · {session.mode}
                                                        </span>
                                                    </td>
                                                    <td className="py-3 pr-3">{session.course.title}</td>
                                                    <td className="py-3 pr-3">
                                                        {new Date(session.starts_at_utc).toLocaleString()}
                                                    </td>
                                                    <td className="py-3 pr-3">
                                                        {session.duration_minutes} min
                                                    </td>
                                                    <td className="py-3">{session.status}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <p className="text-text-secondary mt-4 text-sm">
                                    No sessions have been added to this cycle.
                                </p>
                            )}
                            {cycle.sessions.map((session) => (
                                <div key={`session-tools-${session.id}`}>
                                    <PaperTools
                                        root={root}
                                        session={session}
                                        versions={props.paperVersions.filter(
                                            (version) => version.session_id === session.id,
                                        )}
                                        questions={props.questionOptions}
                                        canManage={props.canManagePapers}
                                        canReview={props.canReviewPapers}
                                        canPublish={props.canPublishPapers}
                                        canPreview={props.canPreviewPapers}
                                    />
                                    {session.mode === 'online' ? (
                                        <DeliveryTools
                                            root={root}
                                            session={session}
                                            quizzes={props.deliveryQuizzes.filter(
                                                (quiz) => quiz.academic_course_id === session.course.id,
                                            )}
                                            papers={props.deliveryPaperVersions.filter(
                                                (paper) => paper.session_id === session.id,
                                            )}
                                            delivery={props.sessionDeliveries.find(
                                                (item) => item.session_id === session.id,
                                            )}
                                            canConfigure={props.canConfigureDelivery}
                                        />
                                    ) : null}
                                    <SessionOperations
                                        root={root}
                                        cycle={cycle}
                                        session={session}
                                        candidates={cycle.candidates ?? []}
                                        staff={props.staffOptions}
                                        assignments={props.invigilatorAssignments.filter(
                                            (item) => item.session_id === session.id,
                                        )}
                                        attendance={props.attendanceRecords.filter(
                                            (item) => item.session_id === session.id,
                                        )}
                                        canAssign={props.canManageInvigilators}
                                        canRecord={props.canRecordAttendance}
                                        canAccommodate={props.canManageAccommodations}
                                    />
                                    {session.mode !== 'online' ? (
                                        <SeatingPlanner
                                            key={session.id}
                                            root={root}
                                            session={session}
                                            plan={props.seatingPlans[session.id] ?? null}
                                            rooms={props.rooms.filter(
                                                (room) => room.campus_id === session.campus_id,
                                            )}
                                            canManage={props.canManage}
                                            canApprove={props.canApprove}
                                            canPublish={props.canPublishExams}
                                        />
                                    ) : null}
                                </div>
                            ))}
                            {props.canManage && ['draft', 'review', 'approved'].includes(cycle.status) ? (
                                <SessionForm root={root} cycle={cycle} {...props} />
                            ) : null}
                            {props.canRegister && cycle.status === 'published' ? (
                                <CandidateForm root={root} cycle={cycle} students={props.students} />
                            ) : null}
                            {props.canRegister && cycle.candidates?.length ? (
                                <div className="border-border mt-4 border-t pt-4">
                                    <h3 className="font-medium">Registered candidates</h3>
                                    <ul className="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                        {cycle.candidates.map((candidate) => (
                                            <li
                                                key={candidate.id}
                                                className="border-border rounded-lg border px-3 py-2 text-sm"
                                            >
                                                <span className="font-medium">
                                                    {candidate.student.legal_first_name}{' '}
                                                    {candidate.student.legal_last_name}
                                                </span>
                                                <span className="text-text-secondary block">
                                                    {candidate.candidate_number} · {candidate.status}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ) : null}
                        </article>
                    ))
                )}
            </section>
        </WorkspaceLayout>
    );
}

function InvigilatorDuties({
    root,
    assignments,
}: {
    root: string;
    assignments: Props['myInvigilatorAssignments'];
}) {
    const respond = (id: number, decision: 'accept' | 'decline') => {
        router.post(`${root}/invigilator-assignments/${id}/response`, { decision }, { preserveScroll: true });
    };

    if (assignments.length === 0) return null;

    return (
        <section
            className="bg-surface border-border mb-6 rounded-xl border p-5"
            aria-labelledby="my-exam-duties-title"
        >
            <h2 id="my-exam-duties-title" className="text-lg font-semibold">
                My examination duties
            </h2>
            <ul className="divide-border mt-3 divide-y">
                {assignments.map((assignment) => (
                    <li
                        key={assignment.id}
                        className="flex flex-col justify-between gap-3 py-3 sm:flex-row sm:items-center"
                    >
                        <div>
                            <p className="font-medium">{assignment.session_title}</p>
                            <p className="text-text-secondary text-sm">
                                {assignment.cycle_title} · {assignment.duty_role.replaceAll('_', ' ')} ·{' '}
                                {new Date(assignment.starts_at_utc).toLocaleString()}
                            </p>
                            <p className="text-text-secondary text-sm">
                                {assignment.status === 'declined'
                                    ? 'Declined'
                                    : assignment.accepted_at
                                      ? 'Accepted'
                                      : 'Awaiting your response'}
                            </p>
                        </div>
                        {assignment.status === 'assigned' && assignment.accepted_at === null ? (
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    onClick={() => respond(assignment.id, 'accept')}
                                    className="bg-brand-primary focus-ring min-h-10 rounded-lg px-3 text-sm font-medium text-white"
                                >
                                    Accept duty
                                </button>
                                <button
                                    type="button"
                                    onClick={() => respond(assignment.id, 'decline')}
                                    className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm font-medium"
                                >
                                    Decline
                                </button>
                            </div>
                        ) : null}
                    </li>
                ))}
            </ul>
        </section>
    );
}

function DateSheetPanel({
    root,
    cycle,
    dateSheet,
    canManage,
    canApprove,
    canPublish,
}: {
    root: string;
    cycle: Cycle;
    dateSheet: NonNullable<Props['dateSheets'][number]> | null;
    canManage: boolean;
    canApprove: boolean;
    canPublish: boolean;
}) {
    const form = useForm({ session_ids: cycle.sessions.map((session) => session.id) });
    const createRevision = (event: FormEvent) => {
        event.preventDefault();
        form.post(`${root}/cycles/${cycle.id}/date-sheet/revisions`);
    };
    const latest = dateSheet?.revisions[0];

    return (
        <section className="border-border mt-4 border-t pt-4" aria-label={`Date sheet for ${cycle.title}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="font-medium">Published date sheet</h3>
                    <p className="text-text-secondary mt-1 text-sm">
                        {dateSheet
                            ? `Status: ${dateSheet.status} · Current revision: ${dateSheet.currentRevision || 'none'}`
                            : 'No date-sheet revision has been created.'}
                    </p>
                </div>
                {canManage ? (
                    <form onSubmit={createRevision}>
                        <FormErrors errors={form.errors} />
                        <button
                            disabled={form.processing}
                            className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm disabled:opacity-60"
                        >
                            Create schedule revision
                        </button>
                    </form>
                ) : null}
            </div>
            {latest ? (
                <div className="border-border mt-3 rounded-lg border p-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-sm font-medium">
                            Revision {latest.revision} · {latest.status}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {latest.status === 'draft' && canManage ? (
                                <button
                                    className="border-border focus-ring min-h-9 rounded-lg border px-3 text-sm"
                                    onClick={() =>
                                        router.post(`${root}/date-sheet-revisions/${latest.id}/submit`)
                                    }
                                >
                                    Submit for review
                                </button>
                            ) : null}
                            {latest.status === 'submitted' && canApprove ? (
                                <>
                                    <button
                                        className="border-border focus-ring min-h-9 rounded-lg border px-3 text-sm"
                                        onClick={() =>
                                            router.post(
                                                `${root}/date-sheet-revisions/${latest.id}/decision`,
                                                {
                                                    decision: 'rejected',
                                                    notes: 'Revision requires schedule changes.',
                                                },
                                            )
                                        }
                                    >
                                        Request changes
                                    </button>
                                    <button
                                        className="bg-brand-primary focus-ring min-h-9 rounded-lg px-3 text-sm text-white"
                                        onClick={() =>
                                            router.post(
                                                `${root}/date-sheet-revisions/${latest.id}/decision`,
                                                { decision: 'approved', notes: 'Schedule verified.' },
                                            )
                                        }
                                    >
                                        Approve revision
                                    </button>
                                </>
                            ) : null}
                            {latest.status === 'approved' && canPublish ? (
                                <button
                                    className="bg-brand-primary focus-ring min-h-9 rounded-lg px-3 text-sm text-white"
                                    onClick={() =>
                                        router.post(`${root}/date-sheet-revisions/${latest.id}/publish`)
                                    }
                                >
                                    Publish revision
                                </button>
                            ) : null}
                        </div>
                    </div>
                    <ul className="mt-3 grid gap-2 text-sm sm:grid-cols-2 xl:grid-cols-3">
                        {latest.sessions.map((session) => (
                            <li
                                key={session.session_id}
                                className="border-border rounded-md border px-3 py-2"
                            >
                                <span className="font-medium">{session.title}</span>
                                <span className="text-text-secondary block text-xs">
                                    {session.code} · {session.course.title ?? 'Course'}
                                </span>
                                <time
                                    className="text-text-secondary block text-xs"
                                    dateTime={session.starts_at_utc ?? undefined}
                                >
                                    {session.starts_at_utc
                                        ? new Date(session.starts_at_utc).toLocaleString()
                                        : 'Time not scheduled'}
                                </time>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
            {dateSheet && dateSheet.revisions.length > 1 ? (
                <details className="mt-2 text-sm">
                    <summary className="focus-ring cursor-pointer py-2">
                        Revision history ({dateSheet.revisions.length})
                    </summary>
                    <ol className="text-text-secondary grid gap-1 pl-5">
                        {dateSheet.revisions.slice(1).map((revision) => (
                            <li key={revision.id}>
                                Revision {revision.revision} · {revision.status}
                            </li>
                        ))}
                    </ol>
                </details>
            ) : null}
        </section>
    );
}

function SeatingPlanner({
    root,
    session,
    plan,
    rooms,
    canManage,
    canApprove,
    canPublish,
}: {
    root: string;
    session: Cycle['sessions'][number];
    plan: NonNullable<Props['seatingPlans'][number]> | null;
    rooms: Props['rooms'];
    canManage: boolean;
    canApprove: boolean;
    canPublish: boolean;
}) {
    const candidates = plan?.candidates ?? [];
    const form = useForm({
        assignments: candidates.map((candidate, index) => ({
            candidate_id: candidate.id,
            room_id: candidate.seat?.room_id ?? rooms[0]?.id ?? 0,
            seat_number: candidate.seat?.seat_number ?? `S-${String(index + 1).padStart(2, '0')}`,
        })),
    });
    const latest = plan?.revisions[0];
    const save = (event: FormEvent) => {
        event.preventDefault();
        form.post(`${root}/sessions/${session.id}/seating-plans`, { preserveScroll: true });
    };
    const act = (
        revisionId: number,
        action: 'submit' | 'decision' | 'publish',
        payload?: Record<string, FormDataConvertible>,
    ) => {
        router.post(`${root}/seating-plan-revisions/${revisionId}/${action}`, payload, {
            preserveScroll: true,
        });
    };

    return (
        <section
            className="border-border mt-4 border-t pt-4"
            aria-label={`Seating planner for ${session.title}`}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="font-medium">Candidate seating plan</h3>
                    <p className="text-text-secondary mt-1 text-sm">
                        {plan?.status
                            ? `Status: ${plan.status} · Published revision: ${plan.publishedRevision || 'none'}`
                            : 'No reviewed seating plan has been created for this session.'}
                    </p>
                </div>
                {canManage ? (
                    <span className="text-text-secondary text-sm">
                        Rooms and capacities come from this campus schedule.
                    </span>
                ) : null}
            </div>
            {canManage && candidates.length > 0 ? (
                <form onSubmit={save} className="mt-3">
                    <FormErrors errors={form.errors} />
                    <div className="border-border overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[34rem] text-left text-sm">
                            <caption className="sr-only">Seat assignments for {session.title}</caption>
                            <thead>
                                <tr className="border-border text-text-secondary border-b">
                                    <th className="p-2">Candidate</th>
                                    <th className="p-2">Room</th>
                                    <th className="p-2">Seat</th>
                                </tr>
                            </thead>
                            <tbody>
                                {candidates.map((candidate, index) => (
                                    <tr key={candidate.id} className="border-border border-b last:border-0">
                                        <th scope="row" className="p-2 font-medium">
                                            {candidate.studentName}
                                            <span className="text-text-secondary block text-xs">
                                                {candidate.candidateNumber}
                                            </span>
                                        </th>
                                        <td className="p-2">
                                            <select
                                                aria-label={`Room for ${candidate.candidateNumber}`}
                                                required
                                                className="border-border bg-canvas focus-ring min-h-10 w-full rounded-md border px-2"
                                                value={form.data.assignments[index]?.room_id ?? ''}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'assignments',
                                                        form.data.assignments.map((row, rowIndex) =>
                                                            rowIndex === index
                                                                ? {
                                                                      ...row,
                                                                      room_id: Number(
                                                                          event.currentTarget.value,
                                                                      ),
                                                                  }
                                                                : row,
                                                        ),
                                                    )
                                                }
                                            >
                                                <option value="">Select room</option>
                                                {rooms.map((room) => (
                                                    <option key={room.id} value={room.id}>
                                                        {room.name} ({room.code}, capacity {room.capacity})
                                                    </option>
                                                ))}
                                            </select>
                                        </td>
                                        <td className="p-2">
                                            <input
                                                aria-label={`Seat for ${candidate.candidateNumber}`}
                                                required
                                                maxLength={24}
                                                pattern="[A-Za-z0-9._-]+"
                                                className="border-border bg-canvas focus-ring min-h-10 w-full rounded-md border px-2"
                                                value={form.data.assignments[index]?.seat_number ?? ''}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'assignments',
                                                        form.data.assignments.map((row, rowIndex) =>
                                                            rowIndex === index
                                                                ? {
                                                                      ...row,
                                                                      seat_number: event.currentTarget.value,
                                                                  }
                                                                : row,
                                                        ),
                                                    )
                                                }
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <button
                        disabled={form.processing || rooms.length === 0 || candidates.length === 0}
                        className="bg-brand-primary focus-ring mt-3 min-h-10 rounded-lg px-3 text-sm font-medium text-white disabled:opacity-60"
                    >
                        Save seating revision
                    </button>
                </form>
            ) : candidates.length === 0 ? (
                <p className="text-text-secondary mt-3 text-sm">
                    Register eligible candidates before preparing the seating plan.
                </p>
            ) : null}
            {latest ? (
                <div className="border-border mt-3 rounded-lg border p-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-sm font-medium">
                            Revision {latest.revision} · {latest.status}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {latest.status === 'draft' && canManage ? (
                                <button
                                    type="button"
                                    className="border-border focus-ring min-h-9 rounded-lg border px-3 text-sm"
                                    onClick={() => act(latest.id, 'submit')}
                                >
                                    Submit for review
                                </button>
                            ) : null}
                            {latest.status === 'submitted' && canApprove ? (
                                <>
                                    <button
                                        type="button"
                                        className="border-border focus-ring min-h-9 rounded-lg border px-3 text-sm"
                                        onClick={() =>
                                            act(latest.id, 'decision', {
                                                decision: 'rejected',
                                                notes: 'Please resolve the seating conflicts.',
                                            })
                                        }
                                    >
                                        Request changes
                                    </button>
                                    <button
                                        type="button"
                                        className="bg-brand-primary focus-ring min-h-9 rounded-lg px-3 text-sm text-white"
                                        onClick={() =>
                                            act(latest.id, 'decision', {
                                                decision: 'approved',
                                                notes: 'Candidate, room, and capacity checks reviewed.',
                                            })
                                        }
                                    >
                                        Approve seating
                                    </button>
                                </>
                            ) : null}
                            {latest.status === 'approved' && canPublish ? (
                                <button
                                    type="button"
                                    className="bg-brand-primary focus-ring min-h-9 rounded-lg px-3 text-sm text-white"
                                    onClick={() => act(latest.id, 'publish')}
                                >
                                    Publish seating
                                </button>
                            ) : null}
                        </div>
                    </div>
                    {plan && plan.revisions.length > 1 ? (
                        <details className="mt-2 text-sm">
                            <summary className="focus-ring cursor-pointer py-2">
                                Plan revision history ({plan.revisions.length})
                            </summary>
                            <ol className="text-text-secondary grid gap-1 pl-5">
                                {plan.revisions.slice(1).map((revision) => (
                                    <li key={revision.id}>
                                        Revision {revision.revision} · {revision.status}
                                    </li>
                                ))}
                            </ol>
                        </details>
                    ) : null}
                </div>
            ) : null}
        </section>
    );
}

function DeliveryTools({
    root,
    session,
    quizzes,
    papers,
    delivery,
    canConfigure,
}: {
    root: string;
    session: Cycle['sessions'][number];
    quizzes: Props['deliveryQuizzes'];
    papers: Props['paperVersions'];
    delivery?: Props['sessionDeliveries'][number];
    canConfigure: boolean;
}) {
    const form = useForm({ quiz_id: '', paper_version_id: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`${root}/sessions/${session.id}/delivery`);
    };

    return (
        <section
            className="border-border mt-4 border-t pt-4"
            aria-label={`Online delivery for ${session.title}`}
        >
            <h3 className="font-medium">Online exam delivery</h3>
            {delivery ? (
                <p className="text-text-secondary mt-2 text-sm" role="status">
                    Bound to published quiz “{delivery.title}” and approved paper. This binding is immutable
                    after candidate attempts begin.
                </p>
            ) : canConfigure ? (
                <form onSubmit={submit} className="mt-3 grid gap-3 sm:grid-cols-3">
                    <label className="grid gap-1 text-sm">
                        Published quiz
                        <select
                            required
                            className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                            value={form.data.quiz_id}
                            onChange={(event) => form.setData('quiz_id', event.currentTarget.value)}
                        >
                            <option value="">Select a fixed-question quiz</option>
                            {quizzes.map((quiz) => (
                                <option key={quiz.id} value={quiz.id}>
                                    {quiz.title}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">
                        Current approved paper
                        <select
                            required
                            className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                            value={form.data.paper_version_id}
                            onChange={(event) => form.setData('paper_version_id', event.currentTarget.value)}
                        >
                            <option value="">Select published paper</option>
                            {papers.map((paper) => (
                                <option key={paper.id} value={paper.id}>
                                    {paper.title} · {paper.version_number}
                                    {paper.set_code}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="flex items-end">
                        <button
                            type="submit"
                            disabled={form.processing || quizzes.length === 0 || papers.length === 0}
                            className="focus-ring bg-primary text-primary-foreground min-h-11 rounded-lg px-4 font-medium disabled:opacity-60"
                        >
                            Bind exam delivery
                        </button>
                    </div>
                    <FormErrors errors={form.errors} />
                    {quizzes.length === 0 || papers.length === 0 ? (
                        <p className="text-text-secondary text-sm" role="status">
                            A published fixed-question quiz and a current published paper are required.
                        </p>
                    ) : null}
                </form>
            ) : (
                <p className="text-text-secondary mt-2 text-sm">No online delivery has been configured.</p>
            )}
        </section>
    );
}

function PaperTools({
    root,
    session,
    versions,
    questions,
    canManage,
    canReview,
    canPublish,
    canPreview,
}: {
    root: string;
    session: Cycle['sessions'][number];
    versions: Props['paperVersions'];
    questions: Props['questionOptions'];
    canManage: boolean;
    canReview: boolean;
    canPublish: boolean;
    canPreview: boolean;
}) {
    const form = useForm({
        set_code: 'A',
        title: '',
        instructions: '',
        duration_minutes: session.duration_minutes,
        questions: [{ question_version_id: '', points: '1.00' }],
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`${root}/sessions/${session.id}/papers`, {
            onSuccess: () => form.reset('title', 'instructions'),
        });
    };
    const updateQuestion = (index: number, key: 'question_version_id' | 'points', value: string) => {
        form.setData(
            'questions',
            form.data.questions.map((question, row) =>
                row === index ? { ...question, [key]: value } : question,
            ),
        );
    };

    return (
        <section
            className="border-border mt-4 border-t pt-4"
            aria-label={`Paper governance for ${session.title}`}
        >
            <h3 className="font-medium">Examination paper · {session.code}</h3>
            {canManage ? (
                <details className="mt-2">
                    <summary className="focus-ring cursor-pointer rounded text-sm font-medium">
                        Create immutable paper version
                    </summary>
                    <FormErrors errors={form.errors} />
                    {questions.length === 0 ? (
                        <p role="status" className="text-text-secondary mt-2 text-sm">
                            No active approved question-bank entries are available for this institution.
                        </p>
                    ) : (
                        <form onSubmit={submit} className="mt-3 grid gap-3 sm:grid-cols-2">
                            <label className="grid gap-1 text-sm">
                                Paper title
                                <input
                                    required
                                    maxLength={180}
                                    className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                    value={form.data.title}
                                    onChange={(event) => form.setData('title', event.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Set code
                                <input
                                    required
                                    maxLength={8}
                                    pattern="[A-Z0-9]+"
                                    className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                    value={form.data.set_code}
                                    onChange={(event) =>
                                        form.setData('set_code', event.currentTarget.value.toUpperCase())
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Duration in minutes
                                <input
                                    required
                                    type="number"
                                    min={1}
                                    max={session.duration_minutes}
                                    className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                    value={form.data.duration_minutes}
                                    onChange={(event) =>
                                        form.setData('duration_minutes', Number(event.currentTarget.value))
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Instructions
                                <textarea
                                    maxLength={5000}
                                    className="border-border bg-canvas min-h-11 rounded-lg border px-3 py-2"
                                    value={form.data.instructions}
                                    onChange={(event) =>
                                        form.setData('instructions', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <div className="sm:col-span-2">
                                <h4 className="text-sm font-medium">Question snapshots</h4>
                                <div className="mt-2 grid gap-3">
                                    {form.data.questions.map((question, index) => (
                                        <div
                                            key={index}
                                            className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_8rem_auto]"
                                        >
                                            <label className="grid gap-1 text-sm">
                                                Approved question
                                                <select
                                                    required
                                                    className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                                    value={question.question_version_id}
                                                    onChange={(event) =>
                                                        updateQuestion(
                                                            index,
                                                            'question_version_id',
                                                            event.currentTarget.value,
                                                        )
                                                    }
                                                >
                                                    <option value="">Choose a question</option>
                                                    {questions.map((option) => (
                                                        <option key={option.id} value={option.id}>
                                                            {option.content.prompt ?? option.question_type} ·{' '}
                                                            {option.question.bank.name} · {option.difficulty}
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>
                                            <label className="grid gap-1 text-sm">
                                                Points
                                                <input
                                                    required
                                                    type="number"
                                                    min="0.01"
                                                    max="10000"
                                                    step="0.01"
                                                    className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                                    value={question.points}
                                                    onChange={(event) =>
                                                        updateQuestion(
                                                            index,
                                                            'points',
                                                            event.currentTarget.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                            {form.data.questions.length > 1 ? (
                                                <button
                                                    type="button"
                                                    className="border-border focus-ring min-h-11 self-end rounded-lg border px-3 text-sm"
                                                    onClick={() =>
                                                        form.setData(
                                                            'questions',
                                                            form.data.questions.filter(
                                                                (_, row) => row !== index,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    Remove
                                                </button>
                                            ) : null}
                                        </div>
                                    ))}
                                </div>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                                        onClick={() =>
                                            form.setData('questions', [
                                                ...form.data.questions,
                                                { question_version_id: '', points: '1.00' },
                                            ])
                                        }
                                    >
                                        Add question
                                    </button>
                                    <button
                                        disabled={form.processing}
                                        className="bg-brand-primary focus-ring min-h-10 rounded-lg px-4 text-sm font-medium text-white disabled:opacity-60"
                                    >
                                        Save confidential draft
                                    </button>
                                </div>
                            </div>
                        </form>
                    )}
                </details>
            ) : null}
            {versions.length ? (
                <ol className="mt-3 grid gap-2">
                    {versions.map((version) => (
                        <li
                            key={version.id}
                            className="border-border flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm"
                        >
                            <span>
                                <strong>{version.title}</strong>
                                <span className="text-text-secondary block">
                                    Set {version.set_code} · v{version.version_number} · {version.total_marks}{' '}
                                    marks · {version.status}
                                </span>
                            </span>
                            <span className="flex flex-wrap gap-2">
                                {canManage && version.status === 'draft' ? (
                                    <button
                                        className="border-border focus-ring min-h-9 rounded border px-2"
                                        onClick={() =>
                                            router.post(`${root}/paper-versions/${version.id}/submit-review`)
                                        }
                                    >
                                        Submit review
                                    </button>
                                ) : null}
                                {canReview && version.status === 'submitted' ? (
                                    <>
                                        <button
                                            className="border-border focus-ring min-h-9 rounded border px-2"
                                            onClick={() =>
                                                router.post(`${root}/paper-versions/${version.id}/review`, {
                                                    decision: 'approved',
                                                    notes: '',
                                                })
                                            }
                                        >
                                            Approve version
                                        </button>
                                        <button
                                            className="border-border focus-ring min-h-9 rounded border px-2"
                                            onClick={() =>
                                                router.post(`${root}/paper-versions/${version.id}/review`, {
                                                    decision: 'rejected',
                                                    notes: '',
                                                })
                                            }
                                        >
                                            Reject version
                                        </button>
                                    </>
                                ) : null}
                                {canPublish && version.status === 'approved' ? (
                                    <button
                                        className="bg-brand-primary focus-ring min-h-9 rounded px-2 text-white"
                                        onClick={() =>
                                            router.post(`${root}/paper-versions/${version.id}/publish`)
                                        }
                                    >
                                        Publish version
                                    </button>
                                ) : null}
                                {canPreview ? (
                                    <a
                                        className="border-border focus-ring inline-flex min-h-9 items-center rounded border px-2"
                                        href={`${root}/paper-versions/${version.id}/confidential-preview`}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Preview
                                    </a>
                                ) : null}
                            </span>
                        </li>
                    ))}
                </ol>
            ) : (
                <p className="text-text-secondary mt-2 text-sm">
                    No paper versions have been prepared for this session.
                </p>
            )}
        </section>
    );
}

function SessionOperations({
    root,
    cycle,
    session,
    candidates,
    staff,
    assignments,
    attendance,
    canAssign,
    canRecord,
    canAccommodate,
}: {
    root: string;
    cycle: Cycle;
    session: Cycle['sessions'][number];
    candidates: NonNullable<Cycle['candidates']>;
    staff: Props['staffOptions'];
    assignments: Props['invigilatorAssignments'];
    attendance: Props['attendanceRecords'];
    canAssign: boolean;
    canRecord: boolean;
    canAccommodate: boolean;
}) {
    const assignmentForm = useForm({ user_id: '', duty_role: 'invigilator' });
    const assign = (event: FormEvent) => {
        event.preventDefault();
        assignmentForm.post(`${root}/sessions/${session.id}/invigilators`, {
            onSuccess: () => assignmentForm.reset(),
        });
    };
    const attendanceByCandidate = new Map(attendance.map((row) => [row.candidate_id, row]));

    return (
        <section
            className="border-border border-t py-4"
            aria-label={`Invigilation and attendance for ${session.title}`}
        >
            <h3 className="font-medium">Invigilation and exam attendance</h3>
            {canAssign ? (
                <>
                    <FormErrors errors={assignmentForm.errors} />
                    <form
                        onSubmit={assign}
                        className="mt-3 grid gap-2 sm:grid-cols-[minmax(0,1fr)_12rem_auto] sm:items-end"
                    >
                        <label className="grid gap-1 text-sm">
                            Assign staff member
                            <select
                                required
                                className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                value={assignmentForm.data.user_id}
                                onChange={(event) =>
                                    assignmentForm.setData('user_id', event.currentTarget.value)
                                }
                            >
                                <option value="">Select active member</option>
                                {staff.map((member) => (
                                    <option key={member.id} value={member.id}>
                                        {member.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Duty
                            <select
                                className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                                value={assignmentForm.data.duty_role}
                                onChange={(event) =>
                                    assignmentForm.setData('duty_role', event.currentTarget.value)
                                }
                            >
                                <option value="chief_invigilator">Chief invigilator</option>
                                <option value="invigilator">Invigilator</option>
                                <option value="relief">Relief</option>
                                <option value="examiner">Examiner</option>
                            </select>
                        </label>
                        <button
                            disabled={assignmentForm.processing || staff.length === 0}
                            className="bg-brand-primary focus-ring min-h-11 rounded-lg px-4 text-sm font-medium text-white disabled:opacity-60"
                        >
                            Assign duty
                        </button>
                    </form>
                </>
            ) : null}
            {assignments.length ? (
                <ul className="mt-3 flex flex-wrap gap-2" aria-label="Assigned invigilators">
                    {assignments.map((assignment) => (
                        <li
                            key={assignment.user_id}
                            className="border-border rounded-full border px-3 py-1 text-xs"
                        >
                            {assignment.name} · {assignment.duty_role.replaceAll('_', ' ')}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-text-secondary mt-2 text-sm">No staff duty assignments are recorded.</p>
            )}
            {canRecord ? (
                <div className="mt-4 grid gap-2">
                    {candidates.length ? (
                        candidates.map((candidate) => (
                            <CandidateExamOperations
                                key={candidate.id}
                                root={root}
                                cycle={cycle}
                                session={session}
                                candidate={candidate}
                                attendance={attendanceByCandidate.get(candidate.id)}
                                canRecord={canRecord}
                                canAccommodate={canAccommodate}
                            />
                        ))
                    ) : (
                        <p className="text-text-secondary text-sm">
                            Candidate attendance appears after registration.
                        </p>
                    )}
                </div>
            ) : null}
        </section>
    );
}

function CandidateExamOperations({
    root,
    cycle,
    session,
    candidate,
    attendance,
    canRecord,
    canAccommodate,
}: {
    root: string;
    cycle: Cycle;
    session: Cycle['sessions'][number];
    candidate: NonNullable<Cycle['candidates']>[number];
    attendance?: Props['attendanceRecords'][number];
    canRecord: boolean;
    canAccommodate: boolean;
}) {
    const attendanceForm = useForm({
        candidate_id: String(candidate.id),
        attendance_status: attendance?.attendance_status ?? 'present',
        safe_note: '',
    });
    const accommodationForm = useForm({
        accommodation_type: 'extra_time',
        extra_time_minutes: 0,
        confidential_details: '',
        expires_at: '',
    });
    const saveAttendance = (event: FormEvent) => {
        event.preventDefault();
        attendanceForm.post(`${root}/sessions/${session.id}/attendance`);
    };
    const saveAccommodation = (event: FormEvent) => {
        event.preventDefault();
        accommodationForm.post(`${root}/cycles/${cycle.id}/candidates/${candidate.id}/accommodations`, {
            onSuccess: () => accommodationForm.reset(),
        });
    };
    const studentName = `${candidate.student.legal_first_name} ${candidate.student.legal_last_name}`;

    return (
        <article className="border-border grid gap-2 rounded-lg border p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] sm:items-start">
            <div>
                <h4 className="text-sm font-medium">{studentName}</h4>
                <p className="text-text-secondary text-xs">
                    {candidate.candidate_number} ·{' '}
                    {attendance
                        ? `${attendance.attendance_status}, revision ${attendance.revision}`
                        : 'attendance not recorded'}
                </p>
            </div>
            {canRecord ? <FormErrors errors={attendanceForm.errors} /> : null}
            {canRecord ? (
                <form
                    onSubmit={saveAttendance}
                    className="grid gap-2 sm:grid-cols-[10rem_minmax(0,1fr)_auto] sm:items-end"
                >
                    <label className="grid gap-1 text-xs">
                        Attendance
                        <select
                            className="border-border bg-canvas min-h-10 rounded-lg border px-2 text-sm"
                            value={attendanceForm.data.attendance_status}
                            onChange={(event) =>
                                attendanceForm.setData('attendance_status', event.currentTarget.value)
                            }
                        >
                            <option value="present">Present</option>
                            <option value="late">Late</option>
                            <option value="absent">Absent</option>
                            <option value="withdrawn">Withdrawn</option>
                        </select>
                    </label>
                    <label className="grid gap-1 text-xs">
                        Safe correction note
                        <input
                            maxLength={500}
                            className="border-border bg-canvas min-h-10 rounded-lg border px-2 text-sm"
                            value={attendanceForm.data.safe_note}
                            onChange={(event) =>
                                attendanceForm.setData('safe_note', event.currentTarget.value)
                            }
                        />
                    </label>
                    <button
                        disabled={attendanceForm.processing}
                        className="border-border focus-ring min-h-10 rounded-lg border px-3 text-sm"
                    >
                        Save attendance
                    </button>
                </form>
            ) : null}
            {canAccommodate ? (
                <details className="sm:col-span-2">
                    <summary className="focus-ring cursor-pointer rounded text-sm">
                        Authorize confidential accommodation
                    </summary>
                    <FormErrors errors={accommodationForm.errors} />
                    <form onSubmit={saveAccommodation} className="mt-3 grid gap-2 sm:grid-cols-2">
                        <label className="grid gap-1 text-xs">
                            Accommodation
                            <select
                                className="border-border bg-canvas min-h-10 rounded-lg border px-2 text-sm"
                                value={accommodationForm.data.accommodation_type}
                                onChange={(event) =>
                                    accommodationForm.setData('accommodation_type', event.currentTarget.value)
                                }
                            >
                                {[
                                    'extra_time',
                                    'reader',
                                    'scribe',
                                    'accessible_room',
                                    'assistive_technology',
                                    'other',
                                ].map((type) => (
                                    <option key={type} value={type}>
                                        {type.replaceAll('_', ' ')}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-xs">
                            Extra minutes
                            <input
                                type="number"
                                min={0}
                                max={300}
                                className="border-border bg-canvas min-h-10 rounded-lg border px-2 text-sm"
                                value={accommodationForm.data.extra_time_minutes}
                                onChange={(event) =>
                                    accommodationForm.setData(
                                        'extra_time_minutes',
                                        Number(event.currentTarget.value),
                                    )
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-xs sm:col-span-2">
                            Confidential authorization details
                            <textarea
                                maxLength={2000}
                                className="border-border bg-canvas min-h-20 rounded-lg border px-2 py-2 text-sm"
                                value={accommodationForm.data.confidential_details}
                                onChange={(event) =>
                                    accommodationForm.setData(
                                        'confidential_details',
                                        event.currentTarget.value,
                                    )
                                }
                            />
                        </label>
                        <button
                            disabled={accommodationForm.processing}
                            className="bg-brand-primary focus-ring min-h-10 w-fit rounded-lg px-3 text-sm font-medium text-white"
                        >
                            Save accommodation
                        </button>
                    </form>
                </details>
            ) : null}
        </article>
    );
}

function CandidateForm({
    root,
    cycle,
    students,
}: {
    root: string;
    cycle: Cycle;
    students: Props['students'];
}) {
    const form = useForm({ student_id: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`${root}/cycles/${cycle.id}/candidates`, { onSuccess: () => form.reset() });
    };
    return (
        <form
            onSubmit={submit}
            className="border-border mt-4 grid gap-3 border-t pt-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
        >
            <label className="grid gap-1 text-sm">
                Register eligible student
                <select
                    required
                    className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                    value={form.data.student_id}
                    onChange={(e) => form.setData('student_id', e.currentTarget.value)}
                >
                    <option value="">Select student</option>
                    {students.map((student) => (
                        <option key={student.id} value={student.id}>
                            {student.legal_first_name} {student.legal_last_name} · {student.student_number}
                        </option>
                    ))}
                </select>
                {form.errors.student_id ? (
                    <span role="alert" className="text-red-700">
                        {form.errors.student_id}
                    </span>
                ) : null}
            </label>
            <button
                disabled={form.processing || students.length === 0}
                className="bg-brand-primary focus-ring min-h-11 rounded-lg px-4 font-medium text-white disabled:opacity-60"
            >
                Register candidate
            </button>
        </form>
    );
}

function SessionForm({
    root,
    cycle,
    campuses,
    courses,
    rooms,
}: { root: string; cycle: Cycle } & Pick<Props, 'campuses' | 'courses' | 'rooms'>) {
    const form = useForm({
        campus_id: '',
        academic_course_id: '',
        room_id: '',
        code: '',
        title: '',
        mode: 'onsite',
        starts_at: '',
        ends_at: '',
        reporting_at: '',
        duration_minutes: 60,
        capacity: '',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`${root}/cycles/${cycle.id}/sessions`);
    };
    return (
        <details className="border-border mt-4 border-t pt-4">
            <summary className="focus-ring cursor-pointer rounded font-medium">
                Add a scheduled exam session
            </summary>
            <FormErrors errors={form.errors} />
            <form onSubmit={submit} className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <label className="grid gap-1 text-sm">
                    Session title
                    <input
                        required
                        maxLength={180}
                        className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.currentTarget.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    Session code
                    <input
                        required
                        maxLength={48}
                        className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                        value={form.data.code}
                        onChange={(e) => form.setData('code', e.currentTarget.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    Course
                    <select
                        required
                        className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                        value={form.data.academic_course_id}
                        onChange={(e) => form.setData('academic_course_id', e.currentTarget.value)}
                    >
                        <option value="">Select course</option>
                        {courses.map((course) => (
                            <option key={course.id} value={course.id}>
                                {course.title} ({course.code})
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm">
                    Campus
                    <select
                        required
                        className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                        value={form.data.campus_id}
                        onChange={(e) => form.setData('campus_id', e.currentTarget.value)}
                    >
                        <option value="">Select campus</option>
                        {campuses.map((campus) => (
                            <option key={campus.id} value={campus.id}>
                                {campus.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm">
                    Room
                    <select
                        className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                        value={form.data.room_id}
                        onChange={(e) => form.setData('room_id', e.currentTarget.value)}
                    >
                        <option value="">No room yet</option>
                        {rooms
                            .filter(
                                (room) =>
                                    !form.data.campus_id || String(room.campus_id) === form.data.campus_id,
                            )
                            .map((room) => (
                                <option key={room.id} value={room.id}>
                                    {room.name} ({room.code}, {room.capacity} seats)
                                </option>
                            ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm">
                    Format
                    <select
                        className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                        value={form.data.mode}
                        onChange={(e) => form.setData('mode', e.currentTarget.value)}
                    >
                        {['onsite', 'online', 'practical', 'oral'].map((mode) => (
                            <option key={mode} value={mode}>
                                {mode}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm">
                    Starts
                    <input
                        required
                        type="datetime-local"
                        className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                        value={form.data.starts_at}
                        onChange={(e) => form.setData('starts_at', e.currentTarget.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    Ends
                    <input
                        required
                        type="datetime-local"
                        className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                        value={form.data.ends_at}
                        onChange={(e) => form.setData('ends_at', e.currentTarget.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    Reporting time
                    <input
                        type="datetime-local"
                        className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                        value={form.data.reporting_at}
                        onChange={(e) => form.setData('reporting_at', e.currentTarget.value)}
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    Duration (minutes)
                    <input
                        required
                        type="number"
                        min={1}
                        max={600}
                        className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                        value={form.data.duration_minutes}
                        onChange={(e) => form.setData('duration_minutes', Number(e.currentTarget.value))}
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    Candidate capacity
                    <input
                        type="number"
                        min={1}
                        max={5000}
                        className="border-border bg-canvas min-h-11 rounded-lg border px-3"
                        value={form.data.capacity}
                        onChange={(e) => form.setData('capacity', e.currentTarget.value)}
                    />
                </label>
                <div className="flex items-end">
                    <button
                        disabled={form.processing || courses.length === 0 || campuses.length === 0}
                        className="bg-brand-primary focus-ring min-h-11 w-full rounded-lg px-4 font-medium text-white disabled:opacity-60"
                    >
                        Add session
                    </button>
                </div>
            </form>
        </details>
    );
}
