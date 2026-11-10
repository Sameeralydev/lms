import { Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormDataConvertible } from '@inertiajs/core';
import type { FormEvent } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type ReviewDocument = {
    id: number;
    name: string;
    status: string;
    scanStatus: string;
    reviewNote: string | null;
    version: number;
    downloadUrl: string | null;
};
type Row = Record<string, unknown>;
type DecisionRow = Row & {
    id: number;
    decision: string;
    recommended_by: number | null;
    approved_by: number | null;
    approved_at: string | null;
    applicant_reason: string | null;
};
type GuardianRow = {
    id: number;
    name: string;
    email: string;
    relationship: string;
    status: string;
    verificationMethod: string | null;
};
type Props = {
    tenant: { slug: string };
    application: {
        id: number;
        number: string;
        status: string;
        eligibility: string;
        submittedAt: string | null;
        intake: { id: number; name: string; code: string };
        profile: Record<string, string | null>;
        answers: Record<string, unknown>;
        form: {
            sections?: { key: string; title: string; fields: { key: string; label: string }[] }[];
        } | null;
    };
    documents: ReviewDocument[];
    guardians: GuardianRow[];
    events: Row[];
    assessments: Row[];
    decisions: DecisionRow[];
    offers: Row[];
    fee: {
        id: number;
        amount: string;
        currency: string;
        status: string;
        payment_reference: string | null;
        waiver_reason: string | null;
    } | null;
    handoffRecord: { correlation_id: string; status: string; created_at: string } | null;
    reviewers: { id: number; name: string; campusId: number | null }[];
};

