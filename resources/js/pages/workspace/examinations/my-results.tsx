import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Result = {
    id: number;
    session: string;
    version: number;
    status: string;
    outcome: string;
    marks: string;
    maximum: string;
    percentage: string;
    recheckUntil: string;
    releasedAt: string;
    components: { title: string; marks: string; maximum: string; weight: string }[];
    recheck: null | {
        id: number;
        status: string;
        decision: string | null;
        type: string;
        evidence: { id: number; name: string; scanStatus: string }[];
    };
    canRequestRecheck: boolean;
};
type Props = { tenant: { slug: string }; workspace: { released: Result[]; pendingCount: number } };

export default function MyExaminationResults({ tenant, workspace }: Props) {
    const [feedback, setFeedback] = useState('');
    const [reasons, setReasons] = useState<Record<number, string>>({});
    const [requestTypes, setRequestTypes] = useState<
        Record<number, 'appeal' | 'review' | 'clerical' | 'calculation'>
    >({});
    const [appealEvidence, setAppealEvidence] = useState<Record<number, File | null>>({});
    const root = `/t/${tenant.slug}/examinations/results`;
    return (
        <WorkspaceLayout title="My examination results" area="tenant">
            <Head title="My examination results" />
            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    title="My examination results"
                    description="Only released results are shown here. Scores remain hidden while marking or approvals are pending."
                />
                {feedback && (
                    <p role="status" aria-live="polite" className="text-sm">
                        {feedback}
                    </p>
                )}
                {workspace.pendingCount > 0 && (
                    <p className="border-border bg-surface rounded-xl border p-4" role="status">
                        {workspace.pendingCount} result{workspace.pendingCount === 1 ? ' is' : 's are'} still
                        being finalized. No unreleased scores are displayed.
                    </p>
                )}
                {workspace.released.length === 0 ? (
                    <section className="border-border bg-surface rounded-2xl border p-8 text-center">
                        <h2 className="text-lg font-semibold">No released results yet</h2>
                        <p className="text-text-muted mt-2">
                            Your institution will publish results here when review is complete.
                        </p>
                    </section>
                ) : (
                    <section className="grid gap-4">
                        {workspace.released.map((result) => (
                            <article
                                key={result.id}
                                className="border-border bg-surface rounded-2xl border p-5"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 className="text-lg font-semibold">{result.session}</h2>
                                        <p className="text-text-muted text-sm">
                                            Released {new Date(result.releasedAt).toLocaleString()} · Version{' '}
                                            {result.version}
                                        </p>
                                    </div>
                                    <p className="text-right">
                                        <span className="block text-2xl font-semibold">
                                            {result.marks} / {result.maximum}
                                        </span>
                                        <span className="text-text-muted text-sm">
                                            {result.percentage}% · {result.outcome}
                                        </span>
                                    </p>
                                </div>
                                <details className="mt-4">
                                    <summary className="cursor-pointer font-medium focus-visible:outline-2">
                                        Result components
                                    </summary>
                                    <ul className="divide-border mt-2 divide-y">
                                        {result.components.map((component, index) => (
                                            <li
                                                className="flex justify-between gap-3 py-2 text-sm"
                                                key={`${component.title}-${index}`}
                                            >
                                                <span>
                                                    {component.title} · weight {component.weight}%
                                                </span>
                                                <span>
                                                    {component.marks} / {component.maximum}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </details>
                                {result.recheck && (
                                    <div className="mt-3 space-y-2 text-sm">
                                        <p role="status">
                                            Recheck request: {result.recheck.status}
                                            {result.recheck.decision ? ` · ${result.recheck.decision}` : ''}
                                        </p>
                                        {result.recheck.type === 'appeal' && (
                                            <>
                                                <ul className="space-y-1">
                                                    {result.recheck.evidence.map((item) => (
                                                        <li key={item.id}>
                                                            {item.name} · {item.scanStatus}
                                                            {item.scanStatus === 'clean' && (
                                                                <a
                                                                    className="text-brand-link ml-2 underline"
                                                                    href={`/t/${tenant.slug}/examinations/appeal-evidence/${item.id}/download`}
                                                                >
                                                                    Download
                                                                </a>
                                                            )}
                                                        </li>
                                                    ))}
                                                </ul>
                                                {result.recheck.status !== 'closed' && (
                                                    <form
                                                        className="flex flex-wrap items-end gap-2"
                                                        onSubmit={(event) => {
                                                            event.preventDefault();
                                                            const file = appealEvidence[result.recheck!.id];
                                                            if (!file) return;
                                                            router.post(
                                                                `/t/${tenant.slug}/examinations/appeals/${result.recheck!.id}/evidence`,
                                                                { evidence: file },
                                                                {
                                                                    forceFormData: true,
                                                                    onSuccess: () =>
                                                                        setFeedback(
                                                                            'Evidence was quarantined for malware scanning.',
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
                                                            Appeal evidence (PDF or image, up to 10 MB)
                                                            <input
                                                                type="file"
                                                                accept="application/pdf,image/jpeg,image/png"
                                                                onChange={(event) =>
                                                                    setAppealEvidence({
                                                                        ...appealEvidence,
                                                                        [result.recheck!.id]:
                                                                            event.target.files?.[0] ?? null,
                                                                    })
                                                                }
                                                            />
                                                        </label>
                                                        <button
                                                            className="border-border rounded-lg border px-3 py-2 font-medium focus-visible:outline-2"
                                                            type="submit"
                                                        >
                                                            Upload to quarantine
                                                        </button>
                                                    </form>
                                                )}
                                            </>
                                        )}
                                    </div>
                                )}
                                {result.canRequestRecheck && (
                                    <form
                                        className="mt-4 grid gap-3 sm:grid-cols-[12rem_1fr_auto]"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            router.post(
                                                `${root}/${result.id}/rechecks`,
                                                {
                                                    request_type: requestTypes[result.id] ?? 'review',
                                                    reason: reasons[result.id] ?? '',
                                                },
                                                {
                                                    onSuccess: () =>
                                                        setFeedback(
                                                            (requestTypes[result.id] ?? 'review') === 'appeal'
                                                                ? 'Your examination appeal was submitted for independent review.'
                                                                : 'Your recheck request was submitted.',
                                                        ),
                                                    onError: () =>
                                                        setFeedback(
                                                            'Enter a reason before requesting a recheck.',
                                                        ),
                                                },
                                            );
                                        }}
                                    >
                                        <label className="grid gap-1 text-sm">
                                            Request type
                                            <select
                                                className="border-border bg-canvas rounded-lg border p-2"
                                                value={requestTypes[result.id] ?? 'review'}
                                                onChange={(event) =>
                                                    setRequestTypes({
                                                        ...requestTypes,
                                                        [result.id]: event.target.value as
                                                            'appeal' | 'review' | 'clerical' | 'calculation',
                                                    })
                                                }
                                            >
                                                <option value="appeal">Formal appeal</option>
                                                <option value="review">Review</option>
                                                <option value="clerical">Clerical</option>
                                                <option value="calculation">Calculation</option>
                                            </select>
                                        </label>
                                        <label className="grid gap-1 text-sm">
                                            Reason
                                            <textarea
                                                className="border-border bg-canvas min-h-10 rounded-lg border p-2"
                                                maxLength={4000}
                                                value={reasons[result.id] ?? ''}
                                                onChange={(event) =>
                                                    setReasons({
                                                        ...reasons,
                                                        [result.id]: event.target.value,
                                                    })
                                                }
                                                required
                                            />
                                        </label>
                                        <button
                                            className="bg-brand-action hover:bg-brand-action-hover self-end rounded-lg px-4 py-2 font-semibold text-white focus-visible:outline-2"
                                            type="submit"
                                        >
                                            Request recheck
                                        </button>
                                    </form>
                                )}
                            </article>
                        ))}
                    </section>
                )}
            </div>
        </WorkspaceLayout>
    );
}
