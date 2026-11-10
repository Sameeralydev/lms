import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Section = {
    id: number;
    code: string;
    capacity: number;
    reserved: number;
    occupied: number;
    waitlistEnabled: boolean;
};
type Offering = {
    id: number;
    code: string;
    courseCode: string;
    title: string;
    period: string;
    courseId: number;
    prerequisites: { id: number; code: string; title: string; relation: string }[];
    sections: Section[];
};
type Enrollment = {
    id: number;
    status: string;
    course: string;
    code: string;
    section: string;
    requestedAt: string;
    canDrop: boolean;
    waitlistEntryId: number | null;
};
type RegistrationPreview = { available_seats: number; expected_status: string; full: boolean };
type Props = {
    tenant: { slug: string };
    studentAvailable: boolean;
    offerings: Offering[];
    myEnrollments: {
        data: Enrollment[];
        links: { url: string | null; label: string; active: boolean }[];
    };
};

export default function StudentRegistration({ tenant, studentAvailable, offerings, myEnrollments }: Props) {
    const form = useForm({ section_id: '', idempotency_key: '' });
    const [search, setSearch] = useState('');
    const [preview, setPreview] = useState<{ offering: Offering; section: Section } | null>(null);
    const [previewResult, setPreviewResult] = useState<RegistrationPreview | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [previewError, setPreviewError] = useState('');
    const confirmButton = useRef<HTMLButtonElement>(null);
    const dialogRef = useRef<HTMLElement>(null);
    const previewTrigger = useRef<HTMLButtonElement | null>(null);
    useEffect(() => {
        if (!preview) {
            previewTrigger.current?.focus();
            return;
        }
        if (!previewResult) return;
        confirmButton.current?.focus();
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setPreview(null);
                setPreviewResult(null);
            }
            if (event.key === 'Tab' && dialogRef.current) {
                const focusable = dialogRef.current.querySelectorAll<HTMLElement>(
                    'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]',
                );
                const first = focusable.item(0);
                const last = focusable.item(focusable.length - 1);
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last?.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first?.focus();
                }
            }
        };
        document.addEventListener('keydown', closeOnEscape);
        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [preview, previewResult]);
    const submit = () => {
        if (!preview) return;
        form.setData('section_id', String(preview.section.id));
        form.setData('idempotency_key', window.crypto.randomUUID());
        form.post(`/t/${tenant.slug}/registration`, {
            preserveScroll: true,
            onSuccess: () => setPreview(null),
        });
    };

    const beginPreview = async (offering: Offering, section: Section, trigger: HTMLButtonElement) => {
        previewTrigger.current = trigger;
        setPreviewLoading(true);
        setPreviewError('');
        try {
            const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
            const response = await fetch(`/t/${tenant.slug}/registration/preview`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                },
                body: JSON.stringify({ section_id: section.id }),
            });
            const result = (await response.json()) as {
                preview?: RegistrationPreview;
                message?: string;
                errors?: Record<string, string[]>;
            };
            if (!response.ok || !result.preview) {
                setPreviewError(
                    result.errors?.section_id?.[0] ??
                        result.message ??
                        'Registration eligibility could not be confirmed.',
                );
                return;
            }
            setPreviewResult(result.preview);
            setPreview({ offering, section });
        } catch {
            setPreviewError('Registration preview is unavailable. Please retry.');
        } finally {
            setPreviewLoading(false);
        }
    };

    return (
        <WorkspaceLayout area="tenant" title="Course registration">
            <Head title="Course registration" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Academic enrollment"
                    title="Course registration"
                    description="Browse open courses, check section availability, and review your registration history."
                />
                {!studentAvailable && (
                    <p
                        role="status"
                        className="bg-surface text-text-primary rounded-xl border border-amber-400/50 p-4"
                    >
                        An active student record linked to this account is required before registering.
                    </p>
                )}
                <FormErrors errors={form.errors} />
                <section aria-labelledby="available-courses" className="space-y-3">
                    <h2 id="available-courses" className="text-text-primary text-xl font-semibold">
                        Available courses
                    </h2>
                    <label className="text-text-primary block max-w-xl text-sm font-medium">
                        Search courses
                        <input
                            aria-label="Search courses"
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="border-border bg-canvas mt-1 min-h-11 w-full rounded-lg border px-3"
                        />
                    </label>
                    {offerings.length === 0 ? (
                        <p className="text-text-muted border-border bg-surface rounded-xl border p-5">
                            There are no open offerings for the active academic period.
                        </p>
                    ) : (
                        offerings
                            .filter((offering) =>
                                `${offering.courseCode} ${offering.title} ${offering.code}`
                                    .toLowerCase()
                                    .includes(search.toLowerCase()),
                            )
                            .map((offering) => (
                                <article
                                    key={offering.id}
                                    className="border-border bg-surface rounded-xl border p-5"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p className="text-brand-link text-xs font-semibold tracking-wide uppercase">
                                                {offering.courseCode} · {offering.code}
                                            </p>
                                            <h3 className="text-text-primary mt-1 text-lg font-semibold">
                                                {offering.title}
                                            </h3>
                                            <p className="text-text-muted text-sm">{offering.period}</p>
                                            {offering.prerequisites.length > 0 && (
                                                <ul
                                                    aria-label="Prerequisite requirements"
                                                    className="text-text-muted mt-2 space-y-1 text-sm"
                                                >
                                                    {offering.prerequisites.map((item) => (
                                                        <li key={`${item.id}-${item.relation}`}>
                                                            {item.relation === 'corequisite'
                                                                ? 'Co-requisite'
                                                                : 'Prerequisite'}
                                                            : {item.code} · {item.title}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </div>
                                    </div>
                                    <ul className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                        {offering.sections.map((section) => {
                                            const available = Math.max(
                                                0,
                                                section.capacity - section.reserved - section.occupied,
                                            );
                                            return (
                                                <li
                                                    key={section.id}
                                                    className="border-border bg-canvas rounded-lg border p-4"
                                                >
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span className="text-text-primary font-semibold">
                                                            Section {section.code}
                                                        </span>
                                                        <span className="text-text-secondary text-sm">
                                                            {available} seats available
                                                        </span>
                                                    </div>
                                                    {studentAvailable && (
                                                        <button
                                                            type="button"
                                                            onClick={(event) =>
                                                                void beginPreview(
                                                                    offering,
                                                                    section,
                                                                    event.currentTarget,
                                                                )
                                                            }
                                                            disabled={form.processing || previewLoading}
                                                            className="bg-brand-action focus-visible:outline-focus mt-3 min-h-11 w-full rounded-lg px-4 py-2 text-sm font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-60"
                                                        >
                                                            {previewLoading
                                                                ? 'Checking eligibility…'
                                                                : available > 0
                                                                  ? 'Preview registration'
                                                                  : section.waitlistEnabled
                                                                    ? 'Preview waitlist request'
                                                                    : 'Section full'}
                                                        </button>
                                                    )}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                    {previewError && (
                                        <p role="alert" className="text-danger mt-2 text-sm">
                                            {previewError}
                                        </p>
                                    )}
                                    {studentAvailable &&
                                        offering.prerequisites.map((item) => (
                                            <PrerequisiteExceptionForm
                                                key={`${offering.courseId}-${item.id}`}
                                                tenant={tenant.slug}
                                                courseId={offering.courseId}
                                                prerequisite={item}
                                            />
                                        ))}
                                </article>
                            ))
                    )}
                </section>
                {preview && (
                    <div
                        className="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4"
                        onMouseDown={(event) => {
                            if (event.target === event.currentTarget) setPreview(null);
                        }}
                    >
                        <section
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="registration-preview-title"
                            ref={dialogRef}
                            className="border-border bg-surface w-full max-w-lg space-y-4 rounded-2xl border p-6 shadow-xl"
                        >
                            <h2
                                id="registration-preview-title"
                                className="text-text-primary text-xl font-semibold"
                            >
                                Review registration
                            </h2>
                            <p className="text-text-secondary">
                                {preview.offering.courseCode} · {preview.offering.title} · Section{' '}
                                {preview.section.code}
                            </p>
                            <p className="text-text-muted text-sm">
                                The request will be checked against current membership, academic placement,
                                holds, prerequisites, policy limits, dates and seat availability before it is
                                saved.
                            </p>
                            {previewResult && (
                                <p role="status" className="text-text-primary bg-surface-soft rounded-lg p-3">
                                    {previewResult.full
                                        ? 'This section is full. Your request will enter the waitlist.'
                                        : `${previewResult.available_seats} seat(s) are currently available.`}{' '}
                                    Expected result: {previewResult.expected_status.replaceAll('_', ' ')}.
                                </p>
                            )}
                            <FormErrors errors={form.errors} />
                            <div className="flex flex-wrap justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setPreview(null);
                                        setPreviewResult(null);
                                    }}
                                    className="border-border text-text-primary min-h-11 rounded-lg border px-4 font-semibold"
                                >
                                    Cancel
                                </button>
                                <button
                                    ref={confirmButton}
                                    type="button"
                                    disabled={form.processing || !previewResult}
                                    onClick={submit}
                                    className="bg-brand-action min-h-11 rounded-lg px-4 font-semibold text-white"
                                >
                                    Confirm request
                                </button>
                            </div>
                        </section>
                    </div>
                )}
                <section aria-labelledby="my-enrollments" className="space-y-3">
                    <h2 id="my-enrollments" className="text-text-primary text-xl font-semibold">
                        My registrations
                    </h2>
                    {myEnrollments.data.length === 0 ? (
                        <p className="border-border bg-surface text-text-muted rounded-xl border p-5">
                            Your course registration history will appear here.
                        </p>
                    ) : (
                        <div className="border-border bg-surface overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[38rem] text-left text-sm">
                                <thead className="bg-surface-soft text-text-secondary">
                                    <tr>
                                        <th className="p-3">Course</th>
                                        <th className="p-3">Section</th>
                                        <th className="p-3">Status</th>
                                        <th className="p-3">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {myEnrollments.data.map((enrollment) => (
                                        <tr key={enrollment.id} className="border-border border-t">
                                            <td className="p-3">
                                                <span className="text-text-primary font-medium">
                                                    {enrollment.course}
                                                </span>
                                                <span className="text-text-muted ml-2">
                                                    {enrollment.code}
                                                </span>
                                            </td>
                                            <td className="text-text-secondary p-3">{enrollment.section}</td>
                                            <td className="p-3">
                                                <span className="bg-surface-soft text-text-primary rounded-full px-2 py-1">
                                                    {enrollment.status.replaceAll('_', ' ')}
                                                </span>
                                            </td>
                                            <td className="p-3">
                                                {enrollment.waitlistEntryId && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.post(
                                                                `/t/${tenant.slug}/registration/waitlist/${enrollment.waitlistEntryId}/accept`,
                                                                {},
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                        className="bg-brand-action focus-visible:outline-focus mr-2 min-h-10 rounded-lg px-3 font-semibold text-white focus-visible:outline-2"
                                                    >
                                                        Accept seat
                                                    </button>
                                                )}
                                                {enrollment.canDrop && (
                                                    <>
                                                        {enrollment.status === 'confirmed' ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    const reason =
                                                                        window.prompt(
                                                                            'Reason for withdrawal',
                                                                        );
                                                                    if (reason)
                                                                        router.post(
                                                                            `/t/${tenant.slug}/registration/${enrollment.id}/withdraw`,
                                                                            { reason },
                                                                            { preserveScroll: true },
                                                                        );
                                                                }}
                                                                className="border-border text-text-primary focus-visible:outline-focus min-h-10 rounded-lg border px-3 font-semibold focus-visible:outline-2"
                                                            >
                                                                Request withdrawal
                                                            </button>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    router.delete(
                                                                        `/t/${tenant.slug}/registration/${enrollment.id}`,
                                                                        { preserveScroll: true },
                                                                    )
                                                                }
                                                                className="border-border text-text-primary focus-visible:outline-focus min-h-10 rounded-lg border px-3 font-semibold focus-visible:outline-2"
                                                            >
                                                                Drop
                                                            </button>
                                                        )}
                                                    </>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <nav aria-label="Enrollment history pages" className="flex flex-wrap gap-2">
                        {myEnrollments.links.map((link) => (
                            <a
                                key={link.label}
                                aria-current={link.active ? 'page' : undefined}
                                href={link.url ?? undefined}
                                className="border-border text-text-primary rounded border px-3 py-2 text-sm"
                            >
                                {link.label.replace('&laquo;', 'Previous').replace('&raquo;', 'Next')}
                            </a>
                        ))}
                    </nav>
                </section>
            </div>
        </WorkspaceLayout>
    );
}

function PrerequisiteExceptionForm({
    tenant,
    courseId,
    prerequisite,
}: {
    tenant: string;
    courseId: number;
    prerequisite: Offering['prerequisites'][number];
}) {
    const form = useForm({
        course_id: String(courseId),
        prerequisite_course_id: String(prerequisite.id),
        exception_type: 'equivalency',
        reason: '',
        evidence_reference: '',
    });
    return (
        <details className="border-border mt-2 rounded-lg border p-3">
            <summary className="text-text-primary min-h-8 cursor-pointer font-medium">
                Request prerequisite evidence review for {prerequisite.code}
            </summary>
            <form
                className="mt-3 grid gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/t/${tenant}/registration/prerequisite-exceptions`, {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <label className="text-text-primary text-sm">
                    Request type
                    <select
                        className="border-border bg-canvas mt-1 min-h-10 w-full rounded border px-2"
                        value={form.data.exception_type}
                        onChange={(event) => form.setData('exception_type', event.target.value)}
                    >
                        <option value="equivalency">Equivalency</option>
                        <option value="waiver">Waiver</option>
                    </select>
                </label>
                <label className="text-text-primary text-sm">
                    Reason
                    <textarea
                        required
                        minLength={12}
                        maxLength={1000}
                        className="border-border bg-canvas mt-1 min-h-20 w-full rounded border p-2"
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                    />
                </label>
                <label className="text-text-primary text-sm">
                    Authoritative evidence reference
                    <input
                        required
                        maxLength={180}
                        className="border-border bg-canvas mt-1 min-h-10 w-full rounded border px-2"
                        value={form.data.evidence_reference}
                        onChange={(event) => form.setData('evidence_reference', event.target.value)}
                    />
                </label>
                <button
                    disabled={form.processing}
                    className="bg-brand-action min-h-10 rounded px-3 font-semibold text-white"
                >
                    Submit for registrar review
                </button>
            </form>
        </details>
    );
}
