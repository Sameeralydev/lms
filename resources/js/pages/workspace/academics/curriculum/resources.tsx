import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Version = {
    id: number;
    curriculum_id: number;
    version_number: number;
    status: string;
    curriculum?: { code: string; title: string };
};
type Node = { id: number; curriculum_version_id: number; title: string };
type Document = { id: number; code: string; title: string };
type Resource = {
    id: number;
    code: string;
    version_number: number;
    title: string;
    description: string | null;
    resource_type: string;
    source_url: string | null;
    author_name: string | null;
    publisher_name: string | null;
    license_code: string | null;
    attribution: string | null;
    language: string | null;
    accessibility_notes: string | null;
    captions_available: boolean;
    transcript_available: boolean;
    alternate_formats: string[] | null;
    estimated_minutes: number | null;
    is_required: boolean;
    status: string;
    created_at: string;
    version?: { version_number: number; curriculum?: { code: string; title: string } } | null;
    node?: Node | null;
    document?: { title: string; code: string; status: string } | null;
};
type Props = {
    tenantSlug: string;
    resources: { data: Resource[]; links: { url: string | null; label: string; active: boolean }[] };
    curriculumVersions: Version[];
    nodes: Node[];
    outcomes: { id: number; code: string; name: string }[];
    documents: Document[];
    filters: { q: string; status: string };
};

const field = 'border-border bg-surface min-h-11 w-full rounded-lg border px-3 text-sm';
const panel = 'border-border bg-surface grid gap-4 rounded-2xl border p-5 shadow-sm';
const primary =
    'bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60';

