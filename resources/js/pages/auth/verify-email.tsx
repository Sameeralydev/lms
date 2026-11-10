import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function VerifyEmail({ email }: { email: string }) {
    const form = useForm<Record<string, string>>({});
    const page = usePage<{ flash?: { status?: string } }>();
    const resend = (event: FormEvent) => {
        event.preventDefault();
        form.post('/email/verification-notification');
    };

    return (
        <div className="bg-canvas grid min-h-screen place-items-center px-4 py-10">
            <Head title="Verify email address · TechMentors.PK" />
            <main className="border-border bg-surface shadow-card grid w-full max-w-2xl gap-5 rounded-2xl border p-5 sm:p-8">
                <Link href="/" className="text-brand-link text-sm font-semibold">
                    TechMentors.PK
                </Link>
                <div>
                    <h1 className="text-navy-950 text-3xl font-semibold tracking-tight">
                        Verify your email address
                    </h1>
                    <p className="text-text-secondary mt-2 text-sm leading-6">
                        Confirm that you control this email address before opening applicant records.
                    </p>
                </div>
                <p className="text-text-secondary text-sm leading-6">
                    We sent a signed verification link to <strong>{email}</strong>. Open it in this browser
                    while signed in. The link expires and can be resent below.
                </p>
                {page.props.flash?.status ? (
                    <p role="status" className="text-sm font-medium">
                        {page.props.flash.status}
                    </p>
                ) : null}
                {form.errors.email ? (
                    <p role="alert" className="text-danger text-sm">
                        {form.errors.email}
                    </p>
                ) : null}
                <form onSubmit={resend}>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                    >
                        {form.processing ? 'Sending…' : 'Resend verification email'}
                    </button>
                </form>
            </main>
        </div>
    );
}
