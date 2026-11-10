import { Link } from '@inertiajs/react';
import { AppLayout } from '@/layouts/app-layout';

const capabilities = [
    {
        number: '01',
        title: 'Institution operations',
        description:
            'Manage institution profiles, campuses, memberships, roles, appearance, and audit history.',
    },
    {
        number: '02',
        title: 'Academic planning',
        description: 'Configure academic calendars, courses, programs, curriculum, and timetables.',
    },
    {
        number: '03',
        title: 'Student records and admissions',
        description:
            'Support secure admissions, student records, guardian authority, and enrollment workflows.',
    },
    {
        number: '04',
        title: 'Teaching and learning',
        description: 'Connect course content, attendance, assignments, submissions, feedback, and progress.',
    },
];

export default function Welcome() {
    return (
        <AppLayout title="Institution operations and learning">
            <div className="space-y-16 sm:space-y-20">
                <section
                    aria-labelledby="welcome-heading"
                    className="bg-navy-950 shadow-navy-950/10 relative isolate overflow-hidden rounded-[2rem] border border-white/10 px-6 py-12 text-white shadow-2xl sm:px-10 sm:py-16 lg:px-16 lg:py-20"
                >
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute -top-24 -right-16 -z-10 size-80 rounded-full bg-cyan-400/20 blur-3xl"
                    />
                    <div
                        aria-hidden="true"
                        className="bg-brand-blue/30 pointer-events-none absolute -bottom-44 left-1/3 -z-10 size-96 rounded-full blur-3xl"
                    />
                    <div className="max-w-4xl">
                        <p className="mb-6 inline-flex items-center gap-2 rounded-full border border-cyan-200/20 bg-white/5 px-3.5 py-2 text-xs font-semibold tracking-[0.14em] text-cyan-100 uppercase">
                            <span aria-hidden="true" className="size-2 rounded-full bg-cyan-300" />
                            TechMentors.PK · Education operations
                        </p>
                        <h1
                            id="welcome-heading"
                            className="max-w-4xl text-4xl leading-[1.08] font-semibold tracking-tight sm:text-5xl lg:text-7xl"
                        >
                            Bring institution operations and learning into one workspace.
                        </h1>
                        <p className="mt-6 max-w-2xl text-base leading-7 text-slate-200 sm:text-lg sm:leading-8">
                            A secure, role-aware platform for academic planning, student records, admissions,
                            teaching, and day-to-day learning workflows.
                        </p>
                        <div className="mt-9 flex flex-col gap-3 sm:flex-row">
                            <Link
                                href="/login"
                                className="focus-ring text-navy-950 inline-flex min-h-12 items-center justify-center rounded-xl bg-cyan-300 px-6 text-sm font-semibold transition-colors hover:bg-cyan-200"
                            >
                                Sign in to TechMentors
                            </Link>
                            <Link
                                href="/workspaces"
                                className="focus-ring inline-flex min-h-12 items-center justify-center rounded-xl border border-white/20 bg-white/5 px-6 text-sm font-semibold text-white transition-colors hover:bg-white/10"
                            >
                                Open an institution workspace
                            </Link>
                        </div>
                        <p className="mt-5 text-sm text-slate-300">
                            Institution access is membership-based. Sign in to see the workspaces available to
                            your account.
                        </p>
                    </div>
                    <div className="mt-12 grid gap-3 border-t border-white/15 pt-6 text-sm text-slate-200 sm:grid-cols-3">
                        <p>Separate platform and institution administration</p>
                        <p>Campus-aware workspaces and permissions</p>
                        <p>Responsive interfaces with six selectable themes</p>
                    </div>
                </section>

                <section id="capabilities" aria-labelledby="capabilities-heading">
                    <div className="max-w-2xl">
                        <p className="text-brand-link text-xs font-bold tracking-[0.14em] uppercase">
                            Built for education teams
                        </p>
                        <h2
                            id="capabilities-heading"
                            className="text-navy-950 mt-3 text-3xl font-semibold tracking-tight sm:text-4xl"
                        >
                            The work behind better learning.
                        </h2>
                        <p className="text-text-secondary mt-4 text-base leading-7">
                            Teams can work from institution-wide administration to individual teaching and
                            learner tasks, with access controlled by verified roles and relationships.
                        </p>
                    </div>
                    <div className="mt-8 grid gap-4 sm:grid-cols-2">
                        {capabilities.map((capability) => (
                            <article
                                key={capability.number}
                                className="border-border bg-surface rounded-2xl border p-6 shadow-sm transition-shadow hover:shadow-md sm:p-7"
                            >
                                <span className="text-brand-link bg-brand-blue-soft grid size-10 place-items-center rounded-xl text-sm font-bold">
                                    {capability.number}
                                </span>
                                <h3 className="text-navy-950 mt-5 text-lg font-semibold">
                                    {capability.title}
                                </h3>
                                <p className="text-text-secondary mt-2 text-sm leading-6">
                                    {capability.description}
                                </p>
                            </article>
                        ))}
                    </div>
                </section>

                <section className="border-border bg-surface-soft flex flex-col gap-5 rounded-2xl border p-6 sm:flex-row sm:items-center sm:justify-between sm:p-8">
                    <div>
                        <h2 className="text-navy-950 text-xl font-semibold">
                            Ready to return to your institution?
                        </h2>
                        <p className="text-text-secondary mt-2 max-w-2xl text-sm leading-6">
                            Sign in to open your platform administration or choose an institution where you
                            have an active membership.
                        </p>
                    </div>
                    <Link
                        href="/workspaces"
                        className="focus-ring bg-brand-action hover:bg-brand-action-hover inline-flex min-h-12 shrink-0 items-center justify-center rounded-xl px-5 text-sm font-semibold text-white transition-colors"
                    >
                        Find my workspace
                    </Link>
                </section>
            </div>
        </AppLayout>
    );
}
