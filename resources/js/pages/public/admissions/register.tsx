import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AdmissionsPublicShell } from '@/components/admissions/public-shell';
import { PageHeader } from '@/components/ui/page-header';
import { TextField } from '@/components/ui/text-field';

export default function RegisterPage({ tenant }: { tenant: { slug: string; name: string } }) {
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/admissions/${tenant.slug}/register`);
    };
    return (
        <AdmissionsPublicShell title="Create applicant account" tenant={tenant}>
            <div className="mx-auto max-w-xl">
                <PageHeader
                    title="Create your applicant account"
                    description="One account can be used for applications to participating institutions."
                />
                <form
                    onSubmit={submit}
                    className="border-border bg-surface grid gap-5 rounded-2xl border p-5 sm:p-7"
                >
                    <TextField
                        label="Full name"
                        name="name"
                        autoComplete="name"
                        required
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                    />
                    <TextField
                        label="Email address"
                        name="email"
                        type="email"
                        autoComplete="email"
                        required
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        error={form.errors.email}
                    />
                    <TextField
                        label="Password"
                        name="password"
                        type="password"
                        autoComplete="new-password"
                        required
                        minLength={12}
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                        error={form.errors.password}
                        hint="Use at least 12 characters."
                    />
                    <TextField
                        label="Confirm password"
                        name="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        required
                        value={form.data.password_confirmation}
                        onChange={(e) => form.setData('password_confirmation', e.target.value)}
                    />
                    <p className="text-text-secondary text-xs leading-5">
                        The response is the same whether an account exists or not. New applicants must verify
                        their email address before opening application records.
                    </p>
                    <button
                        disabled={form.processing}
                        className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                    >
                        {form.processing ? 'Creating…' : 'Create account'}
                    </button>
                    <p className="text-text-secondary text-sm">
                        Already have an account?{' '}
                        <Link className="text-brand-link font-semibold" href="/login">
                            Sign in
                        </Link>
                    </p>
                </form>
            </div>
        </AdmissionsPublicShell>
    );
}
