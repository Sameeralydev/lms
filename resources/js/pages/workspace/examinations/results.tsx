import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Props = {
    tenant: { slug: string };
    actorId: number;
    canExport: boolean;
    canReportIncidents: boolean;
    canReviewIncidents: boolean;
    workspace: {
        sessions: { id: number; title: string; status: string; startsAt: string | null }[];
        components: { id: number; sessionId: number; title: string; type: string; maximum: string }[];
        policies: {
            sessionId: number;
            version: number;
            writtenWeight: string;
            passPercent: string | null;
            recheckDays: number;
            components: { id: number; title: string; weight: string }[];
        }[];
        results: {
            id: number;
            sessionId: number;
            candidateNumber: string;
            session: string;
            version: number;
            status: string;
            outcome: string;
            marks: string | null;
            maximum: string;
        }[];
        rechecks: {
            id: number;
            status: string;
            type: string;
            reason: string;
            reviewerId: number | null;
            proposedMarks: string | null;
            candidateNumber: string;
            resultStatus: string;
            canUploadEvidence: boolean;
            evidence: { id: number; name: string; scanStatus: string }[];
            history: {
                id: number;
                action: string;
                from: string | null;
                to: string;
                resultVersion: number | null;
                at: string;
            }[];
        }[];
        incidents: {
            id: number;
            sessionId: number;
            candidateId: number | null;
            type: string;
            severity: string;
            description: string;
            status: string;
            decision: string | null;
            reportedBy: number;
            assignedTo: number | null;
            reviewers: { id: number; name: string }[];
            evidence: { id: number; name: string; scanStatus: string }[];
            history: {
                id: number;
                event: string;
                from: string | null;
                to: string | null;
                decision: string | null;
                notes: string;
                at: string;
            }[];
        }[];
        candidates: { id: number; number: string }[];
        reviewers: { id: number; name: string }[];
        analytics: {
            releasedOutcomes: { outcome: string; count: number }[];
            subjectPerformance: {
                campus: string;
                courseCode: string;
                courseTitle: string;
                releasedCandidates: number;
                averagePercentage: number;
                passRatePercent: number | null;
            }[];
            componentProgress: { status: string; count: number }[];
        };
        operationalReports: {
            sessionId: number;
            session: string;
            status: string;
            startsAt: string;
            campus: string;
            subject: string;
            registered: number;
            present: number;
            absent: number;
            markingAssigned: number;
            markingSubmitted: number;
            moderationBacklog: number;
            resultsPending: number;
            resultsReleased: number;
            openRechecks: number;
            openAppeals: number;
            openIncidents: number;
            dateSheetStatus: string;
            seatsAllocated: number;
            seatCapacity: number;
            seatUtilizationPercent: number | null;
            invigilatorsAssigned: number;
            invigilatorsAccepted: number;
            eligibilityExceptionsPending: number;
            eligibilityExceptionsApproved: number;
            approvedAccommodations: number;
            activeAdmitDocuments: number;
            retakeRegistrations: number;
            makeupRegistrations: number;
        }[];
        metrics: Record<string, number>;
    };
};

