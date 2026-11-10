import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AdmissionsPublicShell } from '@/components/admissions/public-shell';
import { PageHeader } from '@/components/ui/page-header';
import { TextField } from '@/components/ui/text-field';

type Field = {
    key: string;
    type: string;
    label: string;
    help?: string;
    required?: boolean;
    options?: { value: string; label: string }[];
};
type Props = {
    tenant: { slug: string; name: string };
    intake: {
        code: string;
        name: string;
        cycle: string | null;
        delivery_mode: string;
        application_deadline: string | null;
        public_description: string | null;
        application_fee_amount: string | null;
        application_fee_currency: string | null;
    };
    campus: { name: string; city: string | null } | null;
    program: { title: string; code: string; description: string | null } | null;
    form: {
        title: string;
        instructions: string | null;
        sections: { key: string; title: string; fields: Field[] }[];
    };
    guardianAgeThreshold: number;
    authenticated: boolean;
};

function isBelowThreshold(date: string, threshold: number): boolean {
    if (!date) return false;
    const birth = new Date(`${date}T00:00:00`);
    const today = new Date();
    let age = today.getFullYear() - birth.getFullYear();
    const month = today.getMonth() - birth.getMonth();
    if (month < 0 || (month === 0 && today.getDate() < birth.getDate())) age -= 1;
    return age < threshold;
}

export default function IntakePage({
    tenant,
    intake,
    campus,
    program,
    form,
    guardianAgeThreshold,
    authenticated,
}: Props) {
    const start = useForm({
        intake_code: intake.code,
        given_name: '',
        middle_name: '',
        family_name: '',
        birth_date: '',
        phone: '',
        country: '',
        region: '',
        city: '',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        start.post(`/admissions/${tenant.slug}/applications`);
    };
    return (
        <AdmissionsPublicShell title={intake.name} tenant={tenant}>
            <PageHeader
                title={intake.name}
                description={
                    intake.public_description ??
                    'Review the admission information and begin a secure application draft.'
                }
            />
            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="grid content-start gap-5">
                    <section className="border-border bg-surface rounded-2xl border p-5">
                        <h2 className="text-lg font-semibold">Program details</h2>
                        <p className="text-text-secondary mt-2 text-sm">
                            {program ? `${program.title} (${program.code})` : 'General admission'}
                            {campus ? ` · ${campus.name}${campus.city ? `, ${campus.city}` : ''}` : ''}
                        </p>
                        {program?.description ? (
                            <p className="text-text-secondary mt-3 text-sm leading-6">
                                {program.description}
                            </p>
                        ) : null}
                        <dl className="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-text-muted">Cycle</dt>
                                <dd className="mt-1 font-medium">{intake.cycle ?? 'Current intake'}</dd>
                            </div>
                            <div>
                                <dt className="text-text-muted">Deadline</dt>
                                <dd className="mt-1 font-medium">
                                    {intake.application_deadline
                                        ? new Date(intake.application_deadline).toLocaleString()
                                        : 'Not specified'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-text-muted">Application fee</dt>
                                <dd className="mt-1 font-medium">
                                    {intake.application_fee_amount
                                        ? `${intake.application_fee_currency ?? ''} ${intake.application_fee_amount}`
                                        : 'No application fee'}
                                </dd>
                            </div>
                        </dl>
                    </section>
                    <section className="border-border bg-surface rounded-2xl border p-5">
                        <h2 className="text-lg font-semibold">{form.title}</h2>
                        {form.instructions ? (
                            <p className="text-text-secondary mt-2 text-sm leading-6">{form.instructions}</p>
                        ) : null}
                        <div className="mt-4 grid gap-5">
                            {form.sections.map((section) => (
                                <div key={section.key}>
                                    <h3 className="font-semibold">{section.title}</h3>
                                    <ul className="text-text-secondary mt-2 grid gap-2 text-sm">
                                        {section.fields.map((field) => (
                                            <li key={field.key}>
                                                {field.label}
                                                {field.required ? ' · Required' : ' · Optional'}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    </section>
                </div>
                <aside className="border-border bg-surface h-fit rounded-2xl border p-5 sm:p-6">
                    <h2 className="text-lg font-semibold">Start your application</h2>
                    {authenticated ? (
                        <>
                            <p className="text-text-secondary mt-2 text-sm">
                                Your draft is private to your account. You can save progress and return before
                                the deadline.
                            </p>
                            <form onSubmit={submit} className="mt-5 grid gap-4">
                                <TextField
                                    label="Given name"
                                    name="given_name"
                                    autoComplete="given-name"
                                    required
                                    value={start.data.given_name}
                                    onChange={(e) => start.setData('given_name', e.target.value)}
                                    error={start.errors.given_name}
                                />
                                <TextField
                                    label="Middle name (optional)"
                                    name="middle_name"
                                    autoComplete="additional-name"
                                    value={start.data.middle_name}
                                    onChange={(e) => start.setData('middle_name', e.target.value)}
                                />
                                <TextField
                                    label="Family name"
                                    name="family_name"
                                    autoComplete="family-name"
                                    value={start.data.family_name}
                                    onChange={(e) => start.setData('family_name', e.target.value)}
                                    error={start.errors.family_name}
                                />
                                <TextField
                                    label="Date of birth"
                                    name="birth_date"
                                    type="date"
                                    required
                                    value={start.data.birth_date}
                                    onChange={(e) => start.setData('birth_date', e.target.value)}
                                    error={start.errors.birth_date}
                                />
                                {isBelowThreshold(start.data.birth_date, guardianAgeThreshold) ? (
                                    <p className="border-warning/40 bg-warning/10 text-text-secondary rounded-lg border p-3 text-sm sm:col-span-2">
                                        Because this applicant is below the institution’s age threshold, a
                                        verified guardian must accept a request and the institution must
                                        confirm the relationship before submission.
                                    </p>
                                ) : null}
                                <TextField
                                    label="Phone"
                                    name="phone"
                                    type="tel"
                                    autoComplete="tel"
                                    value={start.data.phone}
                                    onChange={(e) => start.setData('phone', e.target.value)}
                                    error={start.errors.phone}
                                />
                                <button
                                    disabled={start.processing}
                                    className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                                >
                                    {start.processing ? 'Preparing…' : 'Continue securely'}
                                </button>
                            </form>
                        </>
                    ) : (
                        <>
                            <p className="text-text-secondary mt-2 text-sm">
                                Sign in to continue, or create an account if this institution allows open
                                registration.
                            </p>
                            <div className="mt-5 flex flex-wrap gap-3">
                                <Link
                                    className="bg-brand-action inline-flex min-h-11 items-center rounded-lg px-4 text-sm font-semibold text-white"
                                    href="/login"
                                >
                                    Sign in
                                </Link>
                                <Link
                                    className="border-border inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                                    href={`/admissions/${tenant.slug}/register`}
                                >
                                    Create account
                                </Link>
                            </div>
                        </>
                    )}
                </aside>
            </div>
        </AdmissionsPublicShell>
    );
}
