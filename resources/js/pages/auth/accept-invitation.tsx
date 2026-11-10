import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/text-field';

type PageProps = {
    token: string;
    email: string;
    tenantName: string;
    signed: boolean;
    signedUrl: string;
    auth: { user: { email: string } | null };
};
export default function AcceptInvitation({ email, tenantName, signed, signedUrl }: Omit<PageProps, 'auth'>) {
    const auth = usePage<PageProps>().props.auth;
    const form = useForm({ name: '', password: '', password_confirmation: '' });
    const sameAccount = auth.user?.email.toLowerCase() === email.toLowerCase();
    return (
        <div className="bg-canvas grid min-h-screen place-items-center px-4 py-10">
            <Head title={`Join ${tenantName} · TechMentors.PK`} />
            <main className="border-border bg-surface shadow-card w-full max-w-lg rounded-2xl border p-7 sm:p-9">
                <Link href="/" className="text-brand-link text-sm font-semibold">
                    TechMentors.PK
                </Link>
                <p className="text-text-muted mt-7 text-xs font-semibold tracking-wider uppercase">
                    Institution invitation
                </p>
                <h1 className="text-navy-950 mt-2 text-3xl font-semibold">Join {tenantName}</h1>
                <p className="text-text-secondary mt-3 text-sm leading-6">
                    This invitation is for <strong>{email}</strong>. It expires after seven days and can only
                    be accepted once.
                </p>
                {!signed ? (
                    <div role="alert" className="mt-6 rounded-lg bg-red-50 p-4 text-sm text-red-900">
                        This invitation link is invalid or expired.
                    </div>
                ) : sameAccount ? (
                    <form
                        className="mt-7"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(signedUrl);
                        }}
                    >
                        <Button type="submit" loading={form.processing}>
                            Accept invitation
                        </Button>
                    </form>
                ) : auth.user ? (
                    <div className="mt-7 grid gap-4">
                        <p className="text-text-secondary text-sm">
                            Sign out and sign in with the invited email address to continue.
                        </p>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="text-brand-link text-left text-sm font-semibold"
                        >
                            Sign out
                        </Link>
                    </div>
                ) : (
                    <form
                        className="mt-7 grid gap-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(signedUrl);
                        }}
                    >
                        <TextField
                            label="Your name"
                            name="name"
                            autoComplete="name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.currentTarget.value)}
                            error={form.errors.name}
                        />
                        <TextField
                            label="Create password"
                            type="password"
                            name="password"
                            autoComplete="new-password"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.currentTarget.value)}
                            error={form.errors.password}
                            hint="At least 12 characters, with letters, mixed case, and numbers."
                        />
                        <TextField
                            label="Confirm password"
                            type="password"
                            name="password_confirmation"
                            autoComplete="new-password"
                            value={form.data.password_confirmation}
                            onChange={(event) =>
                                form.setData('password_confirmation', event.currentTarget.value)
                            }
                        />
                        <Button type="submit" loading={form.processing}>
                            Create account and join
                        </Button>
                    </form>
                )}
            </main>
        </div>
    );
}