export default function ExaminationResults({
    tenant,
    workspace,
    actorId,
    canExport,
    canReportIncidents,
    canReviewIncidents,
}: Props) {
    const [selectedSession, setSelectedSession] = useState(workspace.sessions[0]?.id ?? 0);
    const [writtenWeight, setWrittenWeight] = useState('100');
    const [passPercent, setPassPercent] = useState('');
    const [recheckDays, setRecheckDays] = useState('14');
    const [weights, setWeights] = useState<Record<number, string>>({});
    const [feedback, setFeedback] = useState('');
    const [incident, setIncident] = useState({
        session_id: workspace.sessions[0]?.id ?? 0,
        candidate_id: '',
        incident_type: 'technical',
        severity: 'standard',
        description: '',
    });
    const [incidentEvidence, setIncidentEvidence] = useState<Record<number, File | null>>({});
    const [appealEvidence, setAppealEvidence] = useState<Record<number, File | null>>({});
    const root = `/t/${tenant.slug}/academics/examinations`;
    const act = (url: string, data: Record<string, string | number | Record<string, string>> = {}) =>
        router.post(`${root}${url}`, data, {
            onSuccess: () => setFeedback('The examination operation was saved.'),
            onError: () =>
                setFeedback('The request needs attention. Review the validation messages and try again.'),
        });

    return (
        <WorkspaceLayout title="Examination results" area="tenant">
            <Head title="Examination results" />
            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    title="Results & appeals"
                    description="Compile versioned examination results, review them independently, release approved results, and track rechecks and incidents."
                />
                <p role="status" aria-live="polite" className="text-text-muted text-sm">
                    {feedback}
                </p>
                <section
                    aria-label="Examination result metrics"
                    className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                >
                    {Object.entries(workspace.metrics).map(([key, value]) => (
                        <article key={key} className="border-border bg-surface rounded-xl border p-4">
                            <p className="text-text-muted text-sm capitalize">
                                {key.replace(/([A-Z])/g, ' $1')}
                            </p>
                            <p className="mt-1 text-2xl font-semibold">{value}</p>
                        </article>
                    ))}
                </section>
                <section className="grid gap-4 lg:grid-cols-2" aria-label="Result and marking analytics">
                    <article className="border-border bg-surface rounded-2xl border p-5">
                        <h2 className="text-lg font-semibold">Released result distribution</h2>
                        <p className="text-text-muted mt-1 text-sm">
                            Released results only, within your authorized campus scope.
                        </p>
                        <ul className="mt-3 space-y-2">
                            {workspace.analytics.releasedOutcomes.map((item) => (
                                <li
                                    className="bg-canvas flex justify-between rounded-lg p-3"
                                    key={item.outcome}
                                >
                                    <span className="capitalize">{item.outcome}</span>
                                    <strong>{item.count}</strong>
                                </li>
                            ))}
                        </ul>
                        {workspace.analytics.releasedOutcomes.length === 0 && (
                            <p className="text-text-muted py-4 text-sm">No released results in this scope.</p>
                        )}
                    </article>
                    <article className="border-border bg-surface rounded-2xl border p-5">
                        <h2 className="text-lg font-semibold">Component marking progress</h2>
                        <p className="text-text-muted mt-1 text-sm">
                            Live allocation counts across authorized examination sessions.
                        </p>
                        <ul className="mt-3 space-y-2">
                            {workspace.analytics.componentProgress.map((item) => (
                                <li
                                    className="bg-canvas flex justify-between rounded-lg p-3"
                                    key={item.status}
                                >
                                    <span className="capitalize">{item.status.replaceAll('_', ' ')}</span>
                                    <strong>{item.count}</strong>
                                </li>
                            ))}
                        </ul>
                        {workspace.analytics.componentProgress.length === 0 && (
                            <p className="text-text-muted py-4 text-sm">
                                No component marking allocations exist yet.
                            </p>
                        )}
                    </article>
                </section>
                <section
                    className="border-border bg-surface rounded-2xl border p-5"
                    aria-labelledby="subject-performance-heading"
                >
                    <h2 id="subject-performance-heading" className="text-lg font-semibold">
                        Released subject and campus performance
                    </h2>
                    <p className="text-text-muted mt-1 text-sm">
                        Aggregated from released results in your authorized campus scope; no candidate scores
                        are shown.
                    </p>
                    {workspace.analytics.subjectPerformance.length === 0 ? (
                        <p className="text-text-muted py-4 text-sm">
                            No released subject results in this scope.
                        </p>
                    ) : (
                        <div
                            className="border-border mt-3 overflow-x-auto rounded-xl border"
                            role="region"
                            aria-label="Scrollable subject and campus performance"
                            tabIndex={0}
                        >
                            <table className="w-full min-w-[600px] text-left text-sm">
                                <caption className="sr-only">
                                    Released result performance by campus and subject
                                </caption>
                                <thead className="bg-canvas text-text-muted">
                                    <tr>
                                        {[
                                            'Campus',
                                            'Subject',
                                            'Released candidates',
                                            'Average',
                                            'Pass rate',
                                        ].map((heading) => (
                                            <th key={heading} className="px-3 py-2 font-medium" scope="col">
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {workspace.analytics.subjectPerformance.map((item) => (
                                        <tr
                                            className="border-border border-t"
                                            key={`${item.campus}-${item.courseCode}`}
                                        >
                                            <th className="px-3 py-2 font-medium" scope="row">
                                                {item.campus}
                                            </th>
                                            <td className="px-3 py-2">
                                                {item.courseCode} · {item.courseTitle}
                                            </td>
                                            <td className="px-3 py-2">{item.releasedCandidates}</td>
                                            <td className="px-3 py-2">
                                                {item.averagePercentage.toFixed(2)}%
                                            </td>
                                            <td className="px-3 py-2">
                                                {item.passRatePercent === null
                                                    ? '—'
                                                    : `${item.passRatePercent.toFixed(1)}%`}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
                <section
                    className="border-border bg-surface space-y-4 rounded-2xl border p-5"
                    aria-labelledby="operations-report-heading"
                >
                    <div>
                        <h2 id="operations-report-heading" className="text-lg font-semibold">
                            Examination operations report
                        </h2>
                        <p className="text-text-muted mt-1 text-sm">
                            Session-level registrations, attendance, marking, moderation and post-exam
                            workload in your authorized campus scope.
                        </p>
                    </div>
                    {workspace.operationalReports.length === 0 ? (
                        <p className="text-text-muted py-4 text-sm">
                            No examination sessions are available in this scope.
                        </p>
                    ) : (
                        <div
                            className="border-border overflow-x-auto rounded-xl border"
                            role="region"
                            aria-label="Scrollable examination operations report"
                            tabIndex={0}
                        >
                            <table className="w-full min-w-[1100px] text-left text-sm">
                                <caption className="sr-only">
                                    Examination operations by session, campus and subject
                                </caption>
                                <thead className="bg-canvas text-text-muted">
                                    <tr>
                                        {[
                                            'Session',
                                            'Campus / subject',
                                            'Registered',
                                            'Present / absent',
                                            'Marking assigned / submitted',
                                            'Moderation backlog',
                                            'Results pending / released',
                                            'Open rechecks / appeals / incidents',
                                            'Date sheet',
                                            'Seats / room capacity',
                                            'Invigilators accepted / assigned',
                                            'Eligibility exceptions pending / approved',
                                            'Approved accommodations',
                                            'Active admit documents',
                                            'Retake / makeup registrations',
                                        ].map((heading) => (
                                            <th className="px-3 py-2 font-medium" scope="col" key={heading}>
                                                {heading}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {workspace.operationalReports.map((report) => (
                                        <tr className="border-border border-t" key={report.sessionId}>
                                            <th className="px-3 py-3 font-medium" scope="row">
                                                <span className="block">{report.session}</span>
                                                <span className="text-text-muted text-xs">
                                                    {report.startsAt} UTC · {report.status}
                                                </span>
                                            </th>
                                            <td className="px-3 py-3">
                                                {report.campus}
                                                <span className="text-text-muted block text-xs">
                                                    {report.subject}
                                                </span>
                                            </td>
                                            <td className="px-3 py-3">{report.registered}</td>
                                            <td className="px-3 py-3">
                                                {report.present} / {report.absent}
                                            </td>
                                            <td className="px-3 py-3">
                                                {report.markingAssigned} / {report.markingSubmitted}
                                            </td>
                                            <td className="px-3 py-3">{report.moderationBacklog}</td>
                                            <td className="px-3 py-3">
                                                {report.resultsPending} / {report.resultsReleased}
                                            </td>
                                            <td className="px-3 py-3">
                                                {report.openRechecks} / {report.openAppeals} /{' '}
                                                {report.openIncidents}
                                            </td>
                                            <td className="px-3 py-3">{report.dateSheetStatus}</td>
                                            <td className="px-3 py-3">
                                                {report.seatsAllocated} / {report.seatCapacity}
                                                {report.seatUtilizationPercent === null
                                                    ? ''
                                                    : ` (${report.seatUtilizationPercent}%)`}
                                            </td>
                                            <td className="px-3 py-3">
                                                {report.invigilatorsAccepted} / {report.invigilatorsAssigned}
                                            </td>
                                            <td className="px-3 py-3">
                                                {report.eligibilityExceptionsPending} /{' '}
                                                {report.eligibilityExceptionsApproved}
                                            </td>
                                            <td className="px-3 py-3">{report.approvedAccommodations}</td>
                                            <td className="px-3 py-3">{report.activeAdmitDocuments}</td>
                                            <td className="px-3 py-3">
                                                {report.retakeRegistrations} / {report.makeupRegistrations}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
                <section className="border-border bg-surface rounded-2xl border p-5">
                    <h2 className="text-lg font-semibold">Result policy</h2>
                    <p className="text-text-muted mt-1 text-sm">
                        Each save creates an immutable policy version. Practical and oral components can be
                        combined with the written paper; weights must total exactly 100%.
                    </p>
                    <form
                        className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const components = workspace.components
                                .filter(
                                    (component) =>
                                        component.sessionId === selectedSession &&
                                        Number(weights[component.id] ?? 0) > 0,
                                )
                                .map((component) => ({
                                    component_id: component.id,
                                    weight_percent: weights[component.id],
                                }));
                            router.put(
                                `${root}/sessions/${selectedSession}/result-policy`,
                                {
                                    written_weight: writtenWeight,
                                    pass_percent: passPercent || null,
                                    recheck_window_days: recheckDays,
                                    components,
                                },
                                {
                                    onSuccess: () => setFeedback('A new result policy version was saved.'),
                                    onError: () =>
                                        setFeedback(
                                            'Policy validation failed. Check that configured weights total 100%.',
                                        ),
                                },
                            );
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            Examination session
                            <select
                                className="border-border bg-canvas rounded-lg border p-2"
                                value={selectedSession}
                                onChange={(event) => setSelectedSession(Number(event.target.value))}
                                required
                            >
                                {workspace.sessions.map((session) => (
                                    <option key={session.id} value={session.id}>
                                        {session.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Written paper weight (%)
                            <input
                                className="border-border bg-canvas rounded-lg border p-2"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                value={writtenWeight}
                                onChange={(event) => setWrittenWeight(event.target.value)}
                                required
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Pass threshold (%)
                            <input
                                className="border-border bg-canvas rounded-lg border p-2"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                value={passPercent}
                                onChange={(event) => setPassPercent(event.target.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Recheck window (days)
                            <input
                                className="border-border bg-canvas rounded-lg border p-2"
                                type="number"
                                min="1"
                                max="90"
                                value={recheckDays}
                                onChange={(event) => setRecheckDays(event.target.value)}
                                required
                            />
                        </label>
                        <button
                            className="bg-brand-action hover:bg-brand-action-hover self-end rounded-lg px-4 py-2 font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2"
                            type="submit"
                        >
                            Save policy version
                        </button>
                    </form>
                    <fieldset className="border-border mt-4 rounded-xl border p-4">
                        <legend className="px-1 text-sm font-semibold">
                            Practical & oral weight allocation
                        </legend>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {workspace.components
                                .filter((component) => component.sessionId === selectedSession)
                                .map((component) => (
                                    <label className="grid gap-1 text-sm" key={component.id}>
                                        {component.title} ({component.type}, max {component.maximum})
                                        <input
                                            className="border-border bg-canvas rounded-lg border p-2"
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            aria-label={`${component.title} weight percent`}
                                            placeholder="0"
                                            value={weights[component.id] ?? ''}
                                            onChange={(event) =>
                                                setWeights({ ...weights, [component.id]: event.target.value })
                                            }
                                        />
                                    </label>
                                ))}
                        </div>
                        {workspace.components.filter((component) => component.sessionId === selectedSession)
                            .length === 0 && (
                            <p className="text-text-muted text-sm">
                                No non-archived components exist for this session.
                            </p>
                        )}
                    </fieldset>
                    <div className="mt-5 flex flex-wrap items-end gap-3">
                        <button
                            className="border-border rounded-lg border px-4 py-2 font-medium focus-visible:outline-2"
                            type="button"
                            onClick={() => act(`/sessions/${selectedSession}/results/compile`)}
                        >
                            Compile candidate results
                        </button>
                        <p className="text-text-muted text-sm">
                            Compilation uses registered candidates, recorded attendance, graded written
                            attempts and approved practical/oral marks.
                        </p>
                    </div>
                    <ul className="mt-4 space-y-2 text-sm">
                        {workspace.policies.map((policy) => (
                            <li
                                key={`${policy.sessionId}-${policy.version}`}
                                className="bg-canvas rounded-lg p-3"
                            >
                                Session {policy.sessionId} · v{policy.version} · written{' '}
                                {policy.writtenWeight}% · pass {policy.passPercent ?? 'not configured'} ·
                                recheck {policy.recheckDays} days
                            </li>
                        ))}
                    </ul>
                </section>
                <section className="border-border bg-surface rounded-2xl border p-5">
                    <h2 className="text-lg font-semibold">Candidate result queue</h2>
                    {canExport && (
                        <a
                            className="border-border mt-3 inline-flex rounded-lg border px-3 py-2 text-sm font-medium focus-visible:outline-2"
                            href={`${root}/results.csv?session_id=${selectedSession}`}
                        >
                            Download scoped result register (CSV)
                        </a>
                    )}
                    {canExport && (
                        <a
                            className="border-border mt-3 ml-2 inline-flex rounded-lg border px-3 py-2 text-sm font-medium focus-visible:outline-2"
                            href={`${root}/operations.csv?session_id=${selectedSession}`}
                        >
                            Download selected session operations report (CSV)
                        </a>
                    )}
                    <div
                        className="mt-4 overflow-x-auto"
                        role="region"
                        aria-label="Candidate result table"
                        tabIndex={0}
                    >
                        <table className="w-full min-w-[680px] text-left text-sm">
                            <caption className="sr-only">Examination candidate results and actions</caption>
                            <thead>
                                <tr className="border-border text-text-muted border-b">
                                    <th className="p-2">Candidate</th>
                                    <th className="p-2">Session</th>
                                    <th className="p-2">Version</th>
                                    <th className="p-2">State</th>
                                    <th className="p-2">Marks</th>
                                    <th className="p-2">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {workspace.results.map((result) => (
                                    <tr key={result.id} className="border-border border-b">
                                        <td className="p-2">{result.candidateNumber}</td>
                                        <td className="p-2">{result.session}</td>
                                        <td className="p-2">v{result.version}</td>
                                        <td className="p-2">
                                            {result.status} · {result.outcome}
                                        </td>
                                        <td className="p-2">
                                            {result.marks ?? 'Pending'} / {result.maximum}
                                        </td>
                                        <td className="p-2">
                                            <div className="flex flex-wrap gap-2">
                                                {result.status === 'approval_pending' && (
                                                    <button
                                                        className="border-border rounded border px-2 py-1"
                                                        onClick={() =>
                                                            act(`/results/${result.id}/approve`, {
                                                                checklist: {
                                                                    scores_verified: '1',
                                                                    attendance_verified: '1',
                                                                    paper_version_verified: '1',
                                                                },
                                                                reason: 'Reviewed result compilation checklist.',
                                                            })
                                                        }
                                                    >
                                                        Approve
                                                    </button>
                                                )}
                                                {result.status === 'approved' && (
                                                    <button
                                                        className="border-border rounded border px-2 py-1"
                                                        onClick={() => act(`/results/${result.id}/release`)}
                                                    >
                                                        Release
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {workspace.results.length === 0 && (
                            <p className="text-text-muted py-6 text-center">
                                No examination results have been compiled.
                            </p>
                        )}
                    </div>
                </section>
                <section className="border-border bg-surface rounded-2xl border p-5">
                    <h2 className="text-lg font-semibold">Appeals & recheck history</h2>
                    <ul className="divide-border mt-3 divide-y">
                        {workspace.rechecks.map((item) => (
                            <li
                                key={item.id}
                                className="grid gap-3 py-4 lg:grid-cols-[1fr_auto]"
                                aria-label={`Recheck ${item.id} for candidate ${item.candidateNumber}`}
                            >
                                <div>
                                    <p className="font-medium">
                                        {item.type} · {item.status} · candidate {item.candidateNumber}
                                    </p>
                                    <p className="text-text-muted text-sm">{item.reason}</p>
                                    {item.type === 'appeal' && (
                                        <div className="mt-2 space-y-2 text-sm">
                                            <ul className="space-y-1">
                                                {item.evidence.map((evidence) => (
                                                    <li key={evidence.id}>
                                                        {evidence.name} · {evidence.scanStatus}
                                                        {evidence.scanStatus === 'clean' && (
                                                            <a
                                                                className="text-brand-link ml-2 underline"
                                                                href={`/t/${tenant.slug}/examinations/appeal-evidence/${evidence.id}/download`}
                                                            >
                                                                Download evidence
                                                            </a>
                                                        )}
                                                    </li>
                                                ))}
                                            </ul>
                                            {item.canUploadEvidence && (
                                                <form
                                                    className="flex flex-wrap items-end gap-2"
                                                    onSubmit={(event) => {
                                                        event.preventDefault();
                                                        const file = appealEvidence[item.id];
                                                        if (!file) return;
                                                        router.post(
                                                            `${root}/appeals/${item.id}/evidence`,
                                                            { evidence: file },
                                                            {
                                                                forceFormData: true,
                                                                onSuccess: () =>
                                                                    setFeedback(
                                                                        'Appeal evidence was quarantined for malware scanning.',
                                                                    ),
                                                                onError: () =>
                                                                    setFeedback(
                                                                        'Evidence could not be uploaded. Check its type and size.',
                                                                    ),
                                                            },
                                                        );
                                                    }}
                                                >
                                                    <label className="grid gap-1">
                                                        Add appeal evidence (PDF or image, up to 10 MB)
                                                        <input
                                                            type="file"
                                                            accept="application/pdf,image/jpeg,image/png"
                                                            onChange={(event) =>
                                                                setAppealEvidence({
                                                                    ...appealEvidence,
                                                                    [item.id]:
                                                                        event.target.files?.[0] ?? null,
                                                                })
                                                            }
                                                        />
                                                    </label>
                                                    <button
                                                        className="border-border rounded border px-3 py-2"
                                                        type="submit"
                                                    >
                                                        Upload to quarantine
                                                    </button>
                                                </form>
                                            )}
                                        </div>
                                    )}
                                    {item.type === 'appeal' && item.history.length > 0 && (
                                        <details className="mt-2 text-sm">
                                            <summary className="cursor-pointer font-medium">
                                                Appeal history ({item.history.length} events)
                                            </summary>
                                            <ol className="mt-2 list-inside list-decimal space-y-1">
                                                {item.history.map((event) => (
                                                    <li key={event.id}>
                                                        {event.action.replaceAll('_', ' ')}:{' '}
                                                        {event.from ?? 'new'} → {event.to} · result v
                                                        {event.resultVersion ?? '—'} ·{' '}
                                                        {new Date(event.at).toLocaleString()}
                                                    </li>
                                                ))}
                                            </ol>
                                        </details>
                                    )}
                                </div>
                                <div className="flex flex-wrap items-start gap-2">
                                    {['submitted', 'escalated', 'reopened'].includes(item.status) && (
                                        <select
                                            aria-label={`Reviewer for recheck ${item.id}`}
                                            className="border-border bg-canvas rounded border p-2"
                                            id={`reviewer-${item.id}`}
                                            defaultValue=""
                                        >
                                            <option value="" disabled>
                                                Assign reviewer
                                            </option>
                                            {workspace.reviewers.map((reviewer) => (
                                                <option key={reviewer.id} value={reviewer.id}>
                                                    {reviewer.name}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                    {['submitted', 'escalated', 'reopened'].includes(item.status) && (
                                        <button
                                            className="border-border rounded border px-3 py-2"
                                            onClick={() => {
                                                const el = document.getElementById(
                                                    `reviewer-${item.id}`,
                                                ) as HTMLSelectElement | null;
                                                if (el?.value)
                                                    act(`/rechecks/${item.id}/assign`, {
                                                        reviewer_id: Number(el.value),
                                                    });
                                            }}
                                        >
                                            Assign
                                        </button>
                                    )}
                                    {item.status === 'assigned' && (
                                        <button
                                            className="border-border rounded border px-3 py-2"
                                            onClick={() =>
                                                act(`/rechecks/${item.id}/decision`, {
                                                    decision: 'upheld',
                                                    findings:
                                                        'Reviewed source marks and found no calculation discrepancy.',
                                                })
                                            }
                                        >
                                            Record upheld
                                        </button>
                                    )}
                                    {item.status === 'approval_pending' && (
                                        <button
                                            className="border-border rounded border px-3 py-2"
                                            onClick={() =>
                                                act(`/rechecks/${item.id}/approve-amendment`, {
                                                    reason: 'Independent review of proposed amended score completed.',
                                                })
                                            }
                                        >
                                            Approve amendment
                                        </button>
                                    )}
                                    {item.type === 'appeal' && item.status === 'assigned' && (
                                        <button
                                            className="border-border rounded border px-3 py-2"
                                            onClick={() =>
                                                act(`/appeals/${item.id}/transition`, {
                                                    action: 'escalate',
                                                    reason: 'Escalated for an additional independent examination review.',
                                                })
                                            }
                                        >
                                            Escalate appeal
                                        </button>
                                    )}
                                    {item.type === 'appeal' &&
                                        item.status === 'decided' &&
                                        item.resultStatus === 'released' && (
                                            <button
                                                className="border-border rounded border px-3 py-2"
                                                onClick={() =>
                                                    act(`/appeals/${item.id}/transition`, {
                                                        action: 'close',
                                                        reason: 'The recorded decision and any approved remedy have been released.',
                                                    })
                                                }
                                            >
                                                Close appeal
                                            </button>
                                        )}
                                    {item.type === 'appeal' && item.status === 'closed' && (
                                        <button
                                            className="border-border rounded border px-3 py-2"
                                            onClick={() =>
                                                act(`/appeals/${item.id}/transition`, {
                                                    action: 'reopen',
                                                    reason: 'Reopened within the permitted review period for further examination.',
                                                })
                                            }
                                        >
                                            Reopen appeal
                                        </button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                    {workspace.rechecks.length === 0 && (
                        <p className="text-text-muted py-5">No open recheck requests.</p>
                    )}
                </section>
                <section className="border-border bg-surface rounded-2xl border p-5">
                    <h2 className="text-lg font-semibold">Incident register</h2>
                    {canReportIncidents && workspace.sessions.length > 0 && (
                        <form
                            className="border-border mt-4 grid gap-3 border-b pb-5 sm:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                const payload: Record<string, string | number> = { ...incident };
                                if (incident.candidate_id === '') delete payload.candidate_id;
                                else payload.candidate_id = Number(incident.candidate_id);
                                act('/incidents', payload);
                            }}
                        >
                            <label className="grid gap-1 text-sm font-medium">
                                Examination session
                                <select
                                    className="border-border bg-canvas rounded-lg border p-2"
                                    value={incident.session_id}
                                    onChange={(event) =>
                                        setIncident({ ...incident, session_id: Number(event.target.value) })
                                    }
                                >
                                    {workspace.sessions.map((session) => (
                                        <option key={session.id} value={session.id}>
                                            {session.title}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm font-medium">
                                Candidate (optional)
                                <select
                                    className="border-border bg-canvas rounded-lg border p-2"
                                    value={incident.candidate_id}
                                    onChange={(event) =>
                                        setIncident({ ...incident, candidate_id: event.target.value })
                                    }
                                >
                                    <option value="">Session-level incident</option>
                                    {workspace.candidates.map((candidate) => (
                                        <option key={candidate.id} value={candidate.id}>
                                            {candidate.number}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm font-medium">
                                Incident type
                                <select
                                    className="border-border bg-canvas rounded-lg border p-2"
                                    value={incident.incident_type}
                                    onChange={(event) =>
                                        setIncident({ ...incident, incident_type: event.target.value })
                                    }
                                >
                                    {[
                                        'technical',
                                        'attendance',
                                        'paper_access',
                                        'interruption',
                                        'complaint',
                                        'other',
                                    ].map((type) => (
                                        <option key={type} value={type}>
                                            {type.replaceAll('_', ' ')}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm font-medium">
                                Severity
                                <select
                                    className="border-border bg-canvas rounded-lg border p-2"
                                    value={incident.severity}
                                    onChange={(event) =>
                                        setIncident({ ...incident, severity: event.target.value })
                                    }
                                >
                                    <option value="standard">Standard</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm font-medium sm:col-span-2">
                                Factual incident description
                                <textarea
                                    required
                                    maxLength={5000}
                                    className="border-border bg-canvas min-h-24 rounded-lg border p-2"
                                    value={incident.description}
                                    onChange={(event) =>
                                        setIncident({ ...incident, description: event.target.value })
                                    }
                                />
                            </label>
                            <button className="bg-primary text-primary-foreground w-fit rounded-lg px-4 py-2 font-semibold">
                                Report examination incident
                            </button>
                        </form>
                    )}
                    <ul className="mt-3 space-y-2">
                        {workspace.incidents.map((incident) => (
                            <li className="bg-canvas rounded-lg p-3" key={incident.id}>
                                <p className="font-medium">
                                    {incident.severity} · {incident.type} · {incident.status}
                                </p>
                                <p className="text-text-muted text-sm">
                                    Assigned reviewer:{' '}
                                    {incident.reviewers.find(
                                        (reviewer) => reviewer.id === incident.assignedTo,
                                    )?.name ?? 'Unassigned'}
                                </p>
                                <p className="text-text-muted text-sm">{incident.description}</p>
                                <ul className="mt-2 space-y-1 text-sm" aria-label="Incident evidence">
                                    {incident.evidence.map((file) => (
                                        <li key={file.id}>
                                            {file.scanStatus === 'clean' ? (
                                                <a
                                                    className="text-primary underline"
                                                    href={`${root}/incident-evidence/${file.id}/download`}
                                                >
                                                    {file.name} (verified clean)
                                                </a>
                                            ) : (
                                                <span>
                                                    {file.name} · {file.scanStatus}; unavailable until scan
                                                    passes
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                                {(canReviewIncidents ||
                                    (canReportIncidents && incident.reportedBy === actorId)) && (
                                    <form
                                        className="mt-3 flex flex-wrap items-center gap-2"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            const file = incidentEvidence[incident.id];
                                            if (!file) return;
                                            router.post(
                                                `${root}/incidents/${incident.id}/evidence`,
                                                { evidence: file },
                                                {
                                                    forceFormData: true,
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        setIncidentEvidence({
                                                            ...incidentEvidence,
                                                            [incident.id]: null,
                                                        }),
                                                },
                                            );
                                        }}
                                    >
                                        <label className="text-sm">
                                            Attach PDF/image evidence
                                            <input
                                                className="ml-2 max-w-full"
                                                type="file"
                                                accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                                onChange={(event) =>
                                                    setIncidentEvidence({
                                                        ...incidentEvidence,
                                                        [incident.id]: event.target.files?.[0] ?? null,
                                                    })
                                                }
                                            />
                                        </label>
                                        <button
                                            className="border-border rounded-lg border px-3 py-2 text-sm"
                                            disabled={!incidentEvidence[incident.id]}
                                        >
                                            Upload to quarantine
                                        </button>
                                    </form>
                                )}
                                {incident.status === 'reported' && (
                                    <button
                                        className="border-border mt-2 rounded border px-3 py-1"
                                        onClick={() =>
                                            act(`/incidents/${incident.id}/review`, {
                                                decision: 'follow_up',
                                                notes: 'Recorded for follow-up review.',
                                            })
                                        }
                                    >
                                        Record follow-up
                                    </button>
                                )}
                                <ol
                                    className="border-border mt-3 space-y-1 border-l-2 pl-3"
                                    aria-label={`Incident ${incident.id} history`}
                                >
                                    {incident.history.map((entry) => (
                                        <li className="text-text-muted text-sm" key={entry.id}>
                                            <span className="font-medium">
                                                {entry.event.replaceAll('_', ' ')}
                                            </span>
                                            {entry.to ? ` · ${entry.from ?? 'new'} → ${entry.to}` : ''}
                                            {entry.notes ? ` · ${entry.notes}` : ''}
                                        </li>
                                    ))}
                                </ol>
                                {canReviewIncidents && (
                                    <form
                                        className="border-border mt-3 grid gap-3 rounded-lg border p-3 sm:grid-cols-2"
                                        aria-label={`Update incident ${incident.id}`}
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            const data = new FormData(event.currentTarget);
                                            const action = String(data.get('action'));
                                            const assignee = String(data.get('assignee_id') ?? '');
                                            act(`/incidents/${incident.id}/transition`, {
                                                action,
                                                notes: String(data.get('notes') ?? ''),
                                                ...(action === 'assign'
                                                    ? { assignee_id: Number(assignee) }
                                                    : {}),
                                            });
                                        }}
                                    >
                                        <label className="grid gap-1 text-sm font-medium">
                                            Incident action
                                            <select
                                                className="border-border bg-surface rounded-lg border p-2"
                                                name="action"
                                                defaultValue="investigate"
                                            >
                                                {incident.status === 'closed' ? (
                                                    <option value="reopen">Reopen investigation</option>
                                                ) : incident.status === 'resolved' ? (
                                                    <>
                                                        <option value="close">Close incident</option>
                                                        <option value="reopen">Reopen investigation</option>
                                                    </>
                                                ) : (
                                                    <>
                                                        <option value="investigate">
                                                            Begin investigation
                                                        </option>
                                                        <option value="assign">Assign reviewer</option>
                                                        <option value="escalate">Escalate</option>
                                                        <option value="resolve">Resolve</option>
                                                    </>
                                                )}
                                            </select>
                                        </label>
                                        <label className="grid gap-1 text-sm font-medium">
                                            Assign to reviewer
                                            <select
                                                className="border-border bg-surface rounded-lg border p-2"
                                                name="assignee_id"
                                                defaultValue={incident.assignedTo ?? ''}
                                            >
                                                <option value="">Choose when assigning</option>
                                                {incident.reviewers.map((reviewer) => (
                                                    <option key={reviewer.id} value={reviewer.id}>
                                                        {reviewer.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                        <label className="grid gap-1 text-sm font-medium sm:col-span-2">
                                            Reason / investigation note
                                            <textarea
                                                className="border-border bg-surface min-h-20 rounded-lg border p-2"
                                                name="notes"
                                                required
                                                maxLength={5000}
                                            />
                                        </label>
                                        <button className="bg-primary text-primary-foreground w-fit rounded-lg px-4 py-2 font-semibold sm:col-span-2">
                                            Save incident update
                                        </button>
                                    </form>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </WorkspaceLayout>
    );
}
