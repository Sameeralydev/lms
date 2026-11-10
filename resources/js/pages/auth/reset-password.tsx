import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/text-field';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });
    return (
        <div className="bg-canvas grid min-h-screen place-items-center px-4 py-10">
            <Head title="Choose a new password · TechMentors.PK" />
            <main className="border-border bg-surface shadow-card w-full max-w-md rounded-2xl border p-7 sm:p-9">
                <Link href="/login" className="text-brand-link text-sm font-semibold">
                    TechMentors.PK
                </Link>
                <h1 className="text-navy-950 mt-7 text-3xl font-semibold">Choose a new password</h1>
                <form
                    className="mt-7 grid gap-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/reset-password');
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
                    <TextField
                        label="New password"
                        type="password"
                        name="password"
                        autoComplete="new-password"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.currentTarget.value)}
                        error={form.errors.password}
                        hint="Use at least 12 characters with letters, mixed case, and numbers."
                    />
                    <TextField
                        label="Confirm new password"
                        type="password"
                        name="password_confirmation"
                        autoComplete="new-password"
                        value={form.data.password_confirmation}
                        onChange={(event) => form.setData('password_confirmation', event.currentTarget.value)}
                    />
                    <Button type="submit" loading={form.processing}>
                        Save password
                    </Button>
                </form>
            </main>
        </div>
    );
}
