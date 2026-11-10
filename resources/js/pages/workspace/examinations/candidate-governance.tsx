import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Candidate = {
    id: number;
    cycle_id: number;
    candidate_number: string;
    cycle_title: string;
    student_number?: string;
    legal_first_name?: string;
    legal_last_name?: string;
};
type Accommodation = {
    id: number;
    candidate_id: number;
    accommodation_type: string;
    extra_time_minutes: number;
    status: string;
    confidential_details?: string | null;
    review_reason?: string | null;
};
type Exception = {
    id: number;
    candidate_id: number;
    cycle_id: number;
    rule_key: string;
    student_reason: string;
    status: string;
    review_reason?: string | null;
};
type Session = { id: number; cycle_id: number; title: string; starts_at_utc: string };
type Props = {
    mode: 'student' | 'review';
    tenant: { slug: string };
    candidates: Candidate[];
    sessions?: Session[];
    accommodationRequests: Accommodation[];
    eligibilityExceptions: Exception[];
    admitDocuments: {
        id: number;
        candidate_id?: number;
        session_id?: number;
        revision?: number;
        issued_at: string;
        expires_at?: string | null;
        revoked_at?: string | null;
        session_title?: string;
        safe_snapshot?: string;
    }[];
    policy?: {
        name: string;
        active: boolean;
        require_active_student: boolean;
        require_confirmed_enrollment: boolean;
    } | null;
    canRequestAccommodation?: boolean;
    canRequestException?: boolean;
    canReviewAccommodations?: boolean;
    canReviewEligibility?: boolean;
    canIssueDocuments?: boolean;
};

