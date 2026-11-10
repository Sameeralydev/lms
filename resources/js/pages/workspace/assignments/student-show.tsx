import { Head, router, useForm } from '@inertiajs/react';
import type { ChangeEvent, FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Artifact = { id: number; name: string; scan_status: string; download_url: string | null };
type IntegrityCase = {
    id: number;
    status: string;
    case_type: string;
    concern_summary: string;
    learner_response: string | null;
    response_due_at: string | null;
    disposition: string | null;
};
type Props = {
    tenant: { slug: string };
    recipient: { id: number; due_at: string | null };
    assignment: {
        id: number;
        title: string;
        course: string;
        type: string;
        summary: string | null;
        instructions: string | null;
        submission_mode: string;
        maximum_points: number | null;
        maximum_attempts: number;
        timezone: string;
        version: number;
    };
    submission: {
        id: number | null;
        status: string;
        attempt_count: number;
        feedback: string | null;
        released_score: number | null;
        integrity_cases: IntegrityCase[];
        attempt: null | {
            id: number;
            status: string;
            response_text: string | null;
            response_url: string | null;
            receipt_reference: string | null;
            artifacts: Artifact[];
        };
    };
    can_submit: boolean;
};

function IntegrityResponseForm({ tenant, caseId }: { tenant: Props['tenant']; caseId: number }) {
    const form = useForm({ response: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/t/${tenant.slug}/my-assignments/integrity-cases/${caseId}/response`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };
    return (
        <form onSubmit={submit} className="mt-4 grid gap-3">
            <FormErrors errors={form.errors} />
            <label className="grid gap-1.5 text-sm font-medium">
                Your response
                <textarea
                    required
                    minLength={8}
                    maxLength={10000}
                    rows={4}
                    value={form.data.response}
                    onChange={(event) => form.setData('response', event.currentTarget.value)}
                    className="border-border rounded-lg border p-3"
                />
            </label>
            <button
                disabled={form.processing}
                className="bg-brand-600 focus-ring min-h-11 justify-self-start rounded-lg px-4 text-sm font-semibold text-white"
            >
                Submit response
            </button>
        </form>
    );
}

export default function StudentAssignment({ tenant, recipient, assignment, submission, can_submit }: Props) {
    const form = useForm({
        response_text: submission.attempt?.response_text ?? '',
        response_url: submission.attempt?.response_url ?? '',
        idempotency_key: crypto.randomUUID(),
    });
    const uploadForm = useForm<{ files: File[] }>({ files: [] });
    const extensionForm = useForm({ reason: '', requested_due_at: '' });
    const save = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/t/${tenant.slug}/my-assignments/recipients/${recipient.id}/draft`, {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['submission', 'can_submit'] }),
        });
    };
    const upload = (event: FormEvent) => {
        event.preventDefault();
        uploadForm.post(`/t/${tenant.slug}/my-assignments/recipients/${recipient.id}/artifacts`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                uploadForm.reset();
                router.reload({ only: ['submission'] });
            },
        });
    };
    const selectFiles = (event: ChangeEvent<HTMLInputElement>) =>
        uploadForm.setData('files', Array.from(event.currentTarget.files ?? []));
    const submit = () => {
        if (!submission.attempt) return;
        router.post(
            `/t/${tenant.slug}/my-assignments/recipients/${recipient.id}/submit`,
            { attempt_id: submission.attempt.id },
            { preserveScroll: true },
        );
    };
    const requestExtension = (event: FormEvent) => {
        event.preventDefault();
        extensionForm.post(`/t/${tenant.slug}/my-assignments/recipients/${recipient.id}/extensions`, {
            preserveScroll: true,
        });
    };
    const waitingForScan =
        submission.attempt?.artifacts.some(
            (artifact) => !['clean', 'infected', 'rejected'].includes(artifact.scan_status),
        ) ?? false;
    const hasSafeFile =
        submission.attempt?.artifacts.some((artifact) => artifact.scan_status === 'clean') ?? false;
    const canSubmitResponse =
        form.data.response_text.trim() !== '' || form.data.response_url.trim() !== '' || hasSafeFile;

    return (
        <WorkspaceLayout title={assignment.title} area="tenant">
            <Head title={assignment.title} />
            <PageHeader
                eyebrow={`${assignment.course} · ${assignment.type}`}
                title={assignment.title}
                description={
                    assignment.summary ??
                    'Read the instructions and save a draft before submitting your work.'
                }
            />
            <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                <dl className="grid gap-3 sm:grid-cols-3">
                    <div>
                        <dt className="text-text-muted text-xs uppercase">Due</dt>
                        <dd className="mt-1 font-medium">
                            {recipient.due_at
                                ? new Date(recipient.due_at).toLocaleString()
                                : 'No deadline set'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-text-muted text-xs uppercase">Attempts</dt>
                        <dd className="mt-1 font-medium">
                            {submission.attempt_count} of {assignment.maximum_attempts}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-text-muted text-xs uppercase">Maximum points</dt>
                        <dd className="mt-1 font-medium">{assignment.maximum_points ?? 'Not scored'}</dd>
                    </div>
                </dl>
                <h2 className="mt-7 text-lg font-semibold">Instructions</h2>
                <p className="mt-2 text-sm leading-7 whitespace-pre-wrap">
                    {assignment.instructions || 'No additional instructions were provided.'}
                </p>
                <p className="text-text-muted mt-5 text-xs">
                    Published assignment version {assignment.version}. Submitted attempts are preserved as
                    immutable receipts.
                </p>
            </section>
            <section
                className="border-border bg-surface mt-5 rounded-2xl border p-5 sm:p-7"
                aria-labelledby="response-heading"
            >
                <h2 id="response-heading" className="text-lg font-semibold">
                    Your response
                </h2>
                {submission.feedback && (
                    <div className="bg-surface-soft mt-4 rounded-xl p-4">
                        <h3 className="font-semibold">
                            Instructor feedback
                            {submission.released_score !== null
                                ? ` · ${submission.released_score} points`
                                : ''}
                        </h3>
                        <p className="mt-2 text-sm whitespace-pre-wrap">{submission.feedback}</p>
                    </div>
                )}
                {submission.attempt?.status === 'submitted' &&
                submission.status !== 'returned_for_revision' ? (
                    <div className="bg-surface-soft mt-4 rounded-xl p-4">
                        <p className="font-medium">Submission received</p>
                        {submission.attempt.receipt_reference && (
                            <a
                                className="text-brand-700 focus-ring mt-2 inline-block rounded underline"
                                href={`/t/${tenant.slug}/my-assignments/receipts/${submission.attempt.receipt_reference}`}
                            >
                                View submission receipt
                            </a>
                        )}
                    </div>
                ) : (
                    <>
                        <form onSubmit={save} className="mt-4 grid gap-4">
                            <FormErrors errors={form.errors} />
                            {['text', 'mixed', 'file'].includes(assignment.submission_mode) && (
                                <label className="grid gap-1.5 text-sm font-medium">
                                    Written response
                                    <textarea
                                        rows={10}
                                        maxLength={100000}
                                        value={form.data.response_text}
                                        onChange={(event) =>
                                            form.setData('response_text', event.currentTarget.value)
                                        }
                                        className="border-border bg-surface rounded-lg border p-3"
                                        aria-describedby="draft-note"
                                    />
                                </label>
                            )}
                            {['url', 'mixed'].includes(assignment.submission_mode) && (
                                <label className="grid gap-1.5 text-sm font-medium">
                                    Public reference URL
                                    <input
                                        type="url"
                                        value={form.data.response_url}
                                        onChange={(event) =>
                                            form.setData('response_url', event.currentTarget.value)
                                        }
                                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    />
                                </label>
                            )}
                            <p id="draft-note" className="text-text-muted text-sm">
                                Saving a draft does not submit it. Server time is recorded only when you
                                submit.
                            </p>
                            <div className="flex justify-end">
                                <button
                                    type="submit"
                                    disabled={form.processing || !can_submit}
                                    className="border-border focus-ring min-h-11 rounded-lg border px-4 text-sm font-semibold disabled:opacity-50"
                                >
                                    {form.processing
                                        ? 'Saving…'
                                        : submission.attempt
                                          ? 'Save draft'
                                          : 'Save draft response'}
                                </button>
                            </div>
                        </form>
                        {['file', 'mixed'].includes(assignment.submission_mode) &&
                            submission.attempt?.status === 'draft' && (
                                <div className="border-border mt-5 border-t pt-5">
                                    <h3 className="font-semibold">Files</h3>
                                    <p className="text-text-muted mt-1 text-sm">
                                        Files stay private until the configured malware scanner approves them.
                                    </p>
                                    {submission.attempt.artifacts.length > 0 && (
                                        <ul className="mt-3 grid gap-2" aria-label="Uploaded files">
                                            {submission.attempt.artifacts.map((artifact) => (
                                                <li
                                                    key={artifact.id}
                                                    className="bg-surface-soft flex flex-wrap items-center justify-between gap-3 rounded-lg p-3 text-sm"
                                                >
                                                    <span>{artifact.name}</span>
                                                    <span className="text-text-muted" role="status">
                                                        {artifact.scan_status === 'clean'
                                                            ? 'Scan passed'
                                                            : artifact.scan_status === 'infected' ||
                                                                artifact.scan_status === 'rejected'
                                                              ? 'File rejected'
                                                              : 'Quarantined · scan pending'}
                                                    </span>
                                                    {artifact.download_url && (
                                                        <a
                                                            className="text-brand-700 focus-ring rounded underline"
                                                            href={artifact.download_url}
                                                        >
                                                            Download
                                                        </a>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    <form onSubmit={upload} className="mt-4 grid gap-3">
                                        <FormErrors errors={uploadForm.errors} />
                                        <label className="grid gap-1.5 text-sm font-medium">
                                            Choose up to five files
                                            <input
                                                type="file"
                                                multiple
                                                accept=".pdf,.doc,.docx,.odt,.txt,.png,.jpg,.jpeg"
                                                onChange={selectFiles}
                                                className="border-border focus-ring min-h-11 rounded-lg border p-2"
                                                aria-describedby="upload-help"
                                            />
                                        </label>
                                        <p id="upload-help" className="text-text-muted text-xs">
                                            PDF, Word, ODT, text, PNG or JPEG; 20 MB per file and 50 MB total.
                                        </p>
                                        <div className="flex justify-end">
                                            <button
                                                type="submit"
                                                disabled={
                                                    uploadForm.processing ||
                                                    uploadForm.data.files.length === 0
                                                }
                                                className="bg-brand-600 focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-50"
                                            >
                                                {uploadForm.processing
                                                    ? 'Uploading…'
                                                    : 'Upload to quarantine'}
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            )}
                        {submission.attempt?.status === 'draft' && (
                            <div className="mt-5 flex justify-end">
                                <button
                                    type="button"
                                    onClick={submit}
                                    disabled={!can_submit || !canSubmitResponse || waitingForScan}
                                    className="bg-brand-600 focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-50"
                                >
                                    {waitingForScan ? 'Waiting for file scan…' : 'Submit work'}
                                </button>
                            </div>
                        )}
                    </>
                )}
            </section>
            {submission.integrity_cases.length > 0 && (
                <section
                    className="border-border bg-surface mt-5 grid gap-4 rounded-2xl border p-5 sm:p-7"
                    aria-labelledby="integrity-heading"
                >
                    <h2 id="integrity-heading" className="text-lg font-semibold">
                        Academic integrity review
                    </h2>
                    <p className="text-text-muted text-sm">
                        Each concern is assessed by a human reviewer. No automated similarity score is used.
                    </p>
                    {submission.integrity_cases.map((integrityCase) => (
                        <article key={integrityCase.id} className="border-border rounded-xl border p-4">
                            <h3 className="font-semibold">
                                {integrityCase.case_type.replaceAll('_', ' ')} ·{' '}
                                {integrityCase.status.replaceAll('_', ' ')}
                            </h3>
                            <p className="mt-2 text-sm whitespace-pre-wrap">
                                {integrityCase.concern_summary}
                            </p>
                            {integrityCase.response_due_at && (
                                <p className="text-text-muted mt-2 text-xs">
                                    Response due {new Date(integrityCase.response_due_at).toLocaleString()}
                                </p>
                            )}
                            {integrityCase.disposition && (
                                <p className="mt-2 text-sm">
                                    Decision: {integrityCase.disposition.replaceAll('_', ' ')}
                                </p>
                            )}
                            {integrityCase.status === 'awaiting_learner' && (
                                <IntegrityResponseForm tenant={tenant} caseId={integrityCase.id} />
                            )}
                        </article>
                    ))}
                </section>
            )}
            {recipient.due_at && (
                <section
                    className="border-border bg-surface mt-5 rounded-2xl border p-5 sm:p-7"
                    aria-labelledby="extension-heading"
                >
                    <h2 id="extension-heading" className="text-lg font-semibold">
                        Request a deadline extension
                    </h2>
                    <p className="text-text-muted mt-1 text-sm">
                        The instructor reviews this request. An extension is not active until approved.
                    </p>
                    <form onSubmit={requestExtension} className="mt-4 grid gap-3 sm:grid-cols-2">
                        <FormErrors errors={extensionForm.errors} />
                        <label className="grid gap-1.5 text-sm font-medium sm:col-span-2">
                            Reason
                            <textarea
                                required
                                minLength={12}
                                maxLength={2000}
                                rows={3}
                                value={extensionForm.data.reason}
                                onChange={(event) =>
                                    extensionForm.setData('reason', event.currentTarget.value)
                                }
                                className="border-border rounded-lg border p-3"
                            />
                        </label>
                        <label className="grid gap-1.5 text-sm font-medium">
                            Requested local deadline
                            <input
                                required
                                type="datetime-local"
                                value={extensionForm.data.requested_due_at}
                                onChange={(event) =>
                                    extensionForm.setData('requested_due_at', event.currentTarget.value)
                                }
                                className="border-border min-h-11 rounded-lg border px-3"
                            />
                            <span className="text-text-muted text-xs">
                                Institution timezone: {assignment.timezone}
                            </span>
                        </label>
                        <div className="flex items-end justify-end">
                            <button
                                type="submit"
                                disabled={extensionForm.processing}
                                className="border-border focus-ring min-h-11 rounded-lg border px-4 text-sm font-semibold"
                            >
                                {extensionForm.processing ? 'Sending…' : 'Send request'}
                            </button>
                        </div>
                    </form>
                </section>
            )}
        </WorkspaceLayout>
    );
}
