import { Head, router, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Category = { id: number; name: string; weight_basis_points: number };
type Item = {
    id: number;
    item_key: string;
    title: string;
    category_id: number;
    maximum_points: string;
    source_type: string;
    source_key: string | null;
};
type RosterEntry = {
    item_id: number;
    entry_id: number | null;
    earned_points: string | null;
    entry_status: string;
    lock_version: number;
};
type Learner = {
    enrollment: { id: number; student_id: number };
    student: { number: string | null; first_name: string | null; last_name: string | null };
    entries: RosterEntry[];
    calculation: { status: string; percentage: string | null };
};
type Override = {
    id: number;
    entry_id: number;
    original_points: string;
    proposed_points: string;
    reason: string;
    student: string;
    student_number: string | null;
};
type Props = {
    tenant: { slug: string };
    gradebook: {
        id: number;
        status: string;
        version: number;
        section: string | null;
        course: string | null;
        policy: { id: number; name: string; categories: Category[] };
        items: Item[];
    };
    roster: Learner[];
    pendingOverrides: Override[];
    permissions: { edit: boolean; submit: boolean; review: boolean; publish: boolean };
};
const control = 'border-border bg-surface focus-ring min-h-10 rounded-lg border px-3 py-2 text-sm';

function ItemForm({
    tenant,
    gradebookId,
    categories,
}: {
    tenant: string;
    gradebookId: number;
    categories: Category[];
}) {
    const form = useForm({
        item_key: '',
        title: '',
        category_id: '',
        maximum_points: '100',
        source_type: 'manual',
        source_key: '',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/t/${tenant}/academics/grades/gradebooks/${gradebookId}/items`, {
            preserveScroll: true,
            onSuccess: () => form.reset('item_key', 'title', 'source_key'),
        });
    };
    return (
        <form onSubmit={submit} className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-7">
            <label className="text-sm">
                Item key
                <input
                    className={`${control} mt-1 w-full`}
                    value={form.data.item_key}
                    onChange={(e) => form.setData('item_key', e.currentTarget.value)}
                    required
                />
            </label>
            <label className="text-sm">
                Assessment name
                <input
                    className={`${control} mt-1 w-full`}
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.currentTarget.value)}
                    required
                />
            </label>
            <label className="text-sm">
                Category
                <select
                    className={`${control} mt-1 w-full`}
                    value={form.data.category_id}
                    onChange={(e) => form.setData('category_id', e.currentTarget.value)}
                    required
                >
                    <option value="">Select</option>
                    {categories.map((category) => (
                        <option key={category.id} value={category.id}>
                            {category.name}
                        </option>
                    ))}
                </select>
            </label>
            <label className="text-sm">
                Maximum points
                <input
                    className={`${control} mt-1 w-full`}
                    inputMode="decimal"
                    value={form.data.maximum_points}
                    onChange={(e) => form.setData('maximum_points', e.currentTarget.value)}
                    required
                />
            </label>
            <label className="text-sm">
                Score source
                <select
                    className={`${control} mt-1 w-full`}
                    value={form.data.source_type}
                    onChange={(e) => form.setData('source_type', e.currentTarget.value)}
                >
                    <option value="manual">Manual entry</option>
                    <option value="assignment">Released assignment</option>
                    <option value="quiz">Released quiz result</option>
                    <option value="examination">Approved examination result</option>
                </select>
            </label>
            {form.data.source_type !== 'manual' && (
                <label className="text-sm">
                    Source record ID
                    <input
                        className={`${control} mt-1 w-full`}
                        inputMode="numeric"
                        value={form.data.source_key}
                        onChange={(e) => form.setData('source_key', e.currentTarget.value)}
                        required
                        aria-describedby="source-id-help"
                    />
                    <span id="source-id-help" className="text-text-muted mt-1 block text-xs">
                        Use the assignment, quiz, or examination session ID.
                    </span>
                </label>
            )}
            <button
                className="bg-brand-action focus-ring self-end rounded-lg px-4 py-2.5 text-sm font-semibold text-white"
                disabled={form.processing}
            >
                Add item
            </button>
            <p role="alert" className="text-danger text-sm sm:col-span-2 xl:col-span-7">
                {Object.values(form.errors).join(' ')}
            </p>
        </form>
    );
}

function EntryForm({
    tenant,
    book,
    item,
    enrollment,
    entry,
}: {
    tenant: string;
    book: number;
    item: Item;
    enrollment: number;
    entry: RosterEntry;
}) {
    const form = useForm({
        earned_points: entry.earned_points ?? '',
        entry_status: entry.entry_status === 'missing' ? 'graded' : entry.entry_status,
        feedback: '',
        expected_lock_version: entry.lock_version,
        reason: '',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(
            `/t/${tenant}/academics/grades/gradebooks/${book}/items/${item.id}/enrollments/${enrollment}`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    form.setData('expected_lock_version', entry.lock_version + 1);
                },
            },
        );
    };
    return (
        <form onSubmit={submit} className="flex min-w-[21rem] items-end gap-2">
            <label className="text-xs">
                Score / state
                <select
                    className={`${control} mt-1 block w-full`}
                    value={form.data.entry_status}
                    onChange={(e) => form.setData('entry_status', e.currentTarget.value)}
                >
                    <option value="graded">Graded</option>
                    <option value="missing">Missing</option>
                    <option value="incomplete">Incomplete</option>
                    <option value="excused">Excused</option>
                </select>
            </label>
            <label className="text-xs">
                Points
                <input
                    aria-label={`${item.title} score`}
                    className={`${control} mt-1 block w-24`}
                    inputMode="decimal"
                    value={form.data.earned_points}
                    onChange={(e) => form.setData('earned_points', e.currentTarget.value)}
                />
            </label>
            <button
                className="border-border focus-ring rounded-lg border px-3 py-2 text-sm font-semibold"
                disabled={form.processing}
            >
                Save
            </button>
            {Object.keys(form.errors).length > 0 && (
                <span role="alert" className="text-danger text-xs">
                    {Object.values(form.errors).join(' ')}
                </span>
            )}
        </form>
    );
}

function OverrideRequestForm({ tenant, book, entry }: { tenant: string; book: number; entry: RosterEntry }) {
    const form = useForm({ proposed_points: entry.earned_points ?? '', reason: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (entry.entry_id === null) return;
        form.post(`/t/${tenant}/academics/grades/gradebooks/${book}/entries/${entry.entry_id}/overrides`, {
            preserveScroll: true,
            onSuccess: () => form.reset('reason'),
        });
    };
    if (entry.entry_id === null || entry.entry_status !== 'graded') return null;
    return (
        <form onSubmit={submit} className="mt-2 flex flex-wrap items-end gap-2">
            <label className="text-xs">
                Proposed points
                <input
                    className={`${control} mt-1 block w-28`}
                    inputMode="decimal"
                    value={form.data.proposed_points}
                    onChange={(event) => form.setData('proposed_points', event.currentTarget.value)}
                    required
                />
            </label>
            <label className="min-w-48 flex-1 text-xs">
                Override reason
                <input
                    className={`${control} mt-1 block w-full`}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.currentTarget.value)}
                    minLength={5}
                    maxLength={500}
                    required
                />
            </label>
            <button
                className="border-border focus-ring rounded-lg border px-3 py-2 text-xs font-semibold"
                disabled={form.processing}
            >
                Request review
            </button>
            {Object.keys(form.errors).length > 0 && (
                <span role="alert" className="text-danger text-xs">
                    {Object.values(form.errors).join(' ')}
                </span>
            )}
        </form>
    );
}

function OverrideDecisionForm({
    tenant,
    book,
    override,
}: {
    tenant: string;
    book: number;
    override: Override;
}) {
    const form = useForm({ decision: 'approve', decision_reason: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/t/${tenant}/academics/grades/gradebooks/${book}/overrides/${override.id}/decision`, {
            preserveScroll: true,
        });
    };
    return (
        <form onSubmit={submit} className="mt-3 flex flex-wrap items-end gap-2">
            <label className="text-xs">
                Decision
                <select
                    className={`${control} mt-1 block`}
                    value={form.data.decision}
                    onChange={(event) => form.setData('decision', event.currentTarget.value)}
                >
                    <option value="approve">Approve</option>
                    <option value="reject">Reject</option>
                </select>
            </label>
            <label className="min-w-48 flex-1 text-xs">
                Decision reason
                <input
                    className={`${control} mt-1 block w-full`}
                    value={form.data.decision_reason}
                    onChange={(event) => form.setData('decision_reason', event.currentTarget.value)}
                    minLength={5}
                    maxLength={500}
                    required
                />
            </label>
            <button
                className="bg-brand-action focus-ring rounded-lg px-3 py-2 text-xs font-semibold text-white"
                disabled={form.processing}
            >
                Record decision
            </button>
            {Object.keys(form.errors).length > 0 && (
                <span role="alert" className="text-danger text-xs">
                    {Object.values(form.errors).join(' ')}
                </span>
            )}
        </form>
    );
}

export default function GradebookShow({ tenant, gradebook, roster, pendingOverrides, permissions }: Props) {
    const root = `/t/${tenant.slug}/academics/grades`;
    const transition = (action: 'submit' | 'approve' | 'publish') =>
        router.post(`${root}/gradebooks/${gradebook.id}/transitions/${action}`, {}, { preserveScroll: true });
    return (
        <WorkspaceLayout area="tenant" title="Course gradebook">
            <Head title="Course gradebook" />
            <PageHeader
                title={`${gradebook.course ?? 'Course'} · ${gradebook.section ?? 'Section'}`}
                description={`${gradebook.policy.name} · ${gradebook.status} · revision ${gradebook.version}`}
            />
            <section
                aria-label="Gradebook actions"
                className="border-border bg-surface mt-6 flex flex-wrap items-center gap-3 rounded-2xl border p-4"
            >
                {permissions.edit && gradebook.status === 'draft' && (
                    <ItemForm
                        tenant={tenant.slug}
                        gradebookId={gradebook.id}
                        categories={gradebook.policy.categories}
                    />
                )}
                {permissions.submit && gradebook.status === 'draft' && (
                    <button
                        className="bg-brand-action focus-ring rounded-lg px-4 py-2.5 text-sm font-semibold text-white"
                        onClick={() => transition('submit')}
                    >
                        Submit for review
                    </button>
                )}
                {permissions.review && gradebook.status === 'submitted' && (
                    <button
                        className="bg-brand-action focus-ring rounded-lg px-4 py-2.5 text-sm font-semibold text-white"
                        onClick={() => transition('approve')}
                    >
                        Approve gradebook
                    </button>
                )}
                {permissions.publish && gradebook.status === 'approved' && (
                    <button
                        className="bg-brand-action focus-ring rounded-lg px-4 py-2.5 text-sm font-semibold text-white"
                        onClick={() => transition('publish')}
                    >
                        Publish grades
                    </button>
                )}
                {!gradebook.items.length && (
                    <p className="text-text-secondary text-sm">
                        Add at least one assessment item before submitting.
                    </p>
                )}
            </section>
            {pendingOverrides.length > 0 && (
                <section
                    aria-labelledby="override-heading"
                    className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7"
                >
                    <h2 id="override-heading" className="text-lg font-semibold">
                        Pending grade overrides
                    </h2>
                    <div className="mt-4 space-y-4">
                        {pendingOverrides.map((override) => (
                            <article key={override.id} className="border-border rounded-xl border p-4">
                                <p className="font-medium">
                                    {override.student || 'Learner'}{' '}
                                    {override.student_number ? `(${override.student_number})` : ''}
                                </p>
                                <p className="text-text-secondary mt-1 text-sm">
                                    {override.original_points} → {override.proposed_points}. {override.reason}
                                </p>
                                {permissions.review && (
                                    <OverrideDecisionForm
                                        tenant={tenant.slug}
                                        book={gradebook.id}
                                        override={override}
                                    />
                                )}
                            </article>
                        ))}
                    </div>
                </section>
            )}
            <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                <h2 className="text-lg font-semibold">Assessment roster</h2>
                {permissions.edit &&
                    gradebook.status === 'draft' &&
                    gradebook.items.some((item) => item.source_type !== 'manual') && (
                        <div className="mt-4 space-y-2" aria-label="Assessment source synchronization">
                            {gradebook.items
                                .filter((item) => item.source_type !== 'manual')
                                .map((item) => (
                                    <div
                                        key={`source-${item.id}`}
                                        className="border-border flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 text-sm"
                                    >
                                        <span>
                                            {item.title} · {item.source_type} source #{item.source_key}
                                        </span>
                                        <button
                                            type="button"
                                            className="focus-ring border-border rounded-lg border px-3 py-2 text-xs font-medium"
                                            onClick={() =>
                                                router.post(
                                                    `${root}/gradebooks/${gradebook.id}/items/${item.id}/synchronize`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Sync released scores
                                        </button>
                                    </div>
                                ))}
                        </div>
                    )}
                {roster.length && gradebook.items.length ? (
                    <div className="mt-4 space-y-5">
                        {roster.map((learner) => (
                            <article
                                key={learner.enrollment.id}
                                className="border-border rounded-xl border p-4"
                            >
                                <div className="flex flex-wrap justify-between gap-2">
                                    <div>
                                        <h3 className="font-semibold">
                                            {learner.student.first_name} {learner.student.last_name}
                                        </h3>
                                        <p className="text-text-secondary text-sm">
                                            Learner {learner.student.number ?? learner.enrollment.student_id}
                                        </p>
                                    </div>
                                    <p className="text-sm">
                                        Calculated:{' '}
                                        {learner.calculation.percentage === null
                                            ? 'Incomplete'
                                            : `${learner.calculation.percentage}%`}
                                    </p>
                                </div>
                                <div className="mt-4 space-y-3">
                                    {gradebook.items.map((item) => {
                                        const entry = learner.entries.find((row) => row.item_id === item.id)!;
                                        return (
                                            <div
                                                key={item.id}
                                                className="flex flex-wrap items-center justify-between gap-3 border-t pt-3"
                                            >
                                                <p className="font-medium">
                                                    {item.title}
                                                    <span className="text-text-muted ml-2 text-xs">
                                                        Max {item.maximum_points}
                                                    </span>
                                                    {item.source_type !== 'manual' && (
                                                        <span className="text-text-muted ml-2 text-xs">
                                                            Source: {item.source_type} #{item.source_key}
                                                        </span>
                                                    )}
                                                </p>
                                                {permissions.edit && gradebook.status === 'draft' ? (
                                                    <div>
                                                        <EntryForm
                                                            tenant={tenant.slug}
                                                            book={gradebook.id}
                                                            item={item}
                                                            enrollment={learner.enrollment.id}
                                                            entry={entry}
                                                        />
                                                        <OverrideRequestForm
                                                            tenant={tenant.slug}
                                                            book={gradebook.id}
                                                            entry={entry}
                                                        />
                                                    </div>
                                                ) : (
                                                    <div>
                                                        <p className="text-sm">
                                                            {entry.entry_status === 'graded'
                                                                ? `${entry.earned_points} / ${item.maximum_points}`
                                                                : entry.entry_status}
                                                        </p>
                                                        {permissions.edit &&
                                                            ['draft', 'submitted', 'published'].includes(
                                                                gradebook.status,
                                                            ) && (
                                                                <OverrideRequestForm
                                                                    tenant={tenant.slug}
                                                                    book={gradebook.id}
                                                                    entry={entry}
                                                                />
                                                            )}
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            </article>
                        ))}
                    </div>
                ) : (
                    <p className="text-text-secondary mt-4 rounded-xl border border-dashed p-5 text-sm">
                        {roster.length
                            ? 'No assessment items have been configured.'
                            : 'No active learners are enrolled in this section.'}
                    </p>
                )}
            </section>
        </WorkspaceLayout>
    );
}
