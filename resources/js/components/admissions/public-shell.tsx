import { Head, Link, usePage } from '@inertiajs/react';

type Props = { title: string; tenant: { slug: string; name: string }; children: React.ReactNode };

export function AdmissionsPublicShell({ title, tenant, children }: Props) {
    const { flash } = usePage<{ flash: { status?: string } }>().props;
    return (
        <div className="bg-canvas text-text-primary min-h-screen">
            <Head title={`${title} · ${tenant.name}`} />
            <a className="skip-link" href="#main-content">
                Skip to content
            </a>
            <header className="bg-navy-950 border-b border-white/10 text-white">
                <div className="mx-auto flex min-h-18 max-w-screen-xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                    <Link
                        href={`/admissions/${tenant.slug}`}
                        className="flex items-center gap-3 rounded focus-visible:outline-2 focus-visible:outline-cyan-300"
                    >
                        <span className="grid size-10 place-items-center rounded-xl border border-cyan-300/40 text-sm font-bold text-cyan-200">
                            TM
                        </span>
                        <span>
                            <span className="block text-sm font-semibold">{tenant.name}</span>
                            <span className="block text-xs text-slate-300">Admissions</span>
                        </span>
                    </Link>
                    <nav aria-label="Admissions" className="flex items-center gap-2">
                        <Link
                            className="min-h-11 rounded-lg px-3 py-3 text-sm hover:bg-white/10"
                            href={`/admissions/${tenant.slug}`}
                        >
                            Open intakes
                        </Link>
                        <Link
                            className="min-h-11 rounded-lg px-3 py-3 text-sm hover:bg-white/10"
                            href={`/admissions/${tenant.slug}/my-applications`}
                        >
                            My applications
                        </Link>
                    </nav>
                </div>
            </header>
            <main
                id="main-content"
                className="mx-auto w-full max-w-screen-xl px-4 py-8 sm:px-6 sm:py-12 lg:px-8"
            >
                {flash.status ? (
                    <div
                        role="status"
                        className="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
                    >
                        {flash.status}
                    </div>
                ) : null}
                {children}
            </main>
            <footer className="border-border border-t">
                <div className="text-text-secondary mx-auto max-w-screen-xl px-4 py-6 text-sm sm:px-6 lg:px-8">
                    {tenant.name} · secure admissions workspace
                </div>
            </footer>
        </div>
    );
}
