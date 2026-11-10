import { Head, Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type AppLayoutProps = {
    children: ReactNode;
    title: string;
};

export function AppLayout({ children, title }: AppLayoutProps) {
    const [menuOpen, setMenuOpen] = useState(false);

    return (
        <div className="bg-canvas text-text-primary min-h-screen">
            <Head title={title} />
            <a className="skip-link" href="#main-content">
                Skip to content
            </a>
            <header className="bg-navy-950 border-b border-white/10 text-white">
                <div className="mx-auto flex min-h-18 max-w-screen-xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                    <Link
                        href="/"
                        className="group inline-flex items-center gap-3 rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-cyan-300"
                    >
                        <span className="grid size-10 place-items-center rounded-xl border border-cyan-300/40 bg-white/5 text-sm font-bold tracking-tight text-cyan-200">
                            TM
                        </span>
                        <span className="leading-tight">
                            <span className="block text-sm font-semibold tracking-wide">TechMentors.PK</span>
                            <span className="block text-xs text-slate-300">Learning platform</span>
                        </span>
                    </Link>

                    <button
                        aria-controls="primary-navigation"
                        aria-expanded={menuOpen}
                        aria-label={menuOpen ? 'Close navigation' : 'Open navigation'}
                        className="inline-flex size-11 items-center justify-center rounded-lg border border-white/20 text-white transition-colors hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 lg:hidden"
                        onClick={() => setMenuOpen((open) => !open)}
                        type="button"
                    >
                        <span aria-hidden="true" className="grid gap-1.5">
                            <span className="h-0.5 w-5 rounded bg-current" />
                            <span className="h-0.5 w-5 rounded bg-current" />
                            <span className="h-0.5 w-5 rounded bg-current" />
                        </span>
                    </button>

                    <nav
                        id="primary-navigation"
                        aria-label="Primary"
                        className={`${menuOpen ? 'grid' : 'hidden'} bg-navy-950 absolute inset-x-0 top-[4.5rem] z-10 gap-1 border-b border-white/10 px-4 py-3 lg:static lg:flex lg:items-center lg:border-0 lg:bg-transparent lg:p-0`}
                    >
                        <Link
                            className="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium text-white transition-colors hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300"
                            onClick={() => setMenuOpen(false)}
                            href="#capabilities"
                        >
                            Capabilities
                        </Link>
                        <Link
                            href="/workspaces"
                            className="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium text-white transition-colors hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300"
                            onClick={() => setMenuOpen(false)}
                        >
                            Institution access
                        </Link>
                        <Link
                            href="/login"
                            className="bg-brand-action hover:bg-brand-action-hover inline-flex min-h-11 items-center justify-center rounded-lg px-4 text-sm font-semibold text-white transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300"
                            onClick={() => setMenuOpen(false)}
                        >
                            Sign in
                        </Link>
                    </nav>
                </div>
            </header>

            <main
                id="main-content"
                className="mx-auto w-full max-w-screen-xl px-4 py-10 sm:px-6 sm:py-14 lg:px-8 lg:py-16"
            >
                {children}
            </main>

            <footer className="border-border bg-surface border-t">
                <div className="text-text-secondary mx-auto flex max-w-screen-xl flex-col gap-2 px-4 py-6 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                    <p className="text-text-primary font-semibold">TechMentors.PK</p>
                    <p>Connected tools for institution operations and learning.</p>
                </div>
            </footer>
        </div>
    );
}
