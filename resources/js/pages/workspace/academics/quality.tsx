import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Review = {
    id: number;
    review_number: number;
    due_on: string | null;
    measured_value: string | null;
    summary: string | null;
    decision: string;
    reviewed_by: number | null;
    approved_at: string | null;
};
type Evidence = {
    id: number;
    title: string;
    private_file_path: string | null;
    reference_url: string | null;
    note: string | null;
};
type Action = {
    id: number;
    code: string;
    title: string;
    description: string;
    status: string;
    due_on: string | null;
    resolution_note: string | null;
};
type Objective = {
    id: number;
    code: string;
    name: string;
    description: string;
    quality_area: string | null;
    metric_definition: string;
    metric_unit: string | null;
    target_value: string | null;
    review_every_months: number;
    next_review_on: string | null;
    status: string;
    academic_unit: { name: string } | null;
    reviews: Review[];
    corrective_actions: Action[];
    evidence: Evidence[];
    reviews_count: number;
    corrective_actions_count: number;
    evidence_count: number;
};
type Document = {
    id: number;
    document_type: string;
    code: string;
    version_number: number;
    title: string;
    summary: string | null;
    file_size: number;
    status: string;
    effective_from: string | null;
    effective_until: string | null;
    approvals: { id: number; decision: string; comment: string | null; occurred_at: string }[];
};
type Props = {
    tenant: { slug: string };
    objectives: { data: Objective[] };
    documents: { data: Document[] };
    units: { id: number; name: string }[];
    members: { id: number; name: string }[];
};
type AuthProps = { auth: { user: { id: number } | null } };

