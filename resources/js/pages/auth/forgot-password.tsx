import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/text-field';

export default function ForgotPassword() {
    const form = useForm({ email: '' });
    const status = usePage<{ flash: { status?: string } }>().props.flash?.status;
    return (
        <div className="bg-canvas grid min-h-screen place-items-center px-4 py-10">
            <Head title="Reset password · TechMentors.PK" />
            <main className="border-border bg-surface shadow-card w-full max-w-md rounded-2xl border p-7 sm:p-9">
                <Link href="/login" className="text-brand-link text-sm font-semibold">
                    Back to sign in
                </Link>
                <h1 className="text-navy-950 mt-7 text-3xl font-semibold">Reset your password</h1>
                <p className="text-text-secondary mt-2 text-sm leading-6">
                    Enter your account email. If it matches an account, we will send a time-limited reset
                    link.
                </p>
                {status ? (
                    <p role="status" className="mt-5 rounded-lg bg-cyan-50 p-3 text-sm text-cyan-900">
                        {status}
                    </p>
                ) : null}
                <form
                    className="mt-7 grid gap-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/forgot-password');
                    }}
                >
                    <TextField
                        label="Email address"
                        type="email"
                        name="email"
                        autoComplete="email"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.currentTarget.value)}
                        error={form.errors.email}
                    />
                    <Button type="submit" loading={form.processing}>
                        Send reset link
                    </Button>
                </form>
            </main>
        </div>
    );
}
