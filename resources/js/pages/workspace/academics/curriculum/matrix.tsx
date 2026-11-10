import { Head, Link, router, usePage } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Props = {
    tenantSlug: string;
    curriculum: { id: number; code: string; title: string };
    version: { id: number; version_number: number; status: string; lock_version: number };
    nodes: {
        data: { id: number; node_type: string; title: string; code: string | null }[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
    };
    outcomes: {
        id: number;
        code: string;
        name: string;
        required: boolean;
        mappings: Record<string, string | null>;
    }[];
};

export default function CurriculumMatrix({ tenantSlug, curriculum, version, nodes, outcomes }: Props) {
    const base = `/t/${tenantSlug}/academics/curriculum`;
    const page = usePage<{ auth?: { tenant?: { permissions?: string[] } | null } }>();
    const canManage = page.props.auth?.tenant?.permissions?.includes('academic.curriculum.manage') ?? false;
    const editable = canManage && ['draft', 'changes_requested'].includes(version.status);
    return (
        <WorkspaceLayout title="Outcome matrix" area="tenant">
            <Head title={`Outcome matrix · ${curriculum.title}`} />
            <div className="grid gap-6">
                <PageHeader
                    title="Outcome mapping matrix"
                    description={`${curriculum.code} · ${curriculum.title} · version ${version.version_number} (${version.status}). A marker identifies the saved mapping strength for each syllabus item.`}
                    actions={
                        <Link
                            href={`${base}/curricula/${curriculum.id}`}
                            className="border-border bg-surface inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                        >
                            Open syllabus
                        </Link>
                    }
                />
                {outcomes.length === 0 || nodes.data.length === 0 ? (
                    <section className="border-border bg-surface rounded-2xl border p-8 text-center">
                        <h2 className="font-semibold">No outcome mappings to display</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Add syllabus nodes and learning outcomes to build this matrix.
                        </p>
                    </section>
                ) : (
                    <section
                        className="border-border bg-surface overflow-hidden rounded-2xl border shadow-sm"
                        aria-labelledby="matrix-title"
                    >
                        <div className="p-5">
                            <h2 id="matrix-title" className="text-lg font-semibold">
                                Outcome coverage by syllabus item
                            </h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                Showing {nodes.data.length} syllabus items on page {nodes.current_page} of{' '}
                                {nodes.last_page}. Scroll horizontally to compare outcomes.{' '}
                                {editable
                                    ? 'Draft mappings can be changed here.'
                                    : 'This version is read-only.'}
                            </p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse text-left text-sm">
                                <caption className="sr-only">
                                    Learning outcome coverage matrix. Rows are outcomes and columns are
                                    syllabus items.
                                </caption>
                                <thead>
                                    <tr>
                                        <th
                                            scope="col"
                                            className="bg-canvas sticky left-0 z-10 min-w-56 border p-3"
                                        >
                                            Learning outcome
                                        </th>
                                        {nodes.data.map((node) => (
                                            <th key={node.id} scope="col" className="border p-3 align-bottom">
                                                <span className="block min-w-28 font-semibold">
                                                    {node.title}
                                                </span>
                                                <span className="text-text-muted mt-1 block text-xs">
                                                    {node.node_type}
                                                    {node.code ? ` · ${node.code}` : ''}
                                                </span>
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {outcomes.map((outcome) => (
                                        <tr key={outcome.id}>
                                            <th
                                                scope="row"
                                                className="bg-surface sticky left-0 border p-3 font-medium"
                                            >
                                                <span className="block">
                                                    {outcome.code}
                                                    {outcome.required ? (
                                                        <span
                                                            className="text-danger ml-1"
                                                            aria-label="required"
                                                        >
                                                            *
                                                        </span>
                                                    ) : null}
                                                </span>
                                                <span className="text-text-secondary mt-1 block text-xs">
                                                    {outcome.name}
                                                </span>
                                            </th>
                                            {nodes.data.map((node) => {
                                                const strength = outcome.mappings[String(node.id)];
                                                return (
                                                    <td key={node.id} className="border p-2 text-center">
                                                        {strength ? (
                                                            <div className="grid justify-items-center gap-1">
                                                                <span
                                                                    className="bg-canvas inline-flex min-h-8 items-center rounded-full px-2.5 text-xs font-semibold capitalize"
                                                                    aria-label={`${strength} mapping`}
                                                                >
                                                                    {strength}
                                                                </span>
                                                                {editable && (
                                                                    <div className="flex flex-wrap justify-center gap-1">
                                                                        <label
                                                                            className="sr-only"
                                                                            htmlFor={`strength-${outcome.id}-${node.id}`}
                                                                        >
                                                                            Mapping strength for{' '}
                                                                            {outcome.code} in {node.title}
                                                                        </label>
                                                                        <select
                                                                            id={`strength-${outcome.id}-${node.id}`}
                                                                            className="border-border bg-surface min-h-9 rounded-md border px-1 text-xs"
                                                                            value={strength}
                                                                            aria-label={`Mapping strength for ${outcome.code} in ${node.title}`}
                                                                            onChange={(event) =>
                                                                                router.post(
                                                                                    `${base}/versions/${version.id}/nodes/${node.id}/outcomes`,
                                                                                    {
                                                                                        version_lock:
                                                                                            version.lock_version,
                                                                                        learning_outcome_id:
                                                                                            outcome.id,
                                                                                        mapping_strength:
                                                                                            event
                                                                                                .currentTarget
                                                                                                .value,
                                                                                        surface: 'matrix',
                                                                                    },
                                                                                    { preserveScroll: true },
                                                                                )
                                                                            }
                                                                        >
                                                                            <option value="introduced">
                                                                                Introduced
                                                                            </option>
                                                                            <option value="developed">
                                                                                Developed
                                                                            </option>
                                                                            <option value="mastered">
                                                                                Mastered
                                                                            </option>
                                                                        </select>
                                                                        <button
                                                                            type="button"
                                                                            className="text-danger min-h-9 rounded-md border border-current px-2 text-xs font-semibold"
                                                                            aria-label={`Remove ${outcome.code} mapping from ${node.title}`}
                                                                            onClick={() =>
                                                                                router.delete(
                                                                                    `${base}/versions/${version.id}/nodes/${node.id}/outcomes/${outcome.id}`,
                                                                                    {
                                                                                        data: {
                                                                                            version_lock:
                                                                                                version.lock_version,
                                                                                            surface: 'matrix',
                                                                                        },
                                                                                        preserveScroll: true,
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            Remove
                                                                        </button>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        ) : editable ? (
                                                            <button
                                                                type="button"
                                                                className="text-brand-link min-h-10 rounded-md border border-current px-2 text-xs font-semibold"
                                                                aria-label={`Map ${outcome.code} to ${node.title}`}
                                                                onClick={() =>
                                                                    router.post(
                                                                        `${base}/versions/${version.id}/nodes/${node.id}/outcomes`,
                                                                        {
                                                                            version_lock:
                                                                                version.lock_version,
                                                                            learning_outcome_id: outcome.id,
                                                                            mapping_strength: 'introduced',
                                                                            surface: 'matrix',
                                                                        },
                                                                        { preserveScroll: true },
                                                                    )
                                                                }
                                                            >
                                                                Map
                                                            </button>
                                                        ) : (
                                                            <span
                                                                className="text-text-muted"
                                                                aria-label="Not mapped"
                                                            >
                                                                —
                                                            </span>
                                                        )}
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <nav
                            aria-label="Outcome matrix syllabus pages"
                            className="border-border flex flex-wrap gap-2 border-t p-4"
                        >
                            {nodes.links.map((link, index) =>
                                link.url ? (
                                    <Link
                                        key={index}
                                        href={link.url}
                                        className={`border-border inline-flex min-h-10 min-w-10 items-center justify-center rounded-lg border px-3 text-sm ${link.active ? 'bg-canvas font-semibold' : ''}`}
                                        aria-current={link.active ? 'page' : undefined}
                                    >
                                        {paginationLabel(link.label)}
                                    </Link>
                                ) : (
                                    <span
                                        key={index}
                                        className="border-border text-text-secondary inline-flex min-h-10 min-w-10 items-center justify-center rounded-lg border px-3 text-sm"
                                        aria-disabled="true"
                                    >
                                        {paginationLabel(link.label)}
                                    </span>
                                ),
                            )}
                        </nav>
                    </section>
                )}
            </div>
        </WorkspaceLayout>
    );
}

function paginationLabel(value: string) {
    return value
        .replace(/<[^>]*>/g, '')
        .replaceAll('&laquo;', '«')
        .replaceAll('&raquo;', '»')
        .trim();
}