function ObjectiveCard({ objective, slug }: { objective: Objective; slug: string }) {
    const root = `/t/${slug}/academics`;
    const reviewForm = useForm({ due_on: '', measured_value: '', summary: '', decision: 'met' });
    const evidenceForm = useForm({ title: '', reference_url: '', note: '', file: null as File | null });
    const actionForm = useForm({ code: '', title: '', description: '', assigned_to: '', due_on: '' });
    return (
        <article className="border-border bg-surface rounded-2xl border p-5 sm:p-7">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="text-lg font-semibold">
                        {objective.name}
                        <span className="text-text-muted ml-2 text-sm">{objective.code}</span>
                    </h3>
                    <p className="text-text-secondary mt-1 text-sm">
                        {objective.quality_area ?? 'General quality'} ·{' '}
                        {objective.academic_unit?.name ?? 'Institution-wide'} · {objective.status}
                    </p>
                </div>
                {objective.status === 'draft' && (
                    <button
                        type="button"
                        onClick={() => router.post(`${root}/quality/objectives/${objective.id}/activate`)}
                        className="border-border rounded-lg border px-3 py-2 text-sm font-semibold"
                    >
                        Activate
                    </button>
                )}
            </div>
            <p className="mt-4 text-sm">{objective.description}</p>
            <dl className="mt-4 grid gap-3 sm:grid-cols-3">
                <div className="bg-canvas rounded-lg p-3">
                    <dt className="text-text-muted text-xs">Measure</dt>
                    <dd className="mt-1 text-sm">
                        {objective.metric_definition}
                        {objective.metric_unit ? ` (${objective.metric_unit})` : ''}
                    </dd>
                </div>
                <div className="bg-canvas rounded-lg p-3">
                    <dt className="text-text-muted text-xs">Target</dt>
                    <dd className="mt-1 text-sm">{objective.target_value ?? 'Not set'}</dd>
                </div>
                <div className="bg-canvas rounded-lg p-3">
                    <dt className="text-text-muted text-xs">Review cycle</dt>
                    <dd className="mt-1 text-sm">
                        Every {objective.review_every_months} months
                        {objective.next_review_on ? ` · next ${objective.next_review_on}` : ''}
                    </dd>
                </div>
            </dl>
            {objective.status === 'active' && (
                <div className="mt-5 grid gap-4 xl:grid-cols-3">
                    <section className="border-border rounded-xl border p-4">
                        <h4 className="font-semibold">Record a quality review</h4>
                        <p className="text-text-secondary mt-1 text-xs">
                            A different authorized reviewer must approve this record.
                        </p>
                        <form
                            className="mt-3 grid gap-3"
                            onSubmit={(event: FormEvent) => {
                                event.preventDefault();
                                reviewForm.post(`${root}/quality/objectives/${objective.id}/reviews`, {
                                    onSuccess: () => reviewForm.reset(),
                                });
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Decision
                                <select
                                    className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                    value={reviewForm.data.decision}
                                    onChange={(e) => reviewForm.setData('decision', e.currentTarget.value)}
                                >
                                    {['met', 'partially_met', 'not_met', 'not_applicable'].map((value) => (
                                        <option key={value} value={value}>
                                            {value.replaceAll('_', ' ')}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Measured value
                                <input
                                    type="number"
                                    step="0.0001"
                                    className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                    value={reviewForm.data.measured_value}
                                    onChange={(e) =>
                                        reviewForm.setData('measured_value', e.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Summary
                                <textarea
                                    required
                                    className="border-border bg-surface min-h-20 rounded-lg border px-3 py-2"
                                    value={reviewForm.data.summary}
                                    onChange={(e) => reviewForm.setData('summary', e.currentTarget.value)}
                                />
                            </label>
                            <button
                                disabled={reviewForm.processing}
                                className="bg-brand-action focus-ring min-h-10 rounded-lg px-4 text-sm font-semibold text-white"
                            >
                                Submit review
                            </button>
                            {Object.keys(reviewForm.errors).length > 0 && <FormErrors />}
                        </form>
                    </section>
                    <section className="border-border rounded-xl border p-4">
                        <h4 className="font-semibold">Attach evidence</h4>
                        <form
                            className="mt-3 grid gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                evidenceForm.post(`${root}/quality/objectives/${objective.id}/evidence`, {
                                    forceFormData: true,
                                    onSuccess: () => evidenceForm.reset(),
                                });
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Evidence title
                                <input
                                    required
                                    className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                    value={evidenceForm.data.title}
                                    onChange={(e) => evidenceForm.setData('title', e.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Reference URL (HTTPS)
                                <input
                                    type="url"
                                    className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                    value={evidenceForm.data.reference_url}
                                    onChange={(e) =>
                                        evidenceForm.setData('reference_url', e.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Private file
                                <input
                                    type="file"
                                    accept=".pdf,.docx,.xlsx,.png,.jpg,.jpeg"
                                    className="border-border bg-surface min-h-10 rounded-lg border px-3 py-2"
                                    onChange={(e) =>
                                        evidenceForm.setData('file', e.currentTarget.files?.[0] ?? null)
                                    }
                                />
                                <span className="text-text-muted text-xs">Maximum 20 MB</span>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Note
                                <textarea
                                    className="border-border bg-surface min-h-16 rounded-lg border px-3 py-2"
                                    value={evidenceForm.data.note}
                                    onChange={(e) => evidenceForm.setData('note', e.currentTarget.value)}
                                />
                            </label>
                            <button
                                disabled={evidenceForm.processing}
                                className="border-border min-h-10 rounded-lg border px-4 text-sm font-semibold"
                            >
                                Save evidence
                            </button>
                            {Object.keys(evidenceForm.errors).length > 0 && <FormErrors />}
                        </form>
                        <ul className="mt-3 grid gap-2">
                            {objective.evidence.map((item) => (
                                <li key={item.id} className="bg-canvas rounded-lg p-3 text-sm">
                                    <strong>{item.title}</strong>
                                    {item.private_file_path && (
                                        <a
                                            className="text-brand-link ml-3 font-semibold"
                                            href={`${root}/quality/evidence/${item.id}/download`}
                                        >
                                            Download
                                        </a>
                                    )}
                                    {item.reference_url && (
                                        <a
                                            className="text-brand-link ml-3 font-semibold"
                                            href={item.reference_url}
                                            rel="noreferrer"
                                            target="_blank"
                                        >
                                            Source
                                        </a>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                    <section className="border-border rounded-xl border p-4">
                        <h4 className="font-semibold">Corrective actions</h4>
                        <form
                            className="mt-3 grid gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                actionForm.post(`${root}/quality/objectives/${objective.id}/actions`, {
                                    onSuccess: () => actionForm.reset(),
                                });
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Action code
                                <input
                                    required
                                    className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                    value={actionForm.data.code}
                                    onChange={(e) => actionForm.setData('code', e.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Title
                                <input
                                    required
                                    className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                    value={actionForm.data.title}
                                    onChange={(e) => actionForm.setData('title', e.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Description
                                <textarea
                                    required
                                    className="border-border bg-surface min-h-16 rounded-lg border px-3 py-2"
                                    value={actionForm.data.description}
                                    onChange={(e) => actionForm.setData('description', e.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Due date
                                <input
                                    type="date"
                                    className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                    value={actionForm.data.due_on}
                                    onChange={(e) => actionForm.setData('due_on', e.currentTarget.value)}
                                />
                            </label>
                            <button
                                disabled={actionForm.processing}
                                className="border-border min-h-10 rounded-lg border px-4 text-sm font-semibold"
                            >
                                Create action
                            </button>
                            {Object.keys(actionForm.errors).length > 0 && <FormErrors />}
                        </form>
                        <ul className="mt-3 grid gap-2">
                            {objective.corrective_actions.map((action) => (
                                <li key={action.id} className="bg-canvas rounded-lg p-3 text-sm">
                                    <strong>
                                        {action.code} · {action.title}
                                    </strong>
                                    <p className="text-text-secondary mt-1">
                                        {action.status} · {action.description}
                                    </p>
                                    {action.status === 'open' && (
                                        <button
                                            type="button"
                                            className="text-brand-link mt-2 font-semibold"
                                            onClick={() =>
                                                router.patch(`${root}/quality/actions/${action.id}`, {
                                                    status: 'in_progress',
                                                })
                                            }
                                        >
                                            Start
                                        </button>
                                    )}
                                    {['open', 'in_progress'].includes(action.status) && (
                                        <form
                                            className="mt-2 flex gap-2"
                                            onSubmit={(event) => {
                                                event.preventDefault();
                                                const note = new FormData(event.currentTarget).get('note');
                                                router.post(`${root}/quality/actions/${action.id}/close`, {
                                                    resolution_note: String(note ?? ''),
                                                });
                                            }}
                                        >
                                            <label className="sr-only" htmlFor={`action-close-${action.id}`}>
                                                Resolution note
                                            </label>
                                            <input
                                                id={`action-close-${action.id}`}
                                                name="note"
                                                required
                                                className="border-border bg-surface min-h-9 min-w-0 flex-1 rounded-lg border px-3"
                                                placeholder="Resolution note"
                                            />
                                            <button className="border-border min-h-9 rounded-lg border px-3">
                                                Close
                                            </button>
                                        </form>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>
            )}
            <section className="border-border mt-5 border-t pt-4">
                <h4 className="font-semibold">Review history</h4>
                {objective.reviews.length ? (
                    <ul className="mt-2 grid gap-2">
                        {objective.reviews.map((review) => (
                            <ReviewRow key={review.id} review={review} slug={slug} />
                        ))}
                    </ul>
                ) : (
                    <p className="text-text-secondary mt-2 text-sm">No reviews recorded.</p>
                )}
            </section>
        </article>
    );
}

function ReviewRow({ review, slug }: { review: Review; slug: string }) {
    const userId = usePage<AuthProps>().props.auth.user?.id;
    const [note, setNote] = useState('');
    return (
        <li className="bg-canvas rounded-lg p-3 text-sm">
            <div className="flex flex-wrap justify-between gap-2">
                <span>
                    <strong>Review {review.review_number}</strong> ·{' '}
                    <span className="capitalize">{review.decision.replaceAll('_', ' ')}</span>
                    {review.measured_value ? ` · measured ${review.measured_value}` : ''}
                </span>
                <span>{review.approved_at ? 'Approved' : 'Awaiting approval'}</span>
            </div>
            {review.summary && <p className="text-text-secondary mt-1">{review.summary}</p>}
            {!review.approved_at && review.reviewed_by !== userId && (
                <form
                    className="mt-2 flex flex-wrap gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.post(`/t/${slug}/quality/reviews/${review.id}/approve`, {
                            approval_note: note,
                        });
                    }}
                >
                    <label className="sr-only" htmlFor={`review-note-${review.id}`}>
                        Approval note
                    </label>
                    <input
                        id={`review-note-${review.id}`}
                        className="border-border bg-surface min-h-9 min-w-48 flex-1 rounded-lg border px-3"
                        value={note}
                        onChange={(e) => setNote(e.currentTarget.value)}
                        placeholder="Approval note"
                    />
                    <button className="border-border min-h-9 rounded-lg border px-3">Approve</button>
                </form>
            )}
        </li>
    );
}

export default function AcademicQualityPage({ tenant, objectives, documents, units }: Props) {
    const root = `/t/${tenant.slug}/academics`;
    const objectiveForm = useForm({
        code: '',
        name: '',
        description: '',
        quality_area: '',
        metric_definition: '',
        metric_unit: '',
        target_value: '',
        review_every_months: 12,
        next_review_on: '',
        academic_unit_id: '',
        owner_user_id: '',
    });
    const documentForm = useForm({
        document_type: 'policy',
        code: '',
        title: '',
        summary: '',
        effective_from: '',
        effective_until: '',
        file: null as File | null,
    });
    return (
        <WorkspaceLayout area="tenant" title="Quality & documents">
            <PageHeader
                title="Educational quality & controlled documents"
                description="Define measurable institutional objectives, retain review evidence, track corrective action, and control academic document versions."
            />
            <div className="mt-5 flex gap-3 text-sm">
                <a href={root} className="text-brand-link font-semibold">
                    Overview
                </a>
                <a href={`${root}/policies`} className="text-brand-link font-semibold">
                    Policy profiles
                </a>
            </div>
            <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                <h2 className="text-lg font-semibold">Create quality objective</h2>
                <form
                    className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        objectiveForm.post(`${root}/quality/objectives`, {
                            onSuccess: () => objectiveForm.reset(),
                        });
                    }}
                >
                    <label className="grid gap-1 text-sm">
                        Objective code
                        <input
                            required
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={objectiveForm.data.code}
                            onChange={(e) => objectiveForm.setData('code', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Name
                        <input
                            required
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={objectiveForm.data.name}
                            onChange={(e) => objectiveForm.setData('name', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Quality area
                        <input
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={objectiveForm.data.quality_area}
                            onChange={(e) => objectiveForm.setData('quality_area', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Academic unit
                        <select
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={objectiveForm.data.academic_unit_id}
                            onChange={(e) => objectiveForm.setData('academic_unit_id', e.currentTarget.value)}
                        >
                            <option value="">Institution-wide</option>
                            {units.map((unit) => (
                                <option key={unit.id} value={unit.id}>
                                    {unit.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm sm:col-span-2">
                        Description
                        <textarea
                            required
                            className="border-border bg-surface min-h-16 rounded-lg border px-3 py-2"
                            value={objectiveForm.data.description}
                            onChange={(e) => objectiveForm.setData('description', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm sm:col-span-2">
                        Metric definition
                        <textarea
                            required
                            className="border-border bg-surface min-h-16 rounded-lg border px-3 py-2"
                            value={objectiveForm.data.metric_definition}
                            onChange={(e) =>
                                objectiveForm.setData('metric_definition', e.currentTarget.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Metric unit
                        <input
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={objectiveForm.data.metric_unit}
                            onChange={(e) => objectiveForm.setData('metric_unit', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Target value
                        <input
                            type="number"
                            step="0.0001"
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={objectiveForm.data.target_value}
                            onChange={(e) => objectiveForm.setData('target_value', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Review every (months)
                        <input
                            required
                            type="number"
                            min={1}
                            max={120}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={objectiveForm.data.review_every_months}
                            onChange={(e) =>
                                objectiveForm.setData('review_every_months', Number(e.currentTarget.value))
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Next review date
                        <input
                            type="date"
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={objectiveForm.data.next_review_on}
                            onChange={(e) => objectiveForm.setData('next_review_on', e.currentTarget.value)}
                        />
                    </label>
                    <div className="sm:col-span-2 xl:col-span-4">
                        <button
                            disabled={objectiveForm.processing}
                            className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                        >
                            Create draft objective
                        </button>
                    </div>
                    {Object.keys(objectiveForm.errors).length > 0 && (
                        <div className="sm:col-span-2 xl:col-span-4">
                            <FormErrors />
                        </div>
                    )}
                </form>
            </section>
            <section className="mt-6 grid gap-4">
                {objectives.data.map((objective) => (
                    <ObjectiveCard key={objective.id} objective={objective} slug={tenant.slug} />
                ))}
                {objectives.data.length === 0 && (
                    <p className="border-border bg-surface text-text-secondary rounded-2xl border p-6">
                        No quality objectives recorded.
                    </p>
                )}
            </section>
            <section className="border-border bg-surface mt-7 rounded-2xl border p-5 sm:p-7">
                <h2 className="text-lg font-semibold">Controlled academic documents</h2>
                <p className="text-text-secondary mt-1 text-sm">
                    Files remain in private storage. Approval requires a second authorized member; approved
                    documents are immutable and retained by version.
                </p>
                <form
                    className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        documentForm.post(`${root}/documents`, {
                            forceFormData: true,
                            onSuccess: () => documentForm.reset(),
                        });
                    }}
                >
                    <label className="grid gap-1 text-sm">
                        Document type
                        <input
                            required
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={documentForm.data.document_type}
                            onChange={(e) => documentForm.setData('document_type', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Document code
                        <input
                            required
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={documentForm.data.code}
                            onChange={(e) => documentForm.setData('code', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Title
                        <input
                            required
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={documentForm.data.title}
                            onChange={(e) => documentForm.setData('title', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        File (PDF, DOCX, XLSX)
                        <input
                            required
                            type="file"
                            accept=".pdf,.docx,.xlsx"
                            className="border-border bg-surface min-h-11 rounded-lg border px-3 py-2"
                            onChange={(e) => documentForm.setData('file', e.currentTarget.files?.[0] ?? null)}
                        />
                        <span className="text-text-muted text-xs">Maximum 30 MB</span>
                    </label>
                    <label className="grid gap-1 text-sm sm:col-span-2 xl:col-span-4">
                        Summary
                        <textarea
                            className="border-border bg-surface min-h-16 rounded-lg border px-3 py-2"
                            value={documentForm.data.summary}
                            onChange={(e) => documentForm.setData('summary', e.currentTarget.value)}
                        />
                    </label>
                    <div className="sm:col-span-2 xl:col-span-4">
                        <button
                            disabled={documentForm.processing}
                            className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                        >
                            Upload private draft
                        </button>
                    </div>
                    {Object.keys(documentForm.errors).length > 0 && (
                        <div className="sm:col-span-2 xl:col-span-4">
                            <FormErrors />
                        </div>
                    )}
                </form>
                <div className="mt-5 grid gap-3">
                    {documents.data.map((document) => (
                        <article key={document.id} className="border-border rounded-xl border p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 className="font-semibold">
                                        {document.title}
                                        <span className="text-text-muted ml-2 text-sm">
                                            {document.code} · v{document.version_number}
                                        </span>
                                    </h3>
                                    <p className="text-text-secondary mt-1 text-sm capitalize">
                                        {document.document_type.replaceAll('_', ' ')} · {document.status} ·{' '}
                                        {Math.ceil(document.file_size / 1024)} KB
                                    </p>
                                    {document.summary && <p className="mt-2 text-sm">{document.summary}</p>}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {document.status === 'approved' && (
                                        <a
                                            className="text-brand-link rounded-lg border px-3 py-2 text-sm font-semibold"
                                            href={`${root}/documents/${document.id}/download`}
                                        >
                                            Download
                                        </a>
                                    )}
                                    {document.status === 'draft' && (
                                        <button
                                            type="button"
                                            className="border-border rounded-lg border px-3 py-2 text-sm font-semibold"
                                            onClick={() =>
                                                router.post(`${root}/documents/${document.id}/submit`)
                                            }
                                        >
                                            Submit for approval
                                        </button>
                                    )}
                                    {document.status === 'in_review' && (
                                        <>
                                            <button
                                                type="button"
                                                className="text-brand-link rounded-lg border px-3 py-2 text-sm font-semibold"
                                                onClick={() =>
                                                    router.post(`${root}/documents/${document.id}/decision`, {
                                                        decision: 'approved',
                                                    })
                                                }
                                            >
                                                Approve
                                            </button>
                                            <button
                                                type="button"
                                                className="rounded-lg border border-rose-300 px-3 py-2 text-sm font-semibold text-rose-800"
                                                onClick={() =>
                                                    router.post(`${root}/documents/${document.id}/decision`, {
                                                        decision: 'rejected',
                                                    })
                                                }
                                            >
                                                Reject
                                            </button>
                                        </>
                                    )}
                                </div>
                            </div>
                            {document.approvals.length > 0 && (
                                <ol className="border-border mt-3 border-t pt-3 text-xs">
                                    {document.approvals.map((approval) => (
                                        <li key={approval.id} className="text-text-secondary py-1">
                                            {approval.occurred_at} · {approval.decision}
                                            {approval.comment ? ` · ${approval.comment}` : ''}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </article>
                    ))}
                    {documents.data.length === 0 && (
                        <p className="text-text-secondary text-sm">No controlled documents recorded.</p>
                    )}
                </div>
            </section>
        </WorkspaceLayout>
    );
}
