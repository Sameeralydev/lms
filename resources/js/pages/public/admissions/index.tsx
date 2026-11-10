import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AdmissionsPublicShell } from '@/components/admissions/public-shell';
import { PageHeader } from '@/components/ui/page-header';
import { TextField } from '@/components/ui/text-field';

type Intake = {
    id: number;
    code: string;
    name: string;
    cycle: string | null;
    delivery_mode: string;
    application_deadline: string | null;
    public_description: string | null;
    campus: { name: string } | null;
    program: { title: string; code: string } | null;
};
type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[] };

export default function AdmissionsIndex({
    tenant,
    institution,
    intakes,
    inquiriesEnabled,
}: {
    tenant: { slug: string; name: string };
    institution: {
        officialName: string;
        type: string;
        website: string | null;
        contactEmail: string | null;
        city: string | null;
    };
    intakes: Page<Intake>;
    inquiriesEnabled: boolean;
}) {
    const inquiry = useForm({ name: '', email: '', phone: '', intake_code: '', message: '', consent: false });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        inquiry.post(`/admissions/${tenant.slug}/inquiries`, {
            preserveScroll: true,
            onSuccess: () => inquiry.reset(),
        });
    };
    return (
        <AdmissionsPublicShell title="Admissions" tenant={tenant}>
            <PageHeader
                title={`Admissions at ${institution.officialName}`}
                description="Review current application opportunities, requirements, and deadlines from the institution."
            />
            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <section aria-labelledby="intakes-heading">
                    <h2 id="intakes-heading" className="mb-4 text-xl font-semibold">
                        Open application opportunities
                    </h2>
                    <div className="grid gap-4">
                        {intakes.data.map((intake) => (
                            <article
                                key={intake.id}
                                className="border-border bg-surface shadow-card rounded-2xl border p-5 sm:p-6"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <p className="text-text-muted text-xs font-semibold tracking-wide uppercase">
                                            {intake.cycle ?? intake.delivery_mode.replaceAll('_', ' ')}
                                        </p>
                                        <h3 className="mt-1 text-xl font-semibold">{intake.name}</h3>
                                        <p className="text-text-secondary mt-2 text-sm">
                                            {intake.program?.title ?? 'General admission'}
                                            {intake.campus ? ` · ${intake.campus.name}` : ''}
                                        </p>
                                    </div>
                                    <span className="bg-surface-soft rounded-full px-3 py-1 text-xs font-medium">
                                        {intake.code}
                                    </span>
                                </div>
                                {intake.public_description ? (
                                    <p className="text-text-secondary mt-4 max-w-3xl text-sm leading-6">
                                        {intake.public_description}
                                    </p>
                                ) : null}
                                <div className="mt-5 flex flex-wrap items-center justify-between gap-3">
                                    <p className="text-text-muted text-sm">
                                        {intake.application_deadline
                                            ? `Apply by ${new Date(intake.application_deadline).toLocaleString()}`
                                            : 'No deadline published'}
                                    </p>
                                    <Link
                                        className="bg-brand-action inline-flex min-h-11 items-center rounded-lg px-4 text-sm font-semibold text-white"
                                        href={`/admissions/${tenant.slug}/intakes/${intake.code}`}
                                    >
                                        View requirements
                                    </Link>
                                </div>
                            </article>
                        ))}
                        {intakes.data.length === 0 ? (
                            <div className="border-border bg-surface rounded-2xl border p-7">
                                <h3 className="font-semibold">No applications are open</h3>
                                <p className="text-text-secondary mt-2 text-sm">
                                    Check again later or contact the admissions office.
                                </p>
                            </div>
                        ) : null}
                    </div>
                    <nav aria-label="Intake pages" className="mt-5 flex flex-wrap gap-2">
                        {intakes.links.map((link, index) =>
                            link.url ? (
                                <Link
                                    key={index}
                                    href={link.url}
                                    className={`rounded-md px-3 py-2 text-xs ${link.active ? 'bg-brand-action text-white' : 'border-border border'}`}
                                >
                                    {link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}
                                </Link>
                            ) : null,
                        )}
                    </nav>
                </section>
                {inquiriesEnabled ? (
                    <aside className="border-border bg-surface h-fit rounded-2xl border p-5 sm:p-6">
                        <h2 className="text-lg font-semibold">Ask the admissions office</h2>
                        <p className="text-text-secondary mt-2 text-sm">
                            Send a question. Your details are used to respond to this inquiry.
                        </p>
                        <form onSubmit={submit} className="mt-5 grid gap-4">
                            <TextField
                                label="Your name"
                                name="name"
                                autoComplete="name"
                                value={inquiry.data.name}
                                onChange={(e) => inquiry.setData('name', e.target.value)}
                                error={inquiry.errors.name}
                            />
                            <TextField
                                label="Email address"
                                name="email"
                                type="email"
                                autoComplete="email"
                                value={inquiry.data.email}
                                onChange={(e) => inquiry.setData('email', e.target.value)}
                                error={inquiry.errors.email}
                            />
                            <TextField
                                label="Phone (optional)"
                                name="phone"
                                type="tel"
                                autoComplete="tel"
                                value={inquiry.data.phone}
                                onChange={(e) => inquiry.setData('phone', e.target.value)}
                                error={inquiry.errors.phone}
                            />
                            <label className="grid gap-2 text-sm font-medium">
                                Question
                                <textarea
                                    className="border-border bg-surface min-h-24 rounded-lg border p-3"
                                    value={inquiry.data.message}
                                    onChange={(e) => inquiry.setData('message', e.target.value)}
                                    maxLength={2000}
                                />
                            </label>
                            <label className="flex items-start gap-3 text-sm">
                                <input
                                    className="mt-1 size-4"
                                    type="checkbox"
                                    checked={inquiry.data.consent}
                                    onChange={(e) => inquiry.setData('consent', e.target.checked)}
                                />
                                <span>I agree the admissions office may use these details to respond.</span>
                            </label>
                            {inquiry.errors.consent ? (
                                <p role="alert" className="text-danger text-sm">
                                    {inquiry.errors.consent}
                                </p>
                            ) : null}
                            <button
                                disabled={inquiry.processing}
                                className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                            >
                                {inquiry.processing ? 'Sending…' : 'Send inquiry'}
                            </button>
                        </form>
                    </aside>
                ) : null}
            </div>
        </AdmissionsPublicShell>
    );
}
