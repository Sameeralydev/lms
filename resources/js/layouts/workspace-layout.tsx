import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type CSSProperties, type ReactNode } from 'react';
import type { AcademicWorkspaceConfig } from '@/types/academic';

type SharedProps = {
    auth: {
        user: { id: number; name: string; email: string } | null;
        platformPermissions: string[];
        tenant: null | {
            id: number;
            slug: string;
            name: string;
            status: string;
            membershipId: number;
            campusId: number | null;
            roles: string[];
            permissions: string[];
        };
    };
    theme: string;
    flash: { status?: string };
    branding: null | {
        displayName: string | null;
        primaryColor: string;
        accentColor: string;
        logoUrl: string | null;
    };
    academicConfig: AcademicWorkspaceConfig | null;
    workspaces: { slug: string; name: string; status: string }[];
};

type WorkspaceLayoutProps = { children: ReactNode; title: string; area: 'platform' | 'tenant' };

const themeOptions = [
    ['executive-navy', 'Executive Navy'],
    ['academic-emerald', 'Academic Emerald'],
    ['modern-indigo', 'Modern Indigo'],
    ['warm-ivory', 'Warm Ivory'],
    ['graphite-dark', 'Graphite Dark'],
    ['midnight-violet', 'Midnight Violet'],
];

const platformNavigation = [
    ['Overview', '/platform/dashboard', 'platform.dashboard.view'],
    ['Institutions', '/platform/tenants', 'platform.tenants.view'],
    ['Operators', '/platform/operators', 'platform.operators.manage'],
    ['Audit history', '/platform/audit', 'platform.audit.view'],
    ['Operations', '/platform/settings', 'platform.dashboard.view'],
];

const tenantNavigation = [
    ['Overview', 'dashboard', 'tenant.view'],
    ['Institution profile', 'institution', 'tenant.view'],
    ['Campuses', 'campuses', 'campus.view'],
    ['Members', 'members', 'members.view'],
    ['Roles & permissions', 'roles', 'roles.view'],
    ['Branding & theme', 'branding', 'tenant.view'],
    ['Domains', 'domains', 'tenant.view'],
    ['Settings', 'settings', 'tenant.view'],
    ['Audit history', 'audit', 'audit.view'],
    ['Academics', 'academics', 'academic.view'],
    ['Subjects & courses', 'academics/catalog', 'academic.view'],
    ['Curriculum', 'academics/curriculum', 'academic.curriculum.view'],
    ['Policy profiles', 'academics/policies', 'academic.view'],
    ['Quality & documents', 'academics/quality', 'academic.view'],
    ['Academic history', 'academics/audit', 'academic.audit.view'],
    ['Gradebooks', 'academics/grades', 'academic.grades.book.view'],
    ['GPA & standing', 'academics/gpa', 'academic.records.gpa.manage'],
    ['Official records', 'academics/records', 'academic.records.issue'],
    ['Report-card comments', 'academics/report-card-comments', 'academic.grades.book.manage'],
    ['Academic reports', 'academics/reports', 'academic.records.view-all'],
    ['My grades', 'academic-records/my-grades', 'academic.grades.view-own'],
    ['Learner grades', 'academic-records/guardian-grades', 'academic.records.guardian.view'],
    ['My progress', 'academic-records/my-progress', 'academic.grades.view-own'],
    ['My academic records', 'academic-records', 'academic.records.view-own'],
    ['Timetables', 'academics/timetables', 'academic.view'],
    ['Examinations', 'academics/examinations', 'exams.view'],
    ['My examination duties', 'examination-duties', 'exams.invigilators.view-own'],
    ['My schedule', 'schedule', 'enrollment.student.view'],
    ['Instructor schedule', 'instructor/schedule', 'enrollment.instructor.roster.view'],
    ['Course delivery', 'learning', 'learning.delivery.view'],
    ['Admissions', 'admissions', 'admissions.view'],
    ['Student registry', 'sis', 'sis.view'],
    ['Course registration', 'registration', 'enrollment.student.view'],
    ['My assignments', 'my-assignments', 'assignments.view'],
    ['Assignment notifications', 'assignment-notifications', 'assignments.view'],
    ['Assignment management', 'assignments', 'assignments.submission.view'],
    ['Assessments', 'assessments', 'assessment.quiz.view'],
    ['Online examinations', 'exams', 'assessment.quiz.view'],
    ['Examiner marking', 'academics/examinations/marking', 'exams.view'],
    ['My practical & oral exams', 'examinations/my', 'exams.student.view-own'],
    ['My examination requests', 'examinations/my-governance', 'exams.student.view-own'],
    ['Candidate governance', 'academics/examinations/candidate-governance', 'exams.eligibility.manage'],
    ['Result management', 'academics/examinations/results', 'exams.results.compile'],
    ['My examination results', 'examinations/results', 'exams.student.view-own'],
    ['Extension requests', 'assignments/extensions', 'assignments.extension.review'],
    ['Enrollment administration', 'registrar/enrollment', 'enrollment.registrar.view'],
    ['Attendance operations', 'attendance', 'attendance.view'],
    ['My attendance', 'attendance/my', 'attendance.student.view'],
    ['Family attendance', 'attendance/guardian', 'attendance.guardian.view'],
];

