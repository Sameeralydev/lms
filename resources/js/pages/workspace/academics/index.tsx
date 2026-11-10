import { Link } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Props = {
    tenant: { slug: string };
    institution: { name: string; type: string };
    configuration: {
        operating_model: string;
        terminology: Record<string, string>;
        enabled_features: Record<string, boolean>;
    };
    metrics: Record<string, number>;
    currentYear: null | {
        name: string;
        starts_on: string;
        ends_on: string;
        periods: { id: number; name: string; is_current: boolean }[];
    };
    recentYears: {
        id: number;
        name: string;
        code: string;
        starts_on: string;
        ends_on: string;
        status: string;
        is_current: boolean;
    }[];
};

const cards = [
    ['currentYears', 'Current academic years'],
    ['academicUnits', 'Active academic units'],
    ['levels', 'Academic levels'],
    ['programs', 'Active programs'],
    ['courses', 'Active courses'],
    ['openPolicyGaps', 'Open policy gaps'],
    ['qualityObjectives', 'Quality objectives'],
] as const;

const metricFeatures = {
    currentYears: 'calendar',
    academicUnits: 'organization',
    levels: 'organization',
    programs: 'programs',
    courses: 'catalog',
    openPolicyGaps: 'catalog',
    qualityObjectives: 'quality',
} as const;

export default function AcademicDashboard({
    tenant,
    institution,
    configuration,
    metrics,
    currentYear,
    recentYears,
}: Props) {
    const root = `/t/${tenant.slug}/academics`;
    return (
        <WorkspaceLayout area="tenant" title="Academic administration">
            <PageHeader
                title="Academic administration"
                description={`${institution.name} · A configurable foundation for calendars, organization, programs, and quality.`}
            />
            <section aria-label="Academic overview" className="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {cards
                    .filter(([key]) => configuration.enabled_features[metricFeatures[key]])
                    .map(([key, label]) => (
                        <article
                            key={key}
                            className="border-border bg-surface rounded-2xl border p-5 shadow-sm"
                        >
                            <p className="text-text-muted text-sm">{label}</p>
                            <p className="mt-3 text-3xl font-semibold tabular-nums">{metrics[key] ?? 0}</p>
                        </article>
                    ))}
            </section>
            <section className="mt-7 grid gap-5 xl:grid-cols-[minmax(0,1.1fr)_minmax(18rem,.9fr)]">
                <div className="border-border bg-surface rounded-2xl border p-5 sm:p-7">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 className="text-lg font-semibold">Operating model</h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                Terminology and enabled capabilities follow your institution configuration.
                            </p>
                        </div>
                        <span className="bg-brand-blue/10 text-brand-link rounded-full px-3 py-1 text-sm font-medium">
                            {configuration.operating_model.replaceAll('_', ' ')}
                        </span>
                    </div>
                    <dl className="mt-6 grid gap-4 sm:grid-cols-2">
                        {Object.entries(configuration.terminology ?? {}).map(([concept, label]) => (
                            <div key={concept} className="border-border rounded-xl border p-4">
                                <dt className="text-text-muted text-xs font-semibold tracking-wide uppercase">
                                    {concept.replaceAll('_', ' ')}
                                </dt>
                                <dd className="mt-1 font-medium">{label}</dd>
                            </div>
                        ))}
                    </dl>
                    <div className="mt-5 flex flex-wrap gap-2">
                        <Link
                            href={`${root}/calendar`}
                            className="bg-brand-action focus-ring rounded-lg px-4 py-2.5 text-sm font-semibold text-white"
                        >
                            Academic calendar
                        </Link>
                        <Link
                            href={`${root}/structure`}
                            className="border-border focus-ring rounded-lg border px-4 py-2.5 text-sm font-semibold"
                        >
                            Organization & programs
                        </Link>
                    </div>
                </div>
                <div className="border-border bg-surface rounded-2xl border p-5 sm:p-7">
                    <h2 className="text-lg font-semibold">Current academic year</h2>
                    {configuration.enabled_features.calendar && currentYear ? (
                        <>
                            <p className="mt-4 text-2xl font-semibold">{currentYear.name}</p>
                            <p className="text-text-secondary mt-1 text-sm">
                                {currentYear.starts_on} to {currentYear.ends_on}
                            </p>
                            <h3 className="mt-6 text-sm font-semibold">Periods</h3>
                            {currentYear.periods.length > 0 ? (
                                <ul className="divide-border mt-2 divide-y">
                                    {currentYear.periods.map((period) => (
                                        <li key={period.id} className="flex justify-between py-3 text-sm">
                                            <span>{period.name}</span>
                                            {period.is_current && (
                                                <span className="text-brand-link font-medium">Current</span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-text-secondary mt-2 text-sm">
                                    No periods have been configured yet.
                                </p>
                            )}
                        </>
                    ) : configuration.enabled_features.calendar ? (
                        <div className="border-border bg-canvas mt-4 rounded-xl border border-dashed p-5">
                            <p className="font-medium">No current year selected</p>
                            <p className="text-text-secondary mt-1 text-sm">
                                Create a calendar year, activate it, then set it as current.
                            </p>
                        </div>
                    ) : (
                        <div className="border-border bg-canvas mt-4 rounded-xl border border-dashed p-5">
                            <p className="font-medium">Calendar capability is disabled</p>
                            <p className="text-text-secondary mt-1 text-sm">
                                An institution administrator can enable it in academic settings.
                            </p>
                        </div>
                    )}
                </div>
            </section>
            {configuration.enabled_features.calendar ? (
                <section className="border-border bg-surface mt-7 rounded-2xl border p-5 sm:p-7">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold">Recent academic years</h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                Historical calendar records remain available for reference.
                            </p>
                        </div>
                        <Link
                            href={`${root}/calendar`}
                            className="text-brand-link focus-ring rounded text-sm font-semibold"
                        >
                            Manage calendar
                        </Link>
                    </div>
                    {recentYears.length ? (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full min-w-[38rem] text-left text-sm">
                                <thead>
                                    <tr className="text-text-muted border-border border-b">
                                        <th className="py-3 pr-4 font-medium">Name</th>
                                        <th className="py-3 pr-4 font-medium">Code</th>
                                        <th className="py-3 pr-4 font-medium">Dates</th>
                                        <th className="py-3 pr-4 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recentYears.map((year) => (
                                        <tr key={year.id} className="border-border border-b last:border-0">
                                            <td className="py-3 pr-4 font-medium">
                                                {year.name}
                                                {year.is_current ? ' · Current' : ''}
                                            </td>
                                            <td className="py-3 pr-4">{year.code}</td>
                                            <td className="py-3 pr-4">
                                                {year.starts_on} – {year.ends_on}
                                            </td>
                                            <td className="py-3 pr-4 capitalize">{year.status}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <p className="text-text-secondary mt-5 text-sm">
                            No academic years yet. Start by creating a calendar year.
                        </p>
                    )}
                </section>
            ) : null}
        </WorkspaceLayout>
    );
}
