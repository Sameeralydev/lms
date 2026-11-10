import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/text-field';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });
    const status = usePage<{ flash: { status?: string } }>().props.flash?.status;
    return (
        <div className="bg-canvas grid min-h-screen place-items-center px-4 py-10">
            <Head title="Sign in · TechMentors.PK" />
            <main className="border-border bg-surface shadow-card w-full max-w-md rounded-2xl border p-7 sm:p-9">
                <Link href="/" className="text-brand-link text-sm font-semibold">
                    TechMentors.PK
                </Link>
                <h1 className="text-navy-950 mt-7 text-3xl font-semibold tracking-tight">Welcome back</h1>
                <p className="text-text-secondary mt-2 text-sm">
                    Sign in to your platform or institution workspace.
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
                        form.post('/login');
                    }}
                >
                    <TextField
                        label="Email address"
                        type="email"
                        name="email"
                        autoComplete="username"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.currentTarget.value)}
                        error={form.errors.email}
                    />
                    <TextField
                        label="Password"
                        type="password"
                        name="password"
                        autoComplete="current-password"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.currentTarget.value)}
                        error={form.errors.password}
                    />
                    <label className="text-text-secondary inline-flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.remember}
                            onChange={(event) => form.setData('remember', event.currentTarget.checked)}
                        />
                        Keep me signed in
                    </label>
                    <Button type="submit" loading={form.processing}>
                        Sign in
                    </Button>
                    <Link
                        href="/forgot-password"
                        className="text-brand-link text-center text-sm font-medium hover:underline"
                    >
                        Forgot your password?
                    </Link>
                </form>
            </main>
        </div>
    );
}