function ResourceActions({
    resource,
    base,
    canManage,
    canReview,
}: {
    resource: Resource;
    base: string;
    canManage: boolean;
    canReview: boolean;
}) {
    const form = useForm({ status: 'in_review' });
    const transition = (status: string) => {
        form.transform(() => ({ status }));
        form.post(`${base}/resources/${resource.id}/transition`);
    };
    return (
        <div className="flex flex-wrap items-center gap-2">
            {resource.status === 'draft' && canManage && (
                <button
                    className="border-border min-h-10 rounded-lg border px-3 text-xs font-semibold"
                    disabled={form.processing}
                    onClick={() => transition('in_review')}
                >
                    Submit review
                </button>
            )}
            {resource.status === 'in_review' && canReview && (
                <>
                    <button
                        className="border-border min-h-10 rounded-lg border px-3 text-xs font-semibold"
                        disabled={form.processing}
                        onClick={() => transition('approved')}
                    >
                        Approve
                    </button>
                    <button
                        className="border-border min-h-10 rounded-lg border px-3 text-xs font-semibold"
                        disabled={form.processing}
                        onClick={() => transition('rejected')}
                    >
                        Reject
                    </button>
                </>
            )}
            {resource.status === 'approved' && resource.resource_type === 'private_document' && (
                <a
                    className="border-border min-h-10 rounded-lg border px-3 py-2 text-xs font-semibold"
                    href={`${base}/resources/${resource.id}/download`}
                >
                    Secure download
                </a>
            )}
            {resource.status === 'approved' &&
                resource.resource_type === 'external_link' &&
                resource.source_url && (
                    <a
                        className="border-border min-h-10 rounded-lg border px-3 py-2 text-xs font-semibold"
                        href={resource.source_url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Open external resource<span className="sr-only"> in a new tab</span>
                    </a>
                )}
            {['draft', 'approved', 'rejected'].includes(resource.status) && canManage && (
                <button
                    className="text-danger min-h-10 rounded-lg px-3 text-xs font-semibold underline"
                    disabled={form.processing}
                    onClick={() => transition('archived')}
                >
                    Archive
                </button>
            )}
            {Object.keys(form.errors).length > 0 && (
                <span role="alert" className="text-danger text-xs">
                    {Object.values(form.errors).join(' ')}
                </span>
            )}
        </div>
    );
}

export default function CurriculumResources({
    tenantSlug,
    resources,
    curriculumVersions,
    nodes,
    outcomes,
    documents,
    filters,
}: Props) {
    const base = `/t/${tenantSlug}/academics/curriculum`;
    const page = usePage<{ auth?: { tenant?: { permissions?: string[] } }; flash?: { status?: string } }>();
    const permissions = page.props.auth?.tenant?.permissions ?? [];
    const canManage = permissions.includes('academic.curriculum.manage');
    const canReview = permissions.includes('academic.curriculum.review');
    const form = useForm({
        code: '',
        title: '',
        description: '',
        resource_type: 'external_link',
        source_url: '',
        academic_document_id: '',
        curriculum_version_id: '',
        node_id: '',
        learning_outcome_id: '',
        author_name: '',
        publisher_name: '',
        license_code: '',
        attribution: '',
        language: 'en',
        accessibility_notes: '',
        captions_available: false,
        transcript_available: false,
        alternate_formats: [] as string[],
        estimated_minutes: '',
        is_required: false,
    });
    const filterForm = useForm({ q: filters.q, status: filters.status });
    const matchingNodes = nodes.filter(
        (node) =>
            !form.data.curriculum_version_id ||
            String(node.curriculum_version_id) === form.data.curriculum_version_id,
    );

    return (
        <WorkspaceLayout title="Curriculum resources" area="tenant">
            <Head title="Curriculum resources" />
            <main className="mx-auto grid w-full max-w-7xl gap-6 px-4 py-6 sm:px-6 lg:px-8">
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-text-muted text-sm">Curriculum materials</p>
                        <h1 className="text-2xl font-bold">Resource library</h1>
                        <p className="text-text-secondary mt-1 max-w-3xl text-sm">
                            Versioned references for curriculum versions, nodes and learning outcomes. Private
                            documents stay in private storage and are scanned again before release.
                        </p>
                    </div>
                    <Link
                        href={base}
                        className="border-border bg-surface min-h-11 rounded-lg border px-4 py-3 text-sm font-semibold"
                    >
                        Back to curriculum
                    </Link>
                </header>
                {canManage && (
                    <section className={panel}>
                        <div>
                            <h2 className="text-lg font-semibold">Add a resource reference</h2>
                            <p className="text-text-secondary text-sm">
                                This creates a draft; only a separate reviewer may approve it.
                            </p>
                        </div>
                        <form
                            className="grid gap-3 sm:grid-cols-2"
                            onSubmit={(event: FormEvent) => {
                                event.preventDefault();
                                form.post(`${base}/resources`, { onSuccess: () => form.reset() });
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Resource code
                                <input
                                    required
                                    maxLength={48}
                                    className={field}
                                    value={form.data.code}
                                    onChange={(event) => form.setData('code', event.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Title
                                <input
                                    required
                                    maxLength={180}
                                    className={field}
                                    value={form.data.title}
                                    onChange={(event) => form.setData('title', event.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Type
                                <select
                                    className={field}
                                    value={form.data.resource_type}
                                    onChange={(event) =>
                                        form.setData('resource_type', event.currentTarget.value)
                                    }
                                >
                                    <option value="external_link">External HTTPS link</option>
                                    <option value="private_document">Approved private document</option>
                                    <option value="learning_reference">Internal learning reference</option>
                                </select>
                            </label>
                            {form.data.resource_type === 'external_link' && (
                                <label className="grid gap-1 text-sm">
                                    HTTPS URL
                                    <input
                                        type="url"
                                        required
                                        className={field}
                                        placeholder="https://example.org/resource"
                                        value={form.data.source_url}
                                        onChange={(event) =>
                                            form.setData('source_url', event.currentTarget.value)
                                        }
                                    />
                                </label>
                            )}
                            {form.data.resource_type === 'private_document' && (
                                <label className="grid gap-1 text-sm">
                                    Approved controlled document
                                    <select
                                        required
                                        className={field}
                                        value={form.data.academic_document_id}
                                        onChange={(event) =>
                                            form.setData('academic_document_id', event.currentTarget.value)
                                        }
                                    >
                                        <option value="">Choose document</option>
                                        {documents.map((document) => (
                                            <option key={document.id} value={document.id}>
                                                {document.code} · {document.title}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            <label className="grid gap-1 text-sm">
                                Curriculum version
                                <select
                                    className={field}
                                    value={form.data.curriculum_version_id}
                                    onChange={(event) => {
                                        form.setData('curriculum_version_id', event.currentTarget.value);
                                        form.setData('node_id', '');
                                    }}
                                >
                                    <option value="">Not linked</option>
                                    {curriculumVersions.map((version) => (
                                        <option key={version.id} value={version.id}>
                                            {version.curriculum?.code} · {version.curriculum?.title} v
                                            {version.version_number} ({version.status})
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Unit, topic or lesson
                                <select
                                    className={field}
                                    disabled={!form.data.curriculum_version_id}
                                    value={form.data.node_id}
                                    onChange={(event) => form.setData('node_id', event.currentTarget.value)}
                                >
                                    <option value="">No node link</option>
                                    {matchingNodes.map((node) => (
                                        <option key={node.id} value={node.id}>
                                            {node.title}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Learning outcome
                                <select
                                    className={field}
                                    value={form.data.learning_outcome_id}
                                    onChange={(event) =>
                                        form.setData('learning_outcome_id', event.currentTarget.value)
                                    }
                                >
                                    <option value="">No outcome link</option>
                                    {outcomes.map((outcome) => (
                                        <option key={outcome.id} value={outcome.id}>
                                            {outcome.code} · {outcome.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Author/source
                                <input
                                    className={field}
                                    maxLength={180}
                                    value={form.data.author_name}
                                    onChange={(event) =>
                                        form.setData('author_name', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Publisher
                                <input
                                    className={field}
                                    maxLength={180}
                                    value={form.data.publisher_name}
                                    onChange={(event) =>
                                        form.setData('publisher_name', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                License
                                <input
                                    className={field}
                                    maxLength={80}
                                    value={form.data.license_code}
                                    onChange={(event) =>
                                        form.setData('license_code', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Language
                                <input
                                    className={field}
                                    maxLength={35}
                                    value={form.data.language}
                                    onChange={(event) => form.setData('language', event.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm sm:col-span-2">
                                Description
                                <textarea
                                    className={`${field} py-2`}
                                    maxLength={5000}
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData('description', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm sm:col-span-2">
                                Attribution
                                <textarea
                                    className={`${field} py-2`}
                                    maxLength={3000}
                                    value={form.data.attribution}
                                    onChange={(event) =>
                                        form.setData('attribution', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm sm:col-span-2">
                                Accessibility notes
                                <textarea
                                    className={`${field} py-2`}
                                    maxLength={3000}
                                    value={form.data.accessibility_notes}
                                    onChange={(event) =>
                                        form.setData('accessibility_notes', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Estimated use (minutes)
                                <input
                                    type="number"
                                    min={1}
                                    max={10000}
                                    className={field}
                                    value={form.data.estimated_minutes}
                                    onChange={(event) =>
                                        form.setData('estimated_minutes', event.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="flex min-h-11 items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.is_required}
                                    onChange={(event) =>
                                        form.setData('is_required', event.currentTarget.checked)
                                    }
                                />
                                Required material
                            </label>
                            <label className="flex min-h-11 items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.captions_available}
                                    onChange={(event) =>
                                        form.setData('captions_available', event.currentTarget.checked)
                                    }
                                />
                                Captions available
                            </label>
                            <label className="flex min-h-11 items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.transcript_available}
                                    onChange={(event) =>
                                        form.setData('transcript_available', event.currentTarget.checked)
                                    }
                                />
                                Transcript available
                            </label>
                            <button className={`${primary} sm:col-span-2`} disabled={form.processing}>
                                Save draft resource
                            </button>
                            {Object.keys(form.errors).length > 0 && (
                                <p role="alert" className="text-danger text-sm sm:col-span-2">
                                    {Object.values(form.errors).join(' ')}
                                </p>
                            )}
                        </form>
                    </section>
                )}
                <section className={panel}>
                    <div>
                        <h2 className="text-lg font-semibold">Institution resource directory</h2>
                        <p className="text-text-secondary text-sm">
                            Search and filter by review state. Archived versions remain in history.
                        </p>
                    </div>
                    <form
                        className="grid gap-2 sm:grid-cols-[1fr_14rem_auto]"
                        onSubmit={(event: FormEvent) => {
                            event.preventDefault();
                            filterForm.get(`${base}/resources`, { preserveState: true });
                        }}
                    >
                        <label className="sr-only" htmlFor="resource-search">
                            Search resources
                        </label>
                        <input
                            id="resource-search"
                            className={field}
                            placeholder="Search title, code or author"
                            value={filterForm.data.q}
                            onChange={(event) => filterForm.setData('q', event.currentTarget.value)}
                        />
                        <label className="sr-only" htmlFor="resource-status">
                            Filter by status
                        </label>
                        <select
                            id="resource-status"
                            className={field}
                            value={filterForm.data.status}
                            onChange={(event) => filterForm.setData('status', event.currentTarget.value)}
                        >
                            <option value="">All statuses</option>
                            {['draft', 'in_review', 'approved', 'rejected', 'archived'].map((status) => (
                                <option key={status} value={status}>
                                    {status.replace('_', ' ')}
                                </option>
                            ))}
                        </select>
                        <button className="border-border min-h-11 rounded-lg border px-4 text-sm font-semibold">
                            Apply filters
                        </button>
                    </form>
                    {resources.data.length === 0 ? (
                        <div className="border-border rounded-xl border border-dashed p-8 text-center">
                            <h3 className="font-semibold">No matching resources</h3>
                            <p className="text-text-secondary mt-1 text-sm">
                                Try a different filter or add a new resource reference.
                            </p>
                        </div>
                    ) : (
                        <div className="grid gap-3">
                            {resources.data.map((resource) => (
                                <article
                                    key={resource.id}
                                    className="border-border grid gap-3 rounded-xl border p-4 lg:grid-cols-[1fr_auto]"
                                >
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h3 className="font-semibold">{resource.title}</h3>
                                            <span className="bg-surface-muted rounded-full px-2 py-1 text-xs">
                                                {resource.status.replace('_', ' ')}
                                            </span>
                                            <span className="text-text-muted text-xs">
                                                {resource.code} · v{resource.version_number}
                                            </span>
                                        </div>
                                        {resource.description && (
                                            <p className="text-text-secondary mt-2 text-sm">
                                                {resource.description}
                                            </p>
                                        )}
                                        <p className="text-text-muted mt-2 text-xs">
                                            {resource.version?.curriculum?.code
                                                ? `${resource.version.curriculum.code} v${resource.version.version_number}`
                                                : 'Unlinked'}
                                            {resource.node ? ` · ${resource.node.title}` : ''}
                                            {resource.author_name ? ` · ${resource.author_name}` : ''}
                                        </p>
                                        {resource.attribution && (
                                            <p className="mt-1 text-xs">
                                                Attribution: {resource.attribution}
                                            </p>
                                        )}
                                        {resource.accessibility_notes && (
                                            <p className="text-text-secondary mt-1 text-xs">
                                                Accessibility: {resource.accessibility_notes}
                                            </p>
                                        )}
                                        {resource.resource_type === 'private_document' && (
                                            <p className="mt-1 text-xs">
                                                Private document: {resource.document?.title ?? 'Unavailable'}.
                                                Release is integrity checked and scanned at access.
                                            </p>
                                        )}
                                    </div>
                                    <ResourceActions
                                        resource={resource}
                                        base={base}
                                        canManage={canManage}
                                        canReview={canReview}
                                    />
                                </article>
                            ))}
                        </div>
                    )}
                    <nav aria-label="Resource directory pages" className="flex flex-wrap gap-2">
                        {resources.links.map((link) => {
                            const label = link.label.includes('Previous')
                                ? 'Previous'
                                : link.label.includes('Next')
                                  ? 'Next'
                                  : link.label.replace(/<[^>]*>/g, '');
                            return (
                                <button
                                    key={link.label}
                                    disabled={!link.url}
                                    aria-current={link.active ? 'page' : undefined}
                                    className="border-border min-h-10 rounded-lg border px-3 text-sm disabled:opacity-50"
                                    onClick={() => link.url && router.get(link.url)}
                                >
                                    {label}
                                </button>
                            );
                        })}
                    </nav>
                </section>
                {page.props.flash?.status && (
                    <p role="status" className="text-success">
                        {page.props.flash.status}
                    </p>
                )}
            </main>
        </WorkspaceLayout>
    );
}