export function WorkspaceLayout({ children, title, area }: WorkspaceLayoutProps) {
    const page = usePage<SharedProps>();
    const { auth, theme, flash } = page.props;
    const { branding, workspaces, academicConfig } = page.props;
    const [menuOpen, setMenuOpen] = useState(false);
    const currentTenant = auth.tenant;
    const enabledAcademicNavigation = tenantNavigation.filter(([, target]) => {
        if (
            target === 'academics/catalog' ||
            target === 'academics/policies' ||
            target === 'academics/curriculum' ||
            target === 'academics/grades'
        ) {
            return academicConfig?.enabledFeatures.catalog ?? false;
        }
        if (target === 'academics/examinations') {
            return academicConfig?.enabledFeatures.calendar ?? false;
        }
        if (target === 'academics/quality') {
            return academicConfig?.enabledFeatures.quality ?? false;
        }
        if (target === 'sis') {
            return academicConfig?.enabledFeatures.student_information ?? false;
        }

        return true;
    });
    const scopedTenantNavigation = currentTenant?.campusId
        ? enabledAcademicNavigation
              .filter(
                  ([, target]) =>
                      ![
                          'academics/catalog',
                          'academics/policies',
                          'academics/quality',
                          'academics/audit',
                      ].includes(target),
              )
              .map(([label, target, permission]) =>
                  target === 'academics'
                      ? [label, 'academics/calendar', permission]
                      : [label, target, permission],
              )
        : enabledAcademicNavigation;
    const navigation =
        area === 'platform'
            ? platformNavigation.filter(([, , permission]) => auth.platformPermissions.includes(permission))
            : scopedTenantNavigation.filter(([, target, permission]) =>
                  target === 'dashboard'
                      ? currentTenant !== null
                      : target === 'academic-records'
                        ? currentTenant?.permissions.some((key) =>
                              ['academic.records.view-own', 'academic.records.guardian.view'].includes(key),
                          )
                        : currentTenant?.permissions.includes(permission),
              );
    const urlFor = (target: string) =>
        area === 'platform'
            ? target
            : `/t/${currentTenant?.slug}/${target === 'dashboard' ? 'dashboard' : target}`;

    const themeStyle =
        branding && area === 'tenant'
            ? ({
                  '--color-tenant-brand': branding.primaryColor,
                  '--color-tenant-accent': branding.accentColor,
              } as CSSProperties)
            : undefined;
    return (
        <div data-theme={theme} style={themeStyle} className="bg-canvas text-text-primary min-h-screen">
            <Head
                title={`${title} · ${area === 'platform' ? 'Platform' : (currentTenant?.name ?? 'Institution')}`}
            />
            <a className="skip-link" href="#main-content">
                Skip to content
            </a>
            <div className="min-h-screen lg:grid lg:grid-cols-[17rem_minmax(0,1fr)]">
                <aside className="bg-navy-950 text-white lg:sticky lg:top-0 lg:flex lg:h-screen lg:flex-col">
                    <div className="flex min-h-18 items-center justify-between border-b border-white/10 px-5">
                        <Link
                            href={
                                area === 'platform'
                                    ? '/platform/dashboard'
                                    : currentTenant
                                      ? `/t/${currentTenant.slug}/dashboard`
                                      : '/workspaces'
                            }
                            className="flex min-w-0 flex-1 items-center gap-3 rounded focus-visible:outline-2 focus-visible:outline-cyan-300"
                        >
                            {branding?.logoUrl && area === 'tenant' ? (
                                <img
                                    src={branding.logoUrl}
                                    alt=""
                                    className="size-10 rounded-xl bg-white object-contain p-1"
                                />
                            ) : (
                                <span className="grid size-10 place-items-center rounded-xl border border-cyan-300/40 bg-white/5 text-sm font-bold text-cyan-200">
                                    TM
                                </span>
                            )}
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-semibold">
                                    {area === 'tenant'
                                        ? (branding?.displayName ?? currentTenant?.name ?? 'TechMentors.PK')
                                        : 'TechMentors.PK'}
                                </span>
                                <span className="block truncate text-xs text-slate-300">
                                    {area === 'platform'
                                        ? 'Platform control'
                                        : (currentTenant?.name ?? 'Institution access')}
                                </span>
                            </span>
                        </Link>
                        <button
                            type="button"
                            className="min-h-11 min-w-11 rounded-lg border border-white/20 lg:hidden"
                            aria-expanded={menuOpen}
                            aria-controls="workspace-navigation"
                            onClick={() => setMenuOpen(!menuOpen)}
                            aria-label={menuOpen ? 'Close menu' : 'Open menu'}
                        >
                            {menuOpen ? 'Close' : 'Menu'}
                        </button>
                    </div>
                    <nav
                        id="workspace-navigation"
                        aria-label="Workspace"
                        className={`${menuOpen ? 'grid' : 'hidden'} gap-1 px-3 py-4 lg:grid`}
                    >
                        {navigation.map(([label, target]) => (
                            <Link
                                key={target}
                                href={urlFor(target)}
                                className={`bg-navy-950 hover:bg-navy-900 min-h-11 rounded-lg px-3 py-3 text-sm font-medium transition-colors ${page.url.startsWith(urlFor(target)) ? 'bg-navy-900 text-white' : 'text-slate-300'}`}
                                onClick={() => setMenuOpen(false)}
                            >
                                {label}
                            </Link>
                        ))}
                        {area === 'tenant' && (
                            <Link
                                href="/workspaces"
                                className="bg-navy-950 hover:bg-navy-900 min-h-11 rounded-lg px-3 py-3 text-sm font-medium text-slate-300 transition-colors"
                            >
                                My institutions
                            </Link>
                        )}
                    </nav>
                    <div className="bg-navy-950 mt-auto hidden p-4 text-xs text-slate-400 lg:block">
                        Platform administration is separated from institution workspaces.
                    </div>
                </aside>

                <div className="min-w-0">
                    <header className="border-border bg-surface sticky top-0 z-20 flex min-h-18 flex-wrap items-center justify-between gap-3 border-b px-4 py-3 sm:px-6 lg:px-10">
                        <div className="min-w-0">
                            <p className="text-text-muted text-xs font-semibold tracking-wider uppercase">
                                {area === 'platform'
                                    ? 'Platform control center'
                                    : (currentTenant?.name ?? 'Institution access')}
                            </p>
                            <p className="truncate text-sm font-semibold">{auth.user?.name}</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <label className="sr-only" htmlFor="theme-choice">
                                Choose theme
                            </label>
                            <select
                                id="theme-choice"
                                className="border-border bg-surface min-h-11 max-w-44 rounded-lg border px-3 text-xs"
                                value={theme}
                                onChange={(event) =>
                                    router.post(
                                        '/workspace/theme',
                                        {
                                            theme: event.currentTarget.value,
                                            return_to: `${window.location.pathname}${window.location.search}${window.location.hash}`,
                                        },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {themeOptions.map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                            {workspaces.length > 1 ? (
                                <>
                                    <label className="sr-only" htmlFor="tenant-choice">
                                        Switch institution
                                    </label>
                                    <select
                                        id="tenant-choice"
                                        className="border-border bg-surface min-h-11 max-w-48 rounded-lg border px-3 text-xs"
                                        value={currentTenant?.slug ?? ''}
                                        onChange={(event) =>
                                            router.post('/workspaces/switch', {
                                                tenant: event.currentTarget.value,
                                            })
                                        }
                                    >
                                        <option value="" disabled>
                                            Choose institution
                                        </option>
                                        {workspaces.map((workspace) => (
                                            <option key={workspace.slug} value={workspace.slug}>
                                                {workspace.name}
                                            </option>
                                        ))}
                                    </select>
                                </>
                            ) : null}
                            <form method="post" action="/logout">
                                <input
                                    type="hidden"
                                    name="_token"
                                    value={
                                        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                                            ?.content ?? ''
                                    }
                                />
                                <button
                                    className="border-border hover:bg-surface-soft min-h-11 rounded-lg border px-3 text-sm font-medium"
                                    type="submit"
                                >
                                    Sign out
                                </button>
                            </form>
                        </div>
                    </header>
                    <main
                        id="main-content"
                        className="mx-auto w-full max-w-screen-2xl px-4 py-7 sm:px-6 sm:py-9 lg:px-10"
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
                </div>
            </div>
        </div>
    );
}