function DocumentReview({
    slug,
    applicationId,
    document,
}: {
    slug: string;
    applicationId: number;
    document: ReviewDocument;
}) {
    const form = useForm({ decision: 'accepted', note: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/t/${slug}/admissions/applications/${applicationId}/documents/${document.id}/review`, {
            preserveScroll: true,
            onSuccess: () => form.reset('note'),
        });
    };
    return (
        <article className="border-border grid gap-4 rounded-xl border p-4 lg:grid-cols-[1fr_18rem]">
            <div>
                <h3 className="font-semibold">{document.name}</h3>
                <p className="text-text-muted mt-1 text-sm">
                    Version {document.version} ·{' '}
                    <span className="capitalize">{document.status.replaceAll('_', ' ')}</span>
                </p>
                {document.reviewNote ? (
                    <p className="text-text-secondary mt-2 text-sm">Reviewer note: {document.reviewNote}</p>
                ) : null}
                <p className="text-text-muted mt-1 text-xs">
                    File scan: {document.scanStatus.replaceAll('_', ' ')}.
                </p>
                {document.downloadUrl ? (
                    <a
                        className="text-brand-link mt-3 inline-block text-sm font-semibold"
                        href={document.downloadUrl}
                    >
                        Download privately
                    </a>
                ) : (
                    <p className="text-warning mt-2 text-sm">
                        Private quarantine remains in effect until a configured scanner marks this file clean.
                    </p>
                )}
            </div>
            {document.scanStatus === 'clean' ? (
                <form onSubmit={submit} className="grid gap-2">
                    <label className="grid gap-1 text-xs font-medium">
                        Review result
                        <select
                            className="border-border bg-surface min-h-10 rounded-lg border px-2 text-sm"
                            value={form.data.decision}
                            onChange={(event) => form.setData('decision', event.target.value)}
                        >
                            <option value="accepted">Accepted</option>
                            <option value="rejected">Rejected</option>
                            <option value="resubmission_required">Request resubmission</option>
                        </select>
                    </label>
                    <label className="grid gap-1 text-xs font-medium">
                        Note
                        <textarea
                            className="border-border min-h-16 rounded-lg border p-2 text-sm"
                            maxLength={1000}
                            value={form.data.note}
                            onChange={(event) => form.setData('note', event.target.value)}
                        />
                    </label>
                    <button className="border-border min-h-10 rounded-lg border px-3 text-sm font-semibold">
                        Record review
                    </button>
                </form>
            ) : null}
        </article>
    );
}

function DataList({ title, rows }: { title: string; rows: Row[] }) {
    return (
        <section className="border-border bg-surface rounded-2xl border p-5">
            <h2 className="text-lg font-semibold">{title}</h2>
            {rows.length ? (
                <ul className="mt-3 grid gap-2">
                    {rows.map((row, index) => (
                        <li key={index} className="bg-canvas rounded-lg p-3 text-sm">
                            {Object.entries(row)
                                .filter(
                                    ([key]) =>
                                        !['id', 'tenant_id', 'application_id', 'updated_at'].includes(key),
                                )
                                .map(([key, value]) => (
                                    <span key={key} className="mr-3 inline-block">
                                        <span className="text-text-muted">{key.replaceAll('_', ' ')}: </span>
                                        {typeof value === 'object' && value !== null
                                            ? JSON.stringify(value)
                                            : String(value ?? '—')}
                                    </span>
                                ))}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-text-secondary mt-3 text-sm">No records yet.</p>
            )}
        </section>
    );
}

export default function AdmissionsApplication({
    tenant,
    application,
    documents,
    guardians,
    events,
    assessments,
    decisions,
    offers,
    fee,
    handoffRecord,
    reviewers,
}: Props) {
    const hasActiveGuardian = guardians.some((guardian) => guardian.status === 'active');
    const page = usePage<{ auth: { user: { id: number }; tenant: { permissions: string[] } } }>();
    const permissions = page.props.auth.tenant.permissions;
    const can = (permission: string) => permissions.includes(permission);
    const statusForm = useForm({ status: 'under_review', reason: '' });
    const assignForm = useForm({ reviewer_user_id: '', assignment_type: 'review', priority: 3, due_at: '' });
    const eligibilityForm = useForm({ human_review_note: '' });
    const assessmentForm = useForm({
        assessment_type: 'interview',
        assessor_user_id: '',
        scheduled_at: '',
        score: '',
        maximum_score: '',
        private_notes: '',
    });
    const decisionForm = useForm({ decision: 'admit', applicant_reason: '', private_reason: '' });
    const offerForm = useForm({ conditions: '', accept_by: '' });
    const feeForm = useForm({ status: 'paid', payment_reference: '', waiver_reason: '' });
    const submit = (event: FormEvent, endpoint: string, data: Record<string, FormDataConvertible>) => {
        event.preventDefault();
        router.post(`/t/${tenant.slug}/admissions/applications/${application.id}/${endpoint}`, data, {
            preserveScroll: true,
        });
    };
    const queueHandoff = () =>
        window.confirm(
            'Create a pending SIS handoff snapshot? No student record is created in this phase.',
        ) &&
        router.post(
            `/t/${tenant.slug}/admissions/applications/${application.id}/handoff`,
            {},
            { preserveScroll: true },
        );
    const answerFields =
        application.form?.sections?.flatMap((section) =>
            section.fields.map((field) => ({ ...field, section: section.title })),
        ) ?? [];
    return (
        <WorkspaceLayout area="tenant" title={`Application ${application.number}`}>
            <PageHeader
                title={`Application ${application.number}`}
                description={`${application.intake.name} · ${application.status.replaceAll('_', ' ')}${application.submittedAt ? ` · Submitted ${new Date(application.submittedAt).toLocaleString()}` : ' · Draft'}`}
                actions={
                    <Link
                        className="border-border inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                        href={`/t/${tenant.slug}/admissions`}
                    >
                        Back to admissions
                    </Link>
                }
            />
            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="grid content-start gap-6">
                    <section className="border-border bg-surface rounded-2xl border p-5 sm:p-7">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="text-text-muted text-xs">Applicant profile</p>
                                <h2 className="mt-1 text-xl font-semibold">
                                    {[
                                        application.profile.given_name,
                                        application.profile.middle_name,
                                        application.profile.family_name,
                                    ]
                                        .filter(Boolean)
                                        .join(' ') || 'Applicant'}
                                </h2>
                                <p className="text-text-secondary mt-2 text-sm">
                                    {[
                                        application.profile.city,
                                        application.profile.region,
                                        application.profile.country,
                                    ]
                                        .filter(Boolean)
                                        .join(', ') || 'No location supplied'}{' '}
                                    · {application.profile.phone ?? 'No phone supplied'}
                                </p>
                            </div>
                            <span className="bg-surface-soft rounded-full px-3 py-1 text-xs font-semibold capitalize">
                                {application.status.replaceAll('_', ' ')}
                            </span>
                        </div>
                        <dl className="mt-5 grid gap-4 sm:grid-cols-2">
                            {answerFields.map((field) => (
                                <div key={field.key} className="border-border border-b pb-3">
                                    <dt className="text-text-muted text-xs">
                                        {field.label} · {field.section}
                                    </dt>
                                    <dd className="mt-1 text-sm whitespace-pre-wrap">
                                        {Array.isArray(application.answers[field.key])
                                            ? JSON.stringify(application.answers[field.key])
                                            : typeof application.answers[field.key] === 'object'
                                              ? JSON.stringify(application.answers[field.key])
                                              : String(application.answers[field.key] ?? '—')}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </section>
                    {guardians.length ? (
                        <section className="border-border bg-surface rounded-2xl border p-5 sm:p-7">
                            <h2 className="text-lg font-semibold">Guardian authorization</h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                {hasActiveGuardian
                                    ? 'Relationship verification is complete. This guardian can view the application until access is revoked.'
                                    : 'Email verification confirms account control. Application access remains inactive until an authorized institution administrator records relationship verification.'}
                            </p>
                            <ul className="mt-4 grid gap-3">
                                {guardians.map((guardian) => (
                                    <li
                                        key={guardian.id}
                                        className="border-border grid gap-3 rounded-xl border p-4 sm:grid-cols-[1fr_auto] sm:items-center"
                                    >
                                        <div>
                                            <p className="font-medium">
                                                {guardian.name} · {guardian.relationship.replaceAll('_', ' ')}
                                            </p>
                                            <p className="text-text-muted mt-1 text-sm">
                                                {guardian.email} · {guardian.status.replaceAll('_', ' ')}
                                            </p>
                                            {guardian.verificationMethod ? (
                                                <p className="text-text-muted mt-1 text-xs">
                                                    Verification method:{' '}
                                                    {guardian.verificationMethod.replaceAll('_', ' ')}
                                                </p>
                                            ) : null}
                                        </div>
                                        {guardian.status === 'guardian_verified' &&
                                        can('admissions.guardians.verify') ? (
                                            <form
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    const form = new FormData(event.currentTarget);
                                                    router.post(
                                                        `/t/${tenant.slug}/admissions/applications/${application.id}/guardians/${guardian.id}/verify`,
                                                        {
                                                            verification_method: String(
                                                                form.get('verification_method') ?? '',
                                                            ),
                                                        },
                                                        { preserveScroll: true },
                                                    );
                                                }}
                                                className="flex flex-wrap items-end gap-2"
                                            >
                                                <label className="grid gap-1 text-xs font-medium">
                                                    Verification method
                                                    <select
                                                        name="verification_method"
                                                        required
                                                        className="border-border bg-surface min-h-10 rounded-lg border px-2 text-sm"
                                                    >
                                                        <option value="in_person">In person</option>
                                                        <option value="document_review">
                                                            Document review
                                                        </option>
                                                        <option value="approved_provider">
                                                            Approved provider
                                                        </option>
                                                    </select>
                                                </label>
                                                <button className="bg-brand-action min-h-10 rounded-lg px-3 text-sm font-semibold text-white">
                                                    Verify relationship
                                                </button>
                                            </form>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ) : null}
                    <section className="border-border bg-surface rounded-2xl border p-5 sm:p-7">
                        <h2 className="text-lg font-semibold">Supporting documents</h2>
                        <div className="mt-4 grid gap-3">
                            {documents.length ? (
                                documents.map((document) => (
                                    <DocumentReview
                                        key={document.id}
                                        slug={tenant.slug}
                                        applicationId={application.id}
                                        document={document}
                                    />
                                ))
                            ) : (
                                <p className="text-text-secondary text-sm">No documents uploaded.</p>
                            )}
                        </div>
                    </section>
                    <section className="border-border bg-surface rounded-2xl border p-5 sm:p-7">
                        <h2 className="text-lg font-semibold">Application lifecycle</h2>
                        <ol className="mt-4 grid gap-4">
                            {events.map((event, index) => (
                                <li
                                    key={`${String(event.to_status)}-${index}`}
                                    className="border-border border-l-2 pl-4"
                                >
                                    <p className="font-medium capitalize">
                                        {String(event.to_status).replaceAll('_', ' ')}
                                    </p>
                                    <p className="text-text-muted mt-1 text-xs">
                                        {String(event.reason_code ?? '')} ·{' '}
                                        {new Date(String(event.occurred_at)).toLocaleString()}
                                    </p>
                                </li>
                            ))}
                        </ol>
                        {application.status === 'submitted' ||
                        [
                            'under_review',
                            'documents_required',
                            'assessment_pending',
                            'interview_pending',
                        ].includes(application.status) ? (
                            <form
                                onSubmit={(event) => submit(event, 'transition', statusForm.data)}
                                className="mt-5 grid gap-3 sm:grid-cols-[1fr_1fr_auto]"
                            >
                                <label className="grid gap-1 text-xs font-medium">
                                    Move to
                                    <select
                                        className="border-border bg-surface min-h-10 rounded-lg border px-2 text-sm"
                                        value={statusForm.data.status}
                                        onChange={(event) => statusForm.setData('status', event.target.value)}
                                    >
                                        {[
                                            'under_review',
                                            'documents_required',
                                            'assessment_pending',
                                            'interview_pending',
                                            'decision_pending',
                                            'withdrawn',
                                        ].map((status) => (
                                            <option key={status} value={status}>
                                                {status.replaceAll('_', ' ')}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="grid gap-1 text-xs font-medium">
                                    Reason
                                    <textarea
                                        className="border-border min-h-10 rounded-lg border p-2 text-sm"
                                        maxLength={500}
                                        value={statusForm.data.reason}
                                        onChange={(event) => statusForm.setData('reason', event.target.value)}
                                    />
                                </label>
                                <button className="border-border min-h-10 self-end rounded-lg border px-3 text-sm font-semibold">
                                    Update status
                                </button>
                            </form>
                        ) : null}
                    </section>
                    {can('admissions.applications.review') ? (
                        <section className="border-border bg-surface rounded-2xl border p-5 sm:p-7">
                            <h2 className="text-lg font-semibold">Review operations</h2>
                            <div className="mt-4 grid gap-6 lg:grid-cols-2">
                                <form
                                    onSubmit={(event) => submit(event, 'assign', assignForm.data)}
                                    className="grid gap-3"
                                >
                                    <h3 className="font-medium">Assign reviewer</h3>
                                    <label className="grid gap-1 text-xs font-medium">
                                        Reviewer
                                        <select
                                            required
                                            className="border-border bg-surface min-h-10 rounded-lg border px-2 text-sm"
                                            value={assignForm.data.reviewer_user_id}
                                            onChange={(event) =>
                                                assignForm.setData('reviewer_user_id', event.target.value)
                                            }
                                        >
                                            <option value="">Choose reviewer</option>
                                            {reviewers.map((reviewer) => (
                                                <option key={reviewer.id} value={reviewer.id}>
                                                    {reviewer.name}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="grid gap-1 text-xs font-medium">
                                        Assignment type
                                        <select
                                            className="border-border bg-surface min-h-10 rounded-lg border px-2 text-sm"
                                            value={assignForm.data.assignment_type}
                                            onChange={(event) =>
                                                assignForm.setData('assignment_type', event.target.value)
                                            }
                                        >
                                            <option value="review">Application review</option>
                                            <option value="document">Document review</option>
                                            <option value="assessment">Assessment</option>
                                        </select>
                                    </label>
                                    <button className="border-border min-h-10 justify-self-start rounded-lg border px-3 text-sm font-semibold">
                                        Assign
                                    </button>
                                </form>
                                <form
                                    onSubmit={(event) => submit(event, 'eligibility', eligibilityForm.data)}
                                    className="grid gap-3"
                                >
                                    <h3 className="font-medium">
                                        Eligibility check · {application.eligibility.replaceAll('_', ' ')}
                                    </h3>
                                    <label className="grid gap-1 text-xs font-medium">
                                        Human review note
                                        <textarea
                                            className="border-border min-h-20 rounded-lg border p-2 text-sm"
                                            maxLength={1000}
                                            value={eligibilityForm.data.human_review_note}
                                            onChange={(event) =>
                                                eligibilityForm.setData(
                                                    'human_review_note',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                    <button className="border-border min-h-10 justify-self-start rounded-lg border px-3 text-sm font-semibold">
                                        Evaluate rules
                                    </button>
                                </form>
                                <form
                                    onSubmit={(event) => submit(event, 'assessments', assessmentForm.data)}
                                    className="grid gap-3"
                                >
                                    <h3 className="font-medium">Assessment record</h3>
                                    <label className="grid gap-1 text-xs font-medium">
                                        Type
                                        <select
                                            className="border-border bg-surface min-h-10 rounded-lg border px-2 text-sm"
                                            value={assessmentForm.data.assessment_type}
                                            onChange={(event) =>
                                                assessmentForm.setData('assessment_type', event.target.value)
                                            }
                                        >
                                            {['entrance_test', 'interview', 'portfolio', 'other'].map(
                                                (type) => (
                                                    <option key={type} value={type}>
                                                        {type.replaceAll('_', ' ')}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </label>
                                    <div className="grid grid-cols-2 gap-3">
                                        <label className="grid gap-1 text-xs font-medium">
                                            Score
                                            <input
                                                type="number"
                                                min="0"
                                                step="0.001"
                                                className="border-border min-h-10 rounded-lg border px-2 text-sm"
                                                value={assessmentForm.data.score}
                                                onChange={(event) =>
                                                    assessmentForm.setData('score', event.target.value)
                                                }
                                            />
                                        </label>
                                        <label className="grid gap-1 text-xs font-medium">
                                            Maximum
                                            <input
                                                type="number"
                                                min="0.001"
                                                step="0.001"
                                                className="border-border min-h-10 rounded-lg border px-2 text-sm"
                                                value={assessmentForm.data.maximum_score}
                                                onChange={(event) =>
                                                    assessmentForm.setData(
                                                        'maximum_score',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                    </div>
                                    <label className="grid gap-1 text-xs font-medium">
                                        Private notes
                                        <textarea
                                            className="border-border min-h-16 rounded-lg border p-2 text-sm"
                                            value={assessmentForm.data.private_notes}
                                            onChange={(event) =>
                                                assessmentForm.setData('private_notes', event.target.value)
                                            }
                                        />
                                    </label>
                                    <button className="border-border min-h-10 justify-self-start rounded-lg border px-3 text-sm font-semibold">
                                        Save assessment
                                    </button>
                                </form>
                                {can('admissions.decisions.recommend') ? (
                                    <form
                                        onSubmit={(event) => submit(event, 'decision', decisionForm.data)}
                                        className="grid gap-3"
                                    >
                                        <h3 className="font-medium">
                                            Recommend a decision · independent approval required
                                        </h3>
                                        <label className="grid gap-1 text-xs font-medium">
                                            Decision
                                            <select
                                                className="border-border bg-surface min-h-10 rounded-lg border px-2 text-sm"
                                                value={decisionForm.data.decision}
                                                onChange={(event) =>
                                                    decisionForm.setData('decision', event.target.value)
                                                }
                                            >
                                                <option value="admit">Admit</option>
                                                <option value="waitlist">Waitlist</option>
                                                <option value="reject">Reject</option>
                                            </select>
                                        </label>
                                        <label className="grid gap-1 text-xs font-medium">
                                            Applicant-facing reason
                                            <textarea
                                                className="border-border min-h-16 rounded-lg border p-2 text-sm"
                                                value={decisionForm.data.applicant_reason}
                                                onChange={(event) =>
                                                    decisionForm.setData(
                                                        'applicant_reason',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <label className="grid gap-1 text-xs font-medium">
                                            Private reason
                                            <textarea
                                                className="border-border min-h-16 rounded-lg border p-2 text-sm"
                                                value={decisionForm.data.private_reason}
                                                onChange={(event) =>
                                                    decisionForm.setData('private_reason', event.target.value)
                                                }
                                            />
                                        </label>
                                        <button className="bg-brand-action min-h-10 justify-self-start rounded-lg px-3 text-sm font-semibold text-white">
                                            Submit recommendation
                                        </button>
                                    </form>
                                ) : null}
                                {can('admissions.offers.manage') ? (
                                    <form
                                        onSubmit={(event) => submit(event, 'offer', offerForm.data)}
                                        className="grid gap-3"
                                    >
                                        <h3 className="font-medium">Issue admission offer</h3>
                                        <label className="grid gap-1 text-xs font-medium">
                                            Accept by
                                            <input
                                                type="datetime-local"
                                                className="border-border min-h-10 rounded-lg border px-2 text-sm"
                                                value={offerForm.data.accept_by}
                                                onChange={(event) =>
                                                    offerForm.setData('accept_by', event.target.value)
                                                }
                                            />
                                        </label>
                                        <label className="grid gap-1 text-xs font-medium">
                                            Conditions
                                            <textarea
                                                className="border-border min-h-16 rounded-lg border p-2 text-sm"
                                                value={offerForm.data.conditions}
                                                onChange={(event) =>
                                                    offerForm.setData('conditions', event.target.value)
                                                }
                                            />
                                        </label>
                                        <button className="bg-brand-action min-h-10 justify-self-start rounded-lg px-3 text-sm font-semibold text-white">
                                            Issue offer
                                        </button>
                                    </form>
                                ) : null}
                            </div>
                        </section>
                    ) : null}
                </div>
                <aside className="grid content-start gap-5">
                    <section className="border-border bg-surface rounded-2xl border p-5">
                        <h2 className="font-semibold">Operations</h2>
                        <p className="text-text-secondary mt-2 text-sm">
                            Eligibility:{' '}
                            <span className="capitalize">{application.eligibility.replaceAll('_', ' ')}</span>
                        </p>
                        {handoffRecord ? (
                            <p className="mt-2 text-sm">
                                SIS handoff: {handoffRecord.status} · {handoffRecord.correlation_id}
                            </p>
                        ) : null}
                        {can('admissions.handoff.manage') && application.status === 'offer_accepted' ? (
                            <button
                                onClick={queueHandoff}
                                className="bg-brand-action mt-4 min-h-11 w-full rounded-lg px-3 text-sm font-semibold text-white"
                            >
                                Queue SIS handoff
                            </button>
                        ) : null}
                    </section>
                    {fee ? (
                        <section className="border-border bg-surface rounded-2xl border p-5">
                            <h2 className="font-semibold">Application fee</h2>
                            <p className="text-text-secondary mt-2 text-sm">
                                {fee.currency} {fee.amount} · <span className="capitalize">{fee.status}</span>
                            </p>
                            {fee.status === 'due' && can('admissions.fees.manage') ? (
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        router.post(
                                            `/t/${tenant.slug}/admissions/fees/${fee.id}/verify`,
                                            feeForm.data,
                                            { preserveScroll: true },
                                        );
                                    }}
                                    className="mt-4 grid gap-3"
                                >
                                    <label className="grid gap-1 text-xs font-medium">
                                        Resolution
                                        <select
                                            className="border-border bg-surface min-h-10 rounded-lg border px-2 text-sm"
                                            value={feeForm.data.status}
                                            onChange={(event) =>
                                                feeForm.setData('status', event.target.value)
                                            }
                                        >
                                            <option value="paid">Record manual payment</option>
                                            <option value="waived">Waive with reason</option>
                                        </select>
                                    </label>
                                    <label className="grid gap-1 text-xs font-medium">
                                        Reference
                                        <input
                                            className="border-border min-h-10 rounded-lg border px-2 text-sm"
                                            value={feeForm.data.payment_reference}
                                            onChange={(event) =>
                                                feeForm.setData('payment_reference', event.target.value)
                                            }
                                        />
                                    </label>
                                    <label className="grid gap-1 text-xs font-medium">
                                        Waiver reason
                                        <textarea
                                            className="border-border min-h-16 rounded-lg border p-2 text-sm"
                                            value={feeForm.data.waiver_reason}
                                            onChange={(event) =>
                                                feeForm.setData('waiver_reason', event.target.value)
                                            }
                                        />
                                    </label>
                                    <button className="border-border min-h-10 rounded-lg border px-3 text-sm font-semibold">
                                        Save fee record
                                    </button>
                                </form>
                            ) : null}
                        </section>
                    ) : null}
                    <DataList title="Assessments" rows={assessments} />
                    <section className="border-border bg-surface rounded-2xl border p-5">
                        <h2 className="text-lg font-semibold">Decisions</h2>
                        {decisions.length ? (
                            <ul className="mt-3 grid gap-3">
                                {decisions.map((decision) => (
                                    <li
                                        key={decision.id}
                                        className="bg-canvas grid gap-2 rounded-lg p-3 text-sm"
                                    >
                                        <p className="font-medium capitalize">
                                            {decision.decision.replaceAll('_', ' ')} ·{' '}
                                            {decision.approved_at ? 'approved' : 'awaiting approval'}
                                        </p>
                                        {decision.applicant_reason ? (
                                            <p className="text-text-secondary">
                                                Applicant reason: {decision.applicant_reason}
                                            </p>
                                        ) : null}
                                        {!decision.approved_at &&
                                        decision.recommended_by !== page.props.auth.user.id &&
                                        can('admissions.decisions.approve') ? (
                                            <button
                                                type="button"
                                                className="bg-brand-action min-h-10 justify-self-start rounded-lg px-3 text-sm font-semibold text-white"
                                                onClick={() =>
                                                    router.post(
                                                        `/t/${tenant.slug}/admissions/applications/${application.id}/decisions/${decision.id}/approve`,
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Approve recommendation
                                            </button>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-text-secondary mt-3 text-sm">No decisions yet.</p>
                        )}
                    </section>
                    <DataList title="Offers" rows={offers} />
                </aside>
            </div>
        </WorkspaceLayout>
    );
}
