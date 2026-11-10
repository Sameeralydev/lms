import { Head, Link, router } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Version = { id: number; version_number: number; status: string };
type Change = { key: string; title: string; changes: Record<string, { from: unknown; to: unknown }> };
type Props = {
    tenantSlug: string;
    curriculum: { id: number; code: string; title: string };
    versions: Version[];
    filters: { from_version: number; to_version: number };
    comparison: {
        from: { id: number; number: number; status: string };
        to: { id: number; number: number; status: string };
        version_changes: Record<string, { from: unknown; to: unknown }>;
        added: { key: string; title: string; type: string }[];
        removed: { key: string; title: string; type: string }[];
        modified: Change[];
        summary: { added: number; removed: number; modified: number };
    };
};

const field = 'border-border bg-surface min-h-11 rounded-lg border px-3 text-sm';

export default function CurriculumCompare({ tenantSlug, curriculum, versions, filters, comparison }: Props) {
    const base = `/t/${tenantSlug}/academics/curriculum`;
    const apply = (from: string, to: string) =>
        router.get(
            `${base}/curricula/${curriculum.id}/compare`,
            { from_version: from, to_version: to },
            { preserveScroll: true },
        );
    const renderValue = (value: unknown) =>
        value === null || value === '' ? '—' : typeof value === 'string' ? value : JSON.stringify(value);
    return (
        <WorkspaceLayout title="Compare curriculum versions" area="tenant">
            <Head title={`Compare versions · ${curriculum.title}`} />
            <div className="grid gap-6">
                <PageHeader
                    title="Version comparison"
                    description={`${curriculum.code} · ${curriculum.title}. Published versions are read-only; this comparison reports saved differences without changing either version.`}
                    actions={
                        <Link
                            href={`${base}/curricula/${curriculum.id}`}
                            className="border-border bg-surface inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                        >
                            Open curriculum
                        </Link>
                    }
                />
                <section
                    className="border-border bg-surface grid gap-3 rounded-2xl border p-5 shadow-sm md:grid-cols-[1fr_1fr_auto] md:items-end"
                    aria-label="Choose versions to compare"
                >
                    <label className="grid gap-1 text-sm font-medium">
                        Earlier / source version
                        <select
                            className={field}
                            value={filters.from_version}
                            onChange={(event) => apply(event.currentTarget.value, String(filters.to_version))}
                        >
                            {versions.map((version) => (
                                <option key={version.id} value={version.id}>
                                    Version {version.version_number} · {version.status.replace('_', ' ')}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-medium">
                        Later / target version
                        <select
                            className={field}
                            value={filters.to_version}
                            onChange={(event) =>
                                apply(String(filters.from_version), event.currentTarget.value)
                            }
                        >
                            {versions.map((version) => (
                                <option key={version.id} value={version.id}>
                                    Version {version.version_number} · {version.status.replace('_', ' ')}
                                </option>
                            ))}
                        </select>
                    </label>
                    <span className="text-text-secondary min-h-11 content-center text-sm">
                        v{comparison.from.number} → v{comparison.to.number}
                    </span>
                </section>
                <section className="grid gap-3 sm:grid-cols-3" aria-label="Change totals">
                    {[
                        ['Added', comparison.summary.added],
                        ['Removed', comparison.summary.removed],
                        ['Modified', comparison.summary.modified],
                    ].map(([label, value]) => (
                        <article
                            key={label}
                            className="border-border bg-surface rounded-2xl border p-4 shadow-sm"
                        >
                            <p className="text-text-muted text-xs font-semibold tracking-wide uppercase">
                                {label}
                            </p>
                            <p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p>
                        </article>
                    ))}
                </section>
                {Object.keys(comparison.version_changes).length > 0 && (
                    <section className="border-border bg-surface rounded-2xl border p-5 shadow-sm">
                        <h2 className="font-semibold">Version details changed</h2>
                        <ul className="mt-3 grid gap-2 text-sm">
                            {Object.entries(comparison.version_changes).map(([key, values]) => (
                                <li key={key}>
                                    <span className="font-semibold capitalize">
                                        {key.replaceAll('_', ' ')}:
                                    </span>{' '}
                                    {renderValue(values.from)} <span aria-hidden="true">→</span>{' '}
                                    {renderValue(values.to)}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                <div className="grid gap-5 xl:grid-cols-3">
                    <ChangeList
                        title="Added items"
                        empty="No syllabus items were added."
                        items={comparison.added.map((item) => `${item.type} · ${item.title}`)}
                    />
                    <ChangeList
                        title="Removed items"
                        empty="No syllabus items were removed."
                        items={comparison.removed.map((item) => `${item.type} · ${item.title}`)}
                    />
                    <section className="border-border bg-surface rounded-2xl border p-5 shadow-sm">
                        <h2 className="font-semibold">Modified items</h2>
                        {comparison.modified.length === 0 ? (
                            <p className="text-text-secondary mt-3 text-sm">No saved fields changed.</p>
                        ) : (
                            <ul className="mt-3 grid gap-4">
                                {comparison.modified.map((item) => (
                                    <li key={item.key} className="border-border border-t pt-3">
                                        <h3 className="font-medium">{item.title}</h3>
                                        <ul className="text-text-secondary mt-2 grid gap-2 text-xs">
                                            {Object.entries(item.changes).map(([fieldName, values]) => (
                                                <li key={fieldName}>
                                                    <span className="font-semibold capitalize">
                                                        {fieldName.replaceAll('_', ' ')}:
                                                    </span>
                                                    <span className="block break-words">
                                                        {renderValue(values.from)}{' '}
                                                        <span aria-hidden="true">→</span>{' '}
                                                        {renderValue(values.to)}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </WorkspaceLayout>
    );
}

function ChangeList({ title, empty, items }: { title: string; empty: string; items: string[] }) {
    return (
        <section className="border-border bg-surface rounded-2xl border p-5 shadow-sm">
            <h2 className="font-semibold">{title}</h2>
            {items.length === 0 ? (
                <p className="text-text-secondary mt-3 text-sm">{empty}</p>
            ) : (
                <ul className="mt-3 grid gap-2">
                    {items.map((item) => (
                        <li key={item} className="border-border rounded-lg border p-3 text-sm">
                            {item}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
