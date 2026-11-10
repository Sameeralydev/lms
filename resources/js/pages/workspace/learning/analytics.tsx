import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Props = {
    tenant: { slug: string };
    filters: {
        course_id: string;
        instructor_membership_id: string;
        academic_year_id: string;
        publication_status: string;
        updated_from: string;
        updated_to: string;
    };
    courses: { id: number; title: string; code: string }[];
    instructors: { id: number; name: string }[];
    academicYears: { id: number; name: string }[];
    workspaces: {
        data: {
            id: number;
            title: string;
            code: string;
            status: string;
            updated_at: string | null;
            modules_count: number;
            lessons_count: number;
            published_lessons_count: number;
            review_backlog: number;
            active_learners: number;
            course: { title: string; code: string };
            course_version: { title: string; version_number: number };
            campus: { name: string } | null;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    metrics: {
        workspaces: number;
        lessons: number;
        published_lessons: number;
        draft_lessons: number;
        review_backlog: number;
        active_learners: number;
        engaged_learners: number;
        current_completions: number;
        completion_percent: number;
        media_assets: number;
        quarantined_assets: number;
        rejected_assets: number;
        accessibility_gaps: number;
        scheduled_releases: number;
        malformed_external_links: number;
        missing_learning_objectives: number;
        curriculum_unlinked_workspaces: number;
    };
    findings: { kind: string; label: string; lesson: string; workspace: string; url: string }[];
};

const metricNames: [keyof Props['metrics'], string][] = [
    ['workspaces', 'Delivery workspaces'],
    ['lessons', 'Lessons'],
    ['published_lessons', 'Published lessons'],
    ['draft_lessons', 'Draft lessons'],
    ['review_backlog', 'Review backlog'],
    ['active_learners', 'Active learner assignments'],
    ['engaged_learners', 'Learners with recorded progress'],
    ['scheduled_releases', 'Scheduled lesson releases'],
    ['current_completions', 'Current revision completions'],
    ['completion_percent', 'Completion rate'],
    ['media_assets', 'Learning assets'],
    ['quarantined_assets', 'Quarantined assets'],
    ['rejected_assets', 'Rejected assets'],
    ['accessibility_gaps', 'Media accessibility gaps'],
    ['malformed_external_links', 'Malformed or non-HTTPS links'],
    ['missing_learning_objectives', 'Missing learning objectives'],
    ['curriculum_unlinked_workspaces', 'Workspaces without curriculum link'],
];

export default function LearningAnalytics({
    tenant,
    filters,
    courses,
    instructors,
    academicYears,
    workspaces,
    metrics,
    findings,
}: Props) {
    return (
        <WorkspaceLayout title="Learning analytics" area="tenant">
            <Head title="Learning analytics and quality" />
            <PageHeader
                eyebrow="Learning delivery"
                title="Analytics and content quality"
                description="Tenant-scoped counts and quality findings from the current delivery records. Completion means a learner confirmed the currently published lesson revision; it does not imply academic mastery."
            />

            <form
                method="get"
                action={`/t/${tenant.slug}/learning/analytics`}
                className="learning-analytics-filters border-border bg-surface mt-6 grid gap-4 rounded-2xl border p-5"
                aria-label="Filter learning analytics"
            >
                <label className="grid gap-1 text-sm font-medium">
                    Course
                    <select
                        name="course_id"
                        defaultValue={filters.course_id}
                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                    >
                        <option value="">All accessible courses</option>
                        {courses.map((course) => (
                            <option key={course.id} value={course.id}>
                                {course.code} · {course.title}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Instructor
                    <select
                        name="instructor_membership_id"
                        defaultValue={filters.instructor_membership_id}
                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                    >
                        <option value="">All accessible instructors</option>
                        {instructors.map((instructor) => (
                            <option key={instructor.id} value={instructor.id}>
                                {instructor.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Academic session
                    <select
                        name="academic_year_id"
                        defaultValue={filters.academic_year_id}
                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                    >
                        <option value="">All sessions</option>
                        {academicYears.map((year) => (
                            <option key={year.id} value={year.id}>
                                {year.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Publication status
                    <select
                        name="publication_status"
                        defaultValue={filters.publication_status}
                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                    >
                        <option value="all">All statuses</option>
                        <option value="draft">Draft</option>
                        <option value="published">Published</option>
                        <option value="archived">Archived</option>
                    </select>
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Workspace updated from
                    <input
                        type="date"
                        name="updated_from"
                        defaultValue={filters.updated_from}
                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                    />
                </label>
                <label className="grid gap-1 text-sm font-medium">
                    Workspace updated through
                    <input
                        type="date"
                        name="updated_to"
                        defaultValue={filters.updated_to}
                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                    />
                </label>
                <div className="flex flex-wrap items-center gap-3 sm:col-span-2 lg:col-span-3">
                    <button className="bg-brand-action hover:bg-brand-action-hover focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white">
                        Apply filters
                    </button>
                    <Link
                        href={`/t/${tenant.slug}/learning/analytics`}
                        className="focus-ring rounded-lg px-3 py-2 text-sm font-semibold"
                    >
                        Clear filters
                    </Link>
                </div>
            </form>

            <section className="mt-6" aria-labelledby="learning-metrics-heading">
                <h2 id="learning-metrics-heading" className="sr-only">
                    Learning delivery metrics
                </h2>
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {metricNames.map(([key, label]) => (
                        <section
                            key={key}
                            className="border-border bg-surface rounded-xl border p-4"
                            aria-label={label}
                        >
                            <p className="text-text-muted text-sm">{label}</p>
                            <p className="mt-2 text-2xl font-semibold tabular-nums">
                                {metrics[key]}
                                {key === 'completion_percent' ? '%' : ''}
                                {key === 'completion_percent' && (
                                    <progress
                                        className="mt-3 h-2 w-full accent-current"
                                        max={100}
                                        value={metrics.completion_percent}
                                        aria-label={`Current revision completion rate ${metrics.completion_percent}%`}
                                    />
                                )}
                            </p>
                        </section>
                    ))}
                </div>
            </section>

            <section className="mt-8" aria-labelledby="quality-findings-heading">
                <h2 id="quality-findings-heading" className="text-lg font-semibold">
                    Actionable content findings
                </h2>
                {findings.length === 0 ? (
                    <p className="border-border bg-surface mt-3 rounded-xl border p-5 text-sm">
                        No missing objectives, media accessibility gaps, or malformed external links were
                        found in this filtered workspace set.
                    </p>
                ) : (
                    <ul className="mt-3 grid gap-3">
                        {findings.map((finding, index) => (
                            <li
                                key={`${finding.kind}-${index}`}
                                className="border-border bg-surface flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"
                            >
                                <div>
                                    <p className="font-medium">{finding.label}</p>
                                    <p className="text-text-muted mt-1 text-sm">
                                        {finding.workspace} · {finding.lesson}
                                    </p>
                                </div>
                                <Link
                                    href={finding.url}
                                    className="focus-ring rounded-lg px-3 py-2 text-sm font-semibold underline"
                                >
                                    Open lesson to review
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className="mt-8" aria-labelledby="workspace-analytics-heading">
                <h2 id="workspace-analytics-heading" className="text-lg font-semibold">
                    Workspace delivery
                </h2>
                <div className="border-border bg-surface mt-3 overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[760px] border-collapse text-left text-sm">
                        <caption className="sr-only">
                            Filtered course delivery workspace lesson and learner counts
                        </caption>
                        <thead className="bg-surface-soft text-text">
                            <tr>
                                {[
                                    'Workspace',
                                    'Status',
                                    'Modules',
                                    'Lessons',
                                    'Published',
                                    'Review backlog',
                                    'Learners',
                                    'Updated',
                                ].map((header) => (
                                    <th key={header} scope="col" className="px-4 py-3 font-semibold">
                                        {header}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {workspaces.data.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="text-text-muted p-6 text-center">
                                        No delivery workspaces match these filters.
                                    </td>
                                </tr>
                            ) : (
                                workspaces.data.map((workspace) => (
                                    <tr key={workspace.id} className="border-border border-t">
                                        <th scope="row" className="px-4 py-3 font-semibold">
                                            <Link
                                                href={`/t/${tenant.slug}/learning/${workspace.id}`}
                                                className="focus-ring underline"
                                            >
                                                {workspace.course.code} · {workspace.title}
                                            </Link>
                                            <span className="text-text-muted block text-xs font-normal">
                                                {workspace.code} · {workspace.course_version.title}
                                            </span>
                                        </th>
                                        <td className="px-4 py-3 capitalize">{workspace.status}</td>
                                        <td className="px-4 py-3 tabular-nums">{workspace.modules_count}</td>
                                        <td className="px-4 py-3 tabular-nums">{workspace.lessons_count}</td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {workspace.published_lessons_count}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">{workspace.review_backlog}</td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {workspace.active_learners}
                                        </td>
                                        <td className="px-4 py-3">{workspace.updated_at ?? '—'}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
                <nav className="mt-4 flex flex-wrap gap-2" aria-label="Workspace analytics pages">
                    {workspaces.links.map((link, index) => {
                        const label = link.label.replace('&laquo;', '«').replace('&raquo;', '»');

                        return link.url ? (
                            <Link
                                key={`${link.label}-${index}`}
                                href={link.url}
                                aria-current={link.active ? 'page' : undefined}
                                className={`focus-ring rounded-lg border px-3 py-2 text-sm ${link.active ? 'border-brand-action font-semibold' : 'border-border'}`}
                            >
                                {label}
                            </Link>
                        ) : (
                            <span
                                key={`${link.label}-${index}`}
                                className="text-text-muted rounded-lg px-3 py-2 text-sm"
                                aria-disabled="true"
                            >
                                {label}
                            </span>
                        );
                    })}
                </nav>
            </section>
        </WorkspaceLayout>
    );
}
