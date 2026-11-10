import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Props = {
    tenant: { slug: string };
    snapshot: {
        candidate_number: string;
        student_number: string;
        session: string;
        course: string;
        starts_at: string | null;
        campus_id: number;
        revision: number;
    };
    verification_code: string;
    revision: number;
};

export default function AdmitDocument({
    tenant,
    snapshot,
    verification_code: verificationCode,
    revision,
}: Props) {
    const form = useForm({ verification_code: '' });
    const verify = (event: FormEvent) => {
        event.preventDefault();
        form.post('/admit-documents/verify', { preserveScroll: true });
    };
    return (
        <WorkspaceLayout title="Examination admit document" area="tenant">
            <Head title="Examination admit document" />
            <main className="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6">
                <PageHeader
                    eyebrow="Secure examination document"
                    title="Examination admit document"
                    description="This private document is available only to the linked active student account."
                />
                <section
                    className="border-primary/30 bg-surface rounded-2xl border-2 p-6 shadow-sm print:shadow-none"
                    aria-label="Admit document details"
                >
                    <p className="text-primary text-sm font-semibold tracking-wide uppercase">
                        TechMentors.PK · Candidate entry
                    </p>
                    <h2 className="mt-4 text-2xl font-bold">{snapshot.session}</h2>
                    <dl className="mt-6 grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt className="text-text-muted text-sm">Candidate number</dt>
                            <dd className="font-semibold">{snapshot.candidate_number}</dd>
                        </div>
                        <div>
                            <dt className="text-text-muted text-sm">Student number</dt>
                            <dd className="font-semibold">{snapshot.student_number}</dd>
                        </div>
                        <div>
                            <dt className="text-text-muted text-sm">Course</dt>
                            <dd className="font-semibold">{snapshot.course}</dd>
                        </div>
                        <div>
                            <dt className="text-text-muted text-sm">Scheduled start</dt>
                            <dd className="font-semibold">
                                {snapshot.starts_at
                                    ? new Date(snapshot.starts_at).toLocaleString()
                                    : 'To be confirmed'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-text-muted text-sm">Document revision</dt>
                            <dd className="font-semibold">{revision}</dd>
                        </div>
                    </dl>
                    <div className="bg-background mt-6 rounded-xl p-4">
                        <p className="text-sm font-medium">Verification code</p>
                        <code
                            className="mt-1 block text-sm break-all"
                            aria-label="Private admit document verification code"
                        >
                            {verificationCode}
                        </code>
                        <p className="text-text-muted mt-2 text-xs">
                            Do not share this code publicly. A verifier sees only whether it is currently
                            valid.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="bg-primary text-primary-foreground mt-5 rounded-lg px-4 py-2 font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    >
                        Print document
                    </button>
                </section>
                <section className="border-border bg-surface rounded-2xl border p-5">
                    <h2 className="text-lg font-semibold">Verify a document</h2>
                    <form className="mt-3 space-y-3" onSubmit={verify}>
                        <label className="block text-sm font-medium">
                            Verification code
                            <input
                                className="border-border bg-background mt-1 block w-full rounded-lg"
                                minLength={32}
                                maxLength={96}
                                autoComplete="off"
                                value={form.data.verification_code}
                                onChange={(event) => form.setData('verification_code', event.target.value)}
                            />
                        </label>
                        <FormErrors errors={form.errors} />
                        <button
                            className="border-border rounded-lg border px-4 py-2 font-semibold"
                            disabled={form.processing}
                        >
                            Verify
                        </button>
                    </form>
                </section>
                <a
                    href={`/t/${tenant.slug}/examinations/my-governance`}
                    className="text-primary inline-flex font-semibold underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2"
                >
                    Back to my exam documents
                </a>
            </main>
        </WorkspaceLayout>
    );
}