export default function CandidateGovernance(props: Props) {
    const { tenant } = props;
    const root = `/t/${tenant.slug}`;
    const accommodation = useForm({
        accommodation_type: 'extra_time',
        extra_time_minutes: 0,
        confidential_details: '',
    });
    const exception = useForm({ rule_key: 'confirmed_course_enrollment', reason: '' });
    const decision = useForm({ decision: 'approved', reason: '', expires_at: '' });
    const policy = useForm({
        name: props.policy?.name ?? 'Institution examination eligibility',
        require_active_student: props.policy?.require_active_student ?? true,
        require_confirmed_enrollment: props.policy?.require_confirmed_enrollment ?? true,
        active: props.policy?.active ?? true,
    });
    const submitAccommodation = (event: FormEvent, url: string) => {
        event.preventDefault();
        accommodation.post(url, { preserveScroll: true, onSuccess: () => accommodation.reset() });
    };
    const submitException = (event: FormEvent, url: string) => {
        event.preventDefault();
        exception.post(url, { preserveScroll: true, onSuccess: () => exception.reset() });
    };
    const submitPolicy = (event: FormEvent, url: string) => {
        event.preventDefault();
        policy.put(url, { preserveScroll: true, onSuccess: () => policy.reset() });
    };
    const decide = (url: string, next: 'approved' | 'rejected') => {
        router.post(
            url,
            { ...decision.data, decision: next },
            {
                preserveScroll: true,
                onSuccess: () => decision.reset(),
                onError: (errors) => decision.setError(errors),
            },
        );
    };

    return (
        <WorkspaceLayout title="Examination candidate governance" area="tenant">
            <Head title="Examination candidate governance" />
            <main className="mx-auto w-full max-w-7xl space-y-7 px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    eyebrow="Examinations"
                    title={
                        props.mode === 'student'
                            ? 'My exam requests and documents'
                            : 'Eligibility and candidate support'
                    }
                    description={
                        props.mode === 'student'
                            ? 'Request confidential support and track decisions for your registered examinations.'
                            : 'Review candidate eligibility exceptions and accommodations, and issue controlled admit documents.'
                    }
                />

                {props.mode === 'student' ? (
                    <section className="grid gap-5 lg:grid-cols-2" aria-label="My examination requests">
                        {props.candidates.map((candidate) => (
                            <article
                                key={candidate.id}
                                className="border-border bg-surface rounded-2xl border p-5 shadow-sm"
                            >
                                <h2 className="text-text text-lg font-semibold">{candidate.cycle_title}</h2>
                                <p className="text-text-muted mt-1 text-sm">
                                    Candidate {candidate.candidate_number}
                                </p>
                                {props.canRequestAccommodation && (
                                    <form
                                        className="border-border mt-5 space-y-3 border-t pt-4"
                                        onSubmit={(event) =>
                                            submitAccommodation(
                                                event,
                                                `${root}/examinations/my-governance/cycles/${candidate.cycle_id}/candidates/${candidate.id}/accommodations`,
                                            )
                                        }
                                    >
                                        <h3 className="font-semibold">Request an accommodation</h3>
                                        <label className="block text-sm font-medium">
                                            Support type
                                            <select
                                                className="border-border bg-background mt-1 block w-full rounded-lg"
                                                value={accommodation.data.accommodation_type}
                                                onChange={(event) =>
                                                    accommodation.setData(
                                                        'accommodation_type',
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="extra_time">Extra time</option>
                                                <option value="reader">Reader</option>
                                                <option value="scribe">Scribe</option>
                                                <option value="accessible_room">Accessible room</option>
                                                <option value="assistive_technology">
                                                    Assistive technology
                                                </option>
                                                <option value="other">Other support</option>
                                            </select>
                                        </label>
                                        <label className="block text-sm font-medium">
                                            Additional minutes
                                            <input
                                                type="number"
                                                min="0"
                                                max="300"
                                                className="border-border bg-background mt-1 block w-full rounded-lg"
                                                value={accommodation.data.extra_time_minutes}
                                                onChange={(event) =>
                                                    accommodation.setData(
                                                        'extra_time_minutes',
                                                        Number(event.target.value),
                                                    )
                                                }
                                            />
                                        </label>
                                        <label className="block text-sm font-medium">
                                            Confidential supporting details
                                            <textarea
                                                className="border-border bg-background mt-1 block w-full rounded-lg"
                                                rows={3}
                                                maxLength={2000}
                                                value={accommodation.data.confidential_details}
                                                onChange={(event) =>
                                                    accommodation.setData(
                                                        'confidential_details',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <FormErrors errors={accommodation.errors} />
                                        <button
                                            className="bg-primary text-primary-foreground rounded-lg px-4 py-2 font-semibold disabled:opacity-60"
                                            disabled={accommodation.processing}
                                        >
                                            Submit confidential request
                                        </button>
                                    </form>
                                )}
                                {props.canRequestException && (
                                    <form
                                        className="border-border mt-5 space-y-3 border-t pt-4"
                                        onSubmit={(event) =>
                                            submitException(
                                                event,
                                                `${root}/examinations/my-governance/cycles/${candidate.cycle_id}/candidates/${candidate.id}/eligibility-exceptions`,
                                            )
                                        }
                                    >
                                        <h3 className="font-semibold">
                                            Request an enrollment eligibility review
                                        </h3>
                                        <label className="block text-sm font-medium">
                                            Reason
                                            <textarea
                                                required
                                                minLength={20}
                                                maxLength={2000}
                                                className="border-border bg-background mt-1 block w-full rounded-lg"
                                                value={exception.data.reason}
                                                onChange={(event) =>
                                                    exception.setData('reason', event.target.value)
                                                }
                                            />
                                        </label>
                                        <FormErrors errors={exception.errors} />
                                        <button
                                            className="border-border rounded-lg border px-4 py-2 font-semibold disabled:opacity-60"
                                            disabled={exception.processing}
                                        >
                                            Request review
                                        </button>
                                    </form>
                                )}
                                <div className="border-border mt-5 space-y-3 border-t pt-4">
                                    <h3 className="font-semibold">Request history</h3>
                                    {props.accommodationRequests
                                        .filter((request) => request.candidate_id === candidate.id)
                                        .map((request) => (
                                            <p
                                                key={`accommodation-${request.id}`}
                                                className="text-text-muted text-sm"
                                            >
                                                Accommodation request Â· {request.status.replaceAll('_', ' ')}
                                                {request.review_reason ? ` Â· ${request.review_reason}` : ''}
                                            </p>
                                        ))}
                                    {props.eligibilityExceptions
                                        .filter((item) => item.candidate_id === candidate.id)
                                        .map((item) => (
                                            <p
                                                key={`exception-${item.id}`}
                                                className="text-text-muted text-sm"
                                            >
                                                Eligibility review Â· {item.status.replaceAll('_', ' ')}
                                                {item.review_reason ? ` Â· ${item.review_reason}` : ''}
                                            </p>
                                        ))}
                                    {props.accommodationRequests.every(
                                        (request) => request.candidate_id !== candidate.id,
                                    ) &&
                                        props.eligibilityExceptions.every(
                                            (item) => item.candidate_id !== candidate.id,
                                        ) && (
                                            <p className="text-text-muted text-sm">
                                                No requests have been submitted.
                                            </p>
                                        )}
                                </div>
                            </article>
                        ))}
                        {props.candidates.length === 0 && (
                            <p className="border-border text-text-muted rounded-xl border border-dashed p-8">
                                No active examination registrations are linked to this account.
                            </p>
                        )}
                    </section>
                ) : (
                    <>
                        <section className="border-border bg-surface rounded-2xl border p-5 shadow-sm">
                            <h2 className="text-lg font-semibold">Institution eligibility policy</h2>
                            <p className="text-text-muted mt-1 text-sm">
                                Policy checks are evaluated during seating validation, admit issuance, and
                                online exam entry. Exceptions never waive candidate registration or active
                                student identity.
                            </p>
                            <form
                                className="mt-4 grid gap-4 md:grid-cols-2"
                                onSubmit={(event) =>
                                    submitPolicy(
                                        event,
                                        `${root}/academics/examinations/candidate-governance/policy`,
                                    )
                                }
                            >
                                <label className="text-sm font-medium">
                                    Policy name
                                    <input
                                        className="border-border bg-background mt-1 block w-full rounded-lg"
                                        value={policy.data.name}
                                        onChange={(event) => policy.setData('name', event.target.value)}
                                    />
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={policy.data.require_active_student}
                                        onChange={(event) =>
                                            policy.setData('require_active_student', event.target.checked)
                                        }
                                    />
                                    Require active student status
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={policy.data.require_confirmed_enrollment}
                                        onChange={(event) =>
                                            policy.setData(
                                                'require_confirmed_enrollment',
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    Require confirmed course and campus enrollment
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={policy.data.active}
                                        onChange={(event) => policy.setData('active', event.target.checked)}
                                    />
                                    Policy active
                                </label>
                                <FormErrors errors={policy.errors} />
                                <button className="bg-primary text-primary-foreground w-fit rounded-lg px-4 py-2 font-semibold">
                                    Save eligibility policy
                                </button>
                            </form>
                        </section>
                        <section className="grid gap-5 xl:grid-cols-2">
                            <div className="border-border bg-surface rounded-2xl border p-5 shadow-sm">
                                <h2 className="text-lg font-semibold">Accommodation requests</h2>
                                <div className="mt-4 space-y-3">
                                    {props.accommodationRequests.map((item) => (
                                        <article
                                            key={item.id}
                                            className="border-border rounded-xl border p-4"
                                        >
                                            <div className="flex flex-wrap justify-between gap-2">
                                                <strong>
                                                    {item.accommodation_type.replaceAll('_', ' ')} ·{' '}
                                                    {item.status}
                                                </strong>
                                                <span className="text-text-muted text-sm">
                                                    +{item.extra_time_minutes} min
                                                </span>
                                            </div>
                                            {item.confidential_details && (
                                                <p className="mt-2 text-sm">
                                                    Confidential details: {item.confidential_details}
                                                </p>
                                            )}
                                            {item.review_reason && (
                                                <p className="text-text-muted mt-2 text-sm">
                                                    Review: {item.review_reason}
                                                </p>
                                            )}
                                            {item.status === 'pending' && props.canReviewAccommodations && (
                                                <div className="mt-3 flex flex-wrap gap-2">
                                                    <button
                                                        type="button"
                                                        className="bg-primary text-primary-foreground rounded-lg px-3 py-2 text-sm font-semibold"
                                                        onClick={() =>
                                                            decide(
                                                                `${root}/academics/examinations/accommodation-requests/${item.id}/decision`,
                                                                'approved',
                                                            )
                                                        }
                                                    >
                                                        Approve
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="border-border rounded-lg border px-3 py-2 text-sm"
                                                        onClick={() =>
                                                            decide(
                                                                `${root}/academics/examinations/accommodation-requests/${item.id}/decision`,
                                                                'rejected',
                                                            )
                                                        }
                                                    >
                                                        Reject
                                                    </button>
                                                    <p className="text-text-muted basis-full text-xs">
                                                        Decision reason and expiry are validated by the
                                                        server. Set them in the review fields below before
                                                        deciding.
                                                    </p>
                                                </div>
                                            )}
                                        </article>
                                    ))}
                                    {props.accommodationRequests.length === 0 && (
                                        <p className="text-text-muted text-sm">
                                            No requests are awaiting or recorded for the current scope.
                                        </p>
                                    )}
                                </div>
                                {props.canReviewAccommodations && (
                                    <div className="border-border mt-4 grid gap-3 border-t pt-4">
                                        <label className="text-sm">
                                            Decision reason
                                            <textarea
                                                className="border-border bg-background mt-1 block w-full rounded-lg"
                                                value={decision.data.reason}
                                                onChange={(event) =>
                                                    decision.setData('reason', event.target.value)
                                                }
                                            />
                                        </label>
                                        <label className="text-sm">
                                            Approval expiry
                                            <input
                                                type="datetime-local"
                                                className="border-border bg-background mt-1 block w-full rounded-lg"
                                                value={decision.data.expires_at}
                                                onChange={(event) =>
                                                    decision.setData('expires_at', event.target.value)
                                                }
                                            />
                                        </label>
                                        <FormErrors errors={decision.errors} />
                                    </div>
                                )}
                            </div>
                            <div className="border-border bg-surface rounded-2xl border p-5 shadow-sm">
                                <h2 className="text-lg font-semibold">Eligibility exception requests</h2>
                                <div className="mt-4 space-y-3">
                                    {props.eligibilityExceptions.map((item) => (
                                        <article
                                            key={item.id}
                                            className="border-border rounded-xl border p-4"
                                        >
                                            <strong>
                                                {item.rule_key.replaceAll('_', ' ')} · {item.status}
                                            </strong>
                                            <p className="mt-2 text-sm">{item.student_reason}</p>
                                            {item.review_reason && (
                                                <p className="text-text-muted mt-2 text-sm">
                                                    Decision: {item.review_reason}
                                                </p>
                                            )}
                                            {item.status === 'pending' && props.canReviewEligibility && (
                                                <div className="mt-3 flex gap-2">
                                                    <button
                                                        type="button"
                                                        className="bg-primary text-primary-foreground rounded-lg px-3 py-2 text-sm font-semibold"
                                                        onClick={() =>
                                                            decide(
                                                                `${root}/academics/examinations/eligibility-exceptions/${item.id}/decision`,
                                                                'approved',
                                                            )
                                                        }
                                                    >
                                                        Approve
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="border-border rounded-lg border px-3 py-2 text-sm"
                                                        onClick={() =>
                                                            decide(
                                                                `${root}/academics/examinations/eligibility-exceptions/${item.id}/decision`,
                                                                'rejected',
                                                            )
                                                        }
                                                    >
                                                        Reject
                                                    </button>
                                                </div>
                                            )}
                                        </article>
                                    ))}
                                    {props.eligibilityExceptions.length === 0 && (
                                        <p className="text-text-muted text-sm">
                                            No eligibility exceptions are recorded for the current scope.
                                        </p>
                                    )}
                                </div>
                            </div>
                        </section>
                        {props.canIssueDocuments && (
                            <section className="border-border bg-surface rounded-2xl border p-5 shadow-sm">
                                <h2 className="text-lg font-semibold">Issue admit documents</h2>
                                <p className="text-text-muted mt-1 text-sm">
                                    Issuance requires a published session and a passing eligibility
                                    evaluation. Reissue revokes the previous document.
                                </p>
                                <div className="mt-4 grid gap-3 md:grid-cols-2">
                                    {props.sessions?.flatMap((session) =>
                                        props.candidates
                                            .filter((candidate) => candidate.cycle_id === session.cycle_id)
                                            .map((candidate) => (
                                                <article
                                                    key={`${session.id}-${candidate.id}`}
                                                    className="border-border flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3"
                                                >
                                                    <div>
                                                        <strong>
                                                            {candidate.student_number} ·{' '}
                                                            {candidate.legal_first_name}{' '}
                                                            {candidate.legal_last_name}
                                                        </strong>
                                                        <p className="text-text-muted text-sm">
                                                            {session.title} ·{' '}
                                                            {new Date(session.starts_at_utc).toLocaleString()}
                                                        </p>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.post(
                                                                `${root}/academics/examinations/admit-documents/sessions/${session.id}/candidates/${candidate.id}`,
                                                                {},
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                        className="border-border rounded-lg border px-3 py-2 text-sm font-semibold"
                                                    >
                                                        Issue admit document
                                                    </button>
                                                </article>
                                            )),
                                    )}
                                </div>
                            </section>
                        )}
                    </>
                )}

                {props.mode === 'student' && (
                    <section className="border-border bg-surface rounded-2xl border p-5 shadow-sm">
                        <h2 className="text-lg font-semibold">Issued admit documents</h2>
                        <ul className="mt-3 space-y-2">
                            {props.admitDocuments.map((document) => (
                                <li
                                    key={document.id}
                                    className="border-border flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                                >
                                    <div>
                                        <strong>{document.session_title}</strong>
                                        <span className="text-text-muted ml-2 text-sm">
                                            Issued {new Date(document.issued_at).toLocaleDateString()}
                                        </span>
                                    </div>
                                    <Link
                                        className="text-primary font-semibold underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2"
                                        href={`${root}/examinations/my-governance/admit-documents/${document.id}`}
                                    >
                                        Open admit document
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        {props.admitDocuments.length === 0 && (
                            <p className="text-text-muted mt-2 text-sm">
                                No admit documents have been issued.
                            </p>
                        )}
                    </section>
                )}
            </main>
        </WorkspaceLayout>
    );
}
