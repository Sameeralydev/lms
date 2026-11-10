import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Option = { id: number; code?: string; title?: string; name?: string };
type Props = {
    tenantSlug: string;
    metrics: {
        curricula: number;
        by_status: Record<string, number>;
        versions_by_status: Record<string, number>;
    };
    filters: Record<string, string | number | null>;
    options: Record<string, Option[]>;
    curricula: {
        data: {
            id: number;
            code: string;
            title: string;
            status: string;
            created_by: number | null;
            program: { code: string; title: string };
            academic_year: { code: string; name: string } | null;
            latest_version: {
                id: number;
                version_number: number;
                status: string;
                reviewer_user_id: number | null;
            } | null;
            versions_count: number;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
    };
};

const field = 'border-border bg-surface min-h-11 rounded-lg border px-3 text-sm';

export default function CurriculumAnalytics({ tenantSlug, metrics, filters, options, curricula }: Props) {
    const base = `/t/${tenantSlug}/academics/curriculum`;
    const [values, setValues] = useState<Record<string, string>>(
        Object.fromEntries(
            Object.entries(filters).map(([key, value]) => [key, value == null ? '' : String(value)]),
        ),
    );
    const set = (key: string, value: string) => setValues((current) => ({ ...current, [key]: value }));
    const apply = () =>
        router.get(
            `${base}/analytics`,
            Object.fromEntries(Object.entries(values).filter(([, value]) => value !== '')),
            { preserveState: true, preserveScroll: true },
        );
    const reset = () => router.get(`${base}/analytics`);
    const select = (key: string, label: string, items: Option[]) => (
        <label className="grid min-w-0 gap-1 text-xs font-medium" key={key}>
            {label}
            <select
                className={`${field} w-full min-w-0`}
                value={values[key] ?? ''}
                onChange={(event) => set(key, event.currentTarget.value)}
            >
                <option value="">All</option>
                {items.map((item) => (
                    <option key={item.id} value={item.id}>
                        {item.code ? `${item.code} · ` : ''}
                        {item.title ?? item.name}
                    </option>
                ))}
            </select>
        </label>
    );

    return (
        <WorkspaceLayout title="Curriculum analytics" area="tenant">
            <Head title="Curriculum analytics" />
            <div className="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-6">
                <PageHeader
                    title="Curriculum analytics"
                    description="Explore saved curriculum plans and version workflows using institution-scoped records."
                    actions={
                        <Link className={`${field} inline-flex items-center font-semibold`} href={base}>
                            Curriculum workspace
                        </Link>
                    }
                />
                <section
                    aria-label="Curriculum totals"
                    className="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <Metric label="Matching curricula" value={metrics.curricula} />
                    {Object.entries(metrics.by_status).map(([status, count]) => (
                        <Metric key={status} label={`${status} curricula`} value={count} />
                    ))}
                    {Object.entries(metrics.versions_by_status).map(([status, count]) => (
                        <Metric
                            key={`version-${status}`}
                            label={`${status.replace('_', ' ')} versions`}
                            value={count}
                        />
                    ))}
                </section>
                <section
                    className="border-border bg-surface min-w-0 rounded-2xl border p-5 shadow-sm"
                    aria-labelledby="filters-title"
                >
                    <h2 id="filters-title" className="text-lg font-semibold">
                        Filter analysis
                    </h2>
                    <div className="mt-4 grid min-w-0 grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {select('academic_program_id', 'Academic program', options.programs ?? [])}
                        {select('academic_year_id', 'Academic session', options.years ?? [])}
                        {select('academic_level_id', 'Academic level', options.levels ?? [])}
                        {select('campus_id', 'Campus', options.campuses ?? [])}
                        <label className="grid min-w-0 gap-1 text-xs font-medium">
                            Curriculum status
                            <select
                                className={`${field} w-full min-w-0`}
                                value={values.status ?? ''}
                                onChange={(event) => set('status', event.currentTarget.value)}
                            >
                                <option value="">All</option>
                                <option value="draft">Draft</option>
                                <option value="published">Published</option>
                            </select>
                        </label>
                        <label className="grid min-w-0 gap-1 text-xs font-medium">
                            Version status
                            <select
                                className={`${field} w-full min-w-0`}
                                value={values.version_status ?? ''}
                                onChange={(event) => set('version_status', event.currentTarget.value)}
                            >
                                <option value="">All</option>
                                {['draft', 'in_review', 'approved', 'published', 'rejected'].map((status) => (
                                    <option key={status} value={status}>
                                        {status.replace('_', ' ')}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {select('owner_user_id', 'Curriculum owner', options.owners ?? [])}
                        {select('reviewer_user_id', 'Assigned reviewer', options.reviewers ?? [])}
                        {select('outcome_id', 'Mapped learning outcome', options.outcomes ?? [])}
                        {select('competency_id', 'Mapped competency', options.competencies ?? [])}
                    </div>
                    <div className="mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={apply}
                            className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                        >
                            Apply filters
                        </button>
                        <button type="button" onClick={reset} className={`${field} font-semibold`}>
                            Reset
                        </button>
                    </div>
                </section>
                <section
                    className="border-border bg-surface min-w-0 overflow-hidden rounded-2xl border shadow-sm"
                    aria-labelledby="curricula-title"
                >
                    <div className="border-border border-b p-5">
                        <h2 id="curricula-title" className="text-lg font-semibold">
                            Matching plans
                        </h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Showing {curricula.data.length} of {metrics.curricula} matching curricula.
                        </p>
                    </div>
                    {curricula.data.length === 0 ? (
                        <p className="p-6 text-sm">No curricula match these filters.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[720px] text-left text-sm">
                                <caption className="sr-only">
                                    Filtered curriculum plans and their versions
                                </caption>
                                <thead className="bg-canvas text-text-secondary">
                                    <tr>
                                        <th scope="col" className="p-3">
                                            Curriculum
                                        </th>
                                        <th scope="col" className="p-3">
                                            Program / session
                                        </th>
                                        <th scope="col" className="p-3">
                                            Status
                                        </th>
                                        <th scope="col" className="p-3">
                                            Version summary
                                        </th>
                                        <th scope="col" className="p-3">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {curricula.data.map((curriculum) => (
                                        <tr key={curriculum.id} className="border-border border-t">
                                            <th scope="row" className="p-3 font-semibold">
                                                {curriculum.title}
                                                <span className="text-text-muted mt-1 block text-xs">
                                                    {curriculum.code}
                                                </span>
                                            </th>
                                            <td className="p-3">
                                                {curriculum.program.code} · {curriculum.program.title}
                                                <span className="text-text-muted mt-1 block text-xs">
                                                    {curriculum.academic_year?.name ?? 'No session assigned'}
                                                </span>
                                            </td>
                                            <td className="p-3 capitalize">{curriculum.status}</td>
                                            <td className="p-3">
                                                {curriculum.versions_count} version(s)
                                                <span className="text-text-muted mt-1 block text-xs">
                                                    {curriculum.latest_version
                                                        ? `Latest v${curriculum.latest_version.version_number} · ${curriculum.latest_version.status.replace('_', ' ')}`
                                                        : 'No versions'}
                                                </span>
                                            </td>
                                            <td className="p-3">
                                                <Link
                                                    className="text-brand-link font-semibold underline underline-offset-4"
                                                    href={`${base}/curricula/${curriculum.id}`}
                                                >
                                                    Open plan
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {curricula.links.length > 1 && (
                        <nav
                            aria-label="Analytics result pages"
                            className="border-border flex flex-wrap gap-2 border-t p-4"
                        >
                            {curricula.links.map((link, index) =>
                                link.url ? (
                                    <Link
                                        key={index}
                                        href={link.url}
                                        className={`${field} inline-flex min-w-10 items-center justify-center ${link.active ? 'bg-canvas' : ''}`}
                                        aria-current={link.active ? 'page' : undefined}
                                    >
                                        {paginationLabel(link.label)}
                                    </Link>
                                ) : (
                                    <span
                                        key={index}
                                        className={`${field} inline-flex min-w-10 items-center justify-center opacity-50`}
                                    >
                                        {paginationLabel(link.label)}
                                    </span>
                                ),
                            )}
                        </nav>
                    )}
                </section>
            </div>
        </WorkspaceLayout>
    );
}

function Metric({ label, value }: { label: string; value: number }) {
    return (
        <article className="border-border bg-surface rounded-2xl border p-4 shadow-sm">
            <p className="text-text-muted text-xs font-semibold tracking-wide uppercase">{label}</p>
            <p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p>
        </article>
    );
}

function paginationLabel(value: string) {
    return value
        .replace(/<[^>]*>/g, '')
        .replaceAll('&laquo;', '«')
        .replaceAll('&raquo;', '»')
        .trim();
}
