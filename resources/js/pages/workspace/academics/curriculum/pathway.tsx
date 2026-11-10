import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type CurriculumVersion = { id: number; version_number: number; status: string };
type CurriculumNode = {
    id: number;
    parent_id: number | null;
    node_type: string;
    code: string | null;
    title: string;
    sort_order: number;
    is_required: boolean;
};
type PrerequisiteEdge = {
    id: number;
    dependency_type: string;
    node_id: number;
    node_code: string | null;
    node_title: string | null;
    prerequisite_node_id: number;
    prerequisite_code: string | null;
    prerequisite_title: string | null;
};
type Props = {
    curriculum: { id: number; code: string; title: string };
    versions: CurriculumVersion[];
    version: CurriculumVersion;
    nodes: CurriculumNode[];
    edges: PrerequisiteEdge[];
    tenantSlug: string;
};

const cardClass = 'border-border bg-surface rounded-2xl border p-5 shadow-sm';

export default function CurriculumPathway({
    curriculum,
    versions,
    version,
    nodes,
    edges,
    tenantSlug,
}: Props) {
    const base = `/t/${tenantSlug}/academics/curriculum`;

    return (
        <WorkspaceLayout title="Prerequisite explorer" area="tenant">
            <Head title={`Prerequisite pathway · ${curriculum.title}`} />
            <div className="grid gap-6">
                <PageHeader
                    title={`${curriculum.title} prerequisite explorer`}
                    description={`${curriculum.code} · Version ${version.version_number} · ${version.status.replaceAll('_', ' ')}`}
                />
                <div className="flex flex-wrap items-center gap-2">
                    <Link
                        href={`${base}/curricula/${curriculum.id}?version=${version.id}`}
                        className="text-brand-link inline-flex min-h-11 items-center py-2 text-sm font-semibold underline underline-offset-4"
                    >
                        Back to curriculum plan
                    </Link>
                </div>
                <nav aria-label="Curriculum versions" className="flex flex-wrap gap-2">
                    {versions.map((item) => (
                        <Link
                            key={item.id}
                            href={`${base}/curricula/${curriculum.id}/pathway?version=${item.id}`}
                            aria-current={item.id === version.id ? 'page' : undefined}
                            className={`border-border min-h-10 rounded-lg border px-3 py-2 text-sm font-semibold ${item.id === version.id ? 'bg-brand-action text-white' : 'bg-surface'}`}
                        >
                            Version {item.version_number} · {item.status.replaceAll('_', ' ')}
                        </Link>
                    ))}
                </nav>
                <section className={cardClass} aria-labelledby="prerequisite-edges-heading">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 id="prerequisite-edges-heading" className="text-lg font-semibold">
                                Learning dependencies
                            </h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                Each dependency reads from the prerequisite item to the item that depends on
                                it.
                            </p>
                        </div>
                        <p className="text-text-muted text-sm tabular-nums">
                            {edges.length} {edges.length === 1 ? 'dependency' : 'dependencies'} ·{' '}
                            {nodes.length} syllabus items
                        </p>
                    </div>
                    {edges.length === 0 ? (
                        <p className="text-text-secondary mt-4 rounded-xl border border-dashed p-5 text-sm">
                            No prerequisite dependencies are defined for this version.
                        </p>
                    ) : (
                        <ol className="mt-4 grid gap-3">
                            {edges.map((edge) => (
                                <li
                                    key={edge.id}
                                    className="border-border grid gap-3 rounded-xl border p-4 sm:grid-cols-[1fr_auto_1fr] sm:items-center"
                                >
                                    <div>
                                        <p className="text-text-muted text-xs font-semibold uppercase">
                                            Prerequisite
                                        </p>
                                        <p className="mt-1 font-semibold">
                                            {edge.prerequisite_title ?? 'Item unavailable'}
                                        </p>
                                        {edge.prerequisite_code && (
                                            <p className="text-text-secondary mt-1 text-sm">
                                                {edge.prerequisite_code}
                                            </p>
                                        )}
                                    </div>
                                    <span
                                        aria-label={`${edge.dependency_type} prerequisite leads to`}
                                        className="text-brand-link text-sm font-semibold sm:px-2"
                                    >
                                        {edge.dependency_type === 'required' ? 'Required →' : 'Recommended →'}
                                    </span>
                                    <div>
                                        <p className="text-text-muted text-xs font-semibold uppercase">
                                            Dependent item
                                        </p>
                                        <p className="mt-1 font-semibold">
                                            {edge.node_title ?? 'Item unavailable'}
                                        </p>
                                        {edge.node_code && (
                                            <p className="text-text-secondary mt-1 text-sm">
                                                {edge.node_code}
                                            </p>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ol>
                    )}
                </section>
                <section className={cardClass} aria-labelledby="pathway-items-heading">
                    <h2 id="pathway-items-heading" className="text-lg font-semibold">
                        Syllabus items in this version
                    </h2>
                    {nodes.length === 0 ? (
                        <p className="text-text-secondary mt-4 rounded-xl border border-dashed p-5 text-sm">
                            This version does not contain syllabus items yet.
                        </p>
                    ) : (
                        <ol className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {nodes.map((node) => (
                                <li key={node.id} className="border-border rounded-xl border p-3">
                                    <p className="text-text-muted text-xs font-semibold uppercase">
                                        {node.node_type} · Order {node.sort_order} ·{' '}
                                        {node.is_required ? 'Required' : 'Optional'}
                                    </p>
                                    <p className="mt-1 font-medium">{node.title}</p>
                                    {node.code && (
                                        <p className="text-text-secondary mt-1 text-sm">{node.code}</p>
                                    )}
                                </li>
                            ))}
                        </ol>
                    )}
                </section>
            </div>
        </WorkspaceLayout>
    );
}
