import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type CourseVersion = {
    id: number;
    academic_course_id: number;
    version_number: number;
    title: string;
    course: { id: number; title: string; code: string };
};
type Workspace = {
    id: number;
    code: string;
    title: string;
    description: string | null;
    status: string;
    delivery_mode: string;
    campus: { id: number; name: string } | null;
    modules_count: number;
    course: { title: string; code: string };
    course_version: { title: string; version_number: number };
};
type Props = {
    tenant: { slug: string };
    workspaces: { data: Workspace[]; links: { url: string | null; label: string; active: boolean }[] };
    courseVersions: CourseVersion[];
    campuses: { id: number; name: string; code: string }[];
    scopeCampusId: number | null;
    canManage: boolean;
    canAnalytics: boolean;
};

export default function LearningIndex({
    tenant,
    workspaces,
    courseVersions,
    campuses,
    scopeCampusId,
    canManage,
    canAnalytics,
}: Props) {
    const form = useForm({
        academic_course_id: '',
        academic_course_version_id: '',
        campus_id: scopeCampusId ? String(scopeCampusId) : '',
        code: '',
        title: '',
        description: '',
        delivery_mode: 'self_paced',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/t/${tenant.slug}/learning`, { preserveScroll: true });
    };

    return (
        <WorkspaceLayout title="Course delivery" area="tenant">
            <Head title="Course delivery" />
            <PageHeader
                eyebrow="Learning delivery"
                title="Course workspaces"
                description="Build and govern institution-owned learning content from published course catalog versions."
            />

            {canAnalytics && (
                <div className="mt-5 flex justify-end">
                    <Link
                        href={`/t/${tenant.slug}/learning/analytics`}
                        className="border-border hover:bg-surface-soft focus-ring min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold"
                    >
                        Learning analytics and quality
                    </Link>
                </div>
            )}

            <div className="mt-3 flex justify-end">
                <Link
                    href={`/t/${tenant.slug}/learning/sessions`}
                    className="border-border hover:bg-surface-soft focus-ring min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold"
                >
                    Live session calendar
                </Link>
            </div>

            {canManage && (
                <div className="mt-3 flex justify-end">
                    <Link
                        href={`/t/${tenant.slug}/learning/templates`}
                        className="border-border hover:bg-surface-soft focus-ring min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold"
                    >
                        Course templates and copying
                    </Link>
                </div>
            )}

            {canManage && (
                <section
                    className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7"
                    aria-labelledby="create-workspace-title"
                >
                    <h2 id="create-workspace-title" className="text-lg font-semibold">
                        Create a delivery workspace
                    </h2>
                    <p className="text-text-muted mt-1 text-sm">
                        A workspace references an existing published course version. It does not create
                        enrollment or assessment records.
                    </p>
                    {courseVersions.length === 0 ? (
                        <p className="bg-surface-soft mt-4 rounded-xl p-4 text-sm">
                            Publish a course version in the academic catalog before creating its delivery
                            workspace.
                        </p>
                    ) : (
                        <form onSubmit={submit} className="mt-5 grid gap-4 md:grid-cols-2">
                            <label className="grid gap-1.5 text-sm font-medium md:col-span-2">
                                Published course version
                                <select
                                    required
                                    value={form.data.academic_course_version_id}
                                    onChange={(event) => {
                                        const selected = courseVersions.find(
                                            (version) => String(version.id) === event.currentTarget.value,
                                        );
                                        form.setData((current) => ({
                                            ...current,
                                            academic_course_id: selected
                                                ? String(selected.academic_course_id)
                                                : '',
                                            academic_course_version_id: event.currentTarget.value,
                                        }));
                                    }}
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                >
                                    <option value="">Choose a course version</option>
                                    {courseVersions.map((version) => (
                                        <option key={version.id} value={version.id}>
                                            {version.course.code} · {version.course.title} — v
                                            {version.version_number}: {version.title}
                                        </option>
                                    ))}
                                </select>
                                {form.errors.academic_course_version_id && (
                                    <span className="text-red-700">
                                        {form.errors.academic_course_version_id}
                                    </span>
                                )}
                            </label>
                            {campuses.length > 0 && (
                                <label className="grid gap-1.5 text-sm font-medium md:col-span-2">
                                    Campus scope
                                    <select
                                        value={form.data.campus_id}
                                        onChange={(event) =>
                                            form.setData('campus_id', event.currentTarget.value)
                                        }
                                        className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    >
                                        <option value="">Institution wide</option>
                                        {campuses.map((campus) => (
                                            <option value={campus.id} key={campus.id}>
                                                {campus.name} · {campus.code}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            <label className="grid gap-1.5 text-sm font-medium">
                                Workspace code
                                <input
                                    required
                                    maxLength={64}
                                    value={form.data.code}
                                    onChange={(event) => form.setData('code', event.currentTarget.value)}
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                />
                                {form.errors.code && <span className="text-red-700">{form.errors.code}</span>}
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium">
                                Workspace title
                                <input
                                    required
                                    maxLength={180}
                                    value={form.data.title}
                                    onChange={(event) => form.setData('title', event.currentTarget.value)}
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                />
                                {form.errors.title && (
                                    <span className="text-red-700">{form.errors.title}</span>
                                )}
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium">
                                Delivery model
                                <select
                                    value={form.data.delivery_mode}
                                    onChange={(event) =>
                                        form.setData('delivery_mode', event.currentTarget.value)
                                    }
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                >
                                    <option value="self_paced">Self paced</option>
                                    <option value="instructor_paced">Instructor paced</option>
                                    <option value="blended">Blended</option>
                                </select>
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium md:col-span-2">
                                Description
                                <textarea
                                    maxLength={5000}
                                    rows={3}
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData('description', event.currentTarget.value)
                                    }
                                    className="border-border bg-surface rounded-lg border px-3 py-2"
                                />
                            </label>
                            <div className="md:col-span-2">
                                <button
                                    disabled={form.processing}
                                    className="bg-brand-action hover:bg-brand-action-hover focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                                >
                                    {form.processing ? 'Creating…' : 'Create workspace'}
                                </button>
                            </div>
                        </form>
                    )}
                </section>
            )}

            <section className="mt-8" aria-labelledby="workspace-list-title">
                <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-text-muted text-xs font-semibold tracking-wide uppercase">
                            Institution library
                        </p>
                        <h2 id="workspace-list-title" className="mt-1 text-xl font-semibold">
                            Delivery workspaces
                        </h2>
                    </div>
                    <p className="text-text-muted text-sm">{workspaces.data.length} shown on this page</p>
                </div>
                {workspaces.data.length === 0 ? (
                    <div className="border-border bg-surface rounded-2xl border border-dashed p-8 text-center">
                        <h3 className="font-semibold">No delivery workspaces yet</h3>
                        <p className="text-text-muted mt-2 text-sm">
                            When a workspace is created from a published catalog version, it will appear here.
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {workspaces.data.map((workspace) => (
                            <article
                                key={workspace.id}
                                className="border-border bg-surface rounded-2xl border p-5 shadow-sm"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="text-text-muted text-xs font-semibold tracking-wide uppercase">
                                            {workspace.code} · {workspace.course.code}
                                        </p>
                                        <h3 className="mt-1 text-lg font-semibold">{workspace.title}</h3>
                                        <p className="text-text-muted mt-1 text-sm">
                                            {workspace.course.title} · catalog v
                                            {workspace.course_version.version_number}
                                        </p>
                                    </div>
                                    <span className="bg-surface-soft rounded-full px-3 py-1 text-xs font-semibold capitalize">
                                        {workspace.status.replace('_', ' ')}
                                    </span>
                                </div>
                                {workspace.description && (
                                    <p className="text-text-muted mt-3 line-clamp-2 text-sm">
                                        {workspace.description}
                                    </p>
                                )}
                                <div className="text-text-muted mt-4 flex flex-wrap gap-4 text-xs">
                                    <span>{workspace.modules_count} modules</span>
                                    <span className="capitalize">
                                        {workspace.delivery_mode.replace('_', ' ')}
                                    </span>
                                    {workspace.campus && <span>{workspace.campus.name}</span>}
                                </div>
                                <Link
                                    href={`/t/${tenant.slug}/learning/${workspace.id}`}
                                    className="focus-ring border-border hover:bg-surface-soft mt-5 inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                                >
                                    Open workspace<span className="sr-only">: {workspace.title}</span>
                                </Link>
                            </article>
                        ))}
                    </div>
                )}
            </section>
        </WorkspaceLayout>
    );
}
