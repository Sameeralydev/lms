import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Resource = {
    id: number;
    title: string;
    subtitle: string | null;
    authors: string | null;
    isbn: string | null;
    publisher: string | null;
    edition: string | null;
    language: string | null;
    category: string | null;
    subject: string | null;
    classification: string | null;
    description: string | null;
    publication_year: number | null;
    total_copies: number;
    available_copies: number;
};
type Loan = {
    id: number;
    copy_id: number;
    accession_number: string;
    title: string;
    member_number: string;
    borrower: string | null;
    due_at: string;
    overdue: boolean;
    renewal_count: number;
};
type Props = {
    tenant: { slug: string };
    resources: {
        data: Resource[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    resourceOptions: { id: number; title: string }[];
    loans: Loan[];
    reservations: {
        id: number;
        title: string;
        member_number: string;
        borrower: string | null;
        status: string;
        expires_at: string | null;
    }[];
    history: {
        event: string;
        at: string;
        actor: string | null;
        title: string | null;
        member_number: string | null;
    }[];
    locations: {
        id: number;
        name: string;
        campus_id: number | null;
        loan_days: number;
        max_active_loans: number;
        renewal_limit: number;
        reservation_limit: number;
    }[];
    members: {
        id: number;
        member_number: string;
        name: string | null;
        status: string;
        location_id: number;
    }[];
    copies: {
        id: number;
        accession_number: string;
        title: string;
        location: string;
        condition: string;
        status: string;
    }[];
    eligibleAccounts: { id: number; name: string }[];
    campuses: { id: number; name: string }[];
    categories: string[];
    filters: { q: string; category: string };
    permissions: {
        manage: boolean;
        manageInventory: boolean;
        circulate: boolean;
        manageMembers: boolean;
        managePolicy: boolean;
        ownMembershipId: number | null;
    };
    stats: { titles: number; active_loans: number; overdue_loans: number; reservations: number };
    campusScope: number | null;
};

const panel = 'border-border bg-surface rounded-2xl border p-4 sm:p-5';
const field =
    'border-border bg-background text-text placeholder:text-text-muted focus:border-primary w-full rounded-lg border px-3 py-2 text-sm focus:outline-none';
const button =
    'bg-primary text-on-primary hover:opacity-90 rounded-lg px-3 py-2 text-sm font-medium disabled:opacity-50';
const secondary = 'border-border hover:bg-surface-subtle rounded-lg border px-3 py-2 text-sm font-medium';

function postForm(event: FormEvent<HTMLFormElement>, url: string, method: 'post' | 'put' = 'post') {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    if (method === 'put') router.put(url, data, { preserveScroll: true });
    else router.post(url, data, { preserveScroll: true });
}

export default function LibraryIndex({
    tenant,
    resources,
    resourceOptions,
    loans,
    reservations,
    history,
    locations,
    members,
    copies,
    eligibleAccounts,
    campuses,
    categories,
    filters,
    permissions,
    stats,
    campusScope,
}: Props) {
    const [query, setQuery] = useState(filters.q);
    const [category, setCategory] = useState(filters.category);
    const [editing, setEditing] = useState<Resource | null>(null);
    const slug = tenant.slug;
    const base = `/t/${slug}/library`;
    const search = (event: FormEvent) => {
        event.preventDefault();
        router.get(base, { q: query, category }, { preserveState: true, replace: true });
    };
    const reserve = (id: number) =>
        router.post(`${base}/resources/${id}/reserve`, {}, { preserveScroll: true });

    return (
        <WorkspaceLayout title="Library" area="tenant">
            <Head title="Library management" />
            <PageHeader
                eyebrow="Learning resources"
                title="Library"
                description="Browse the catalog, manage physical copies, and keep circulation records in one place."
            />
            <div className="mt-4">
                <FormErrors />
            </div>

            <div className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {[
                    ['Catalog titles', stats.titles],
                    ['Active loans', stats.active_loans],
                    ['Overdue loans', stats.overdue_loans],
                    ['Active reservations', stats.reservations],
                ].map(([label, value]) => (
                    <div className={`${panel} p-4`} key={label}>
                        <p className="text-text-muted text-sm">{label}</p>
                        <p className="mt-1 text-2xl font-semibold">{value}</p>
                    </div>
                ))}
            </div>

            <section className={`${panel} mt-5`} aria-label="Library catalog">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold">Catalog</h2>
                        <p className="text-text-muted mt-1 text-sm">
                            {resources.total} titles in the current view. Availability reflects your campus
                            access.
                        </p>
                    </div>
                    <form
                        onSubmit={search}
                        className="grid w-full gap-2 sm:w-auto sm:grid-cols-[minmax(12rem,1fr)_12rem_auto]"
                    >
                        <input
                            className={field}
                            aria-label="Search catalog"
                            placeholder="Title, author, ISBN"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                        <select
                            className={field}
                            aria-label="Filter category"
                            value={category}
                            onChange={(e) => setCategory(e.target.value)}
                        >
                            <option value="">All categories</option>
                            {categories.map((value) => (
                                <option key={value}>{value}</option>
                            ))}
                        </select>
                        <button className={secondary}>Search</button>
                    </form>
                </div>
                <div className="mt-4 overflow-x-auto">
                    <table className="w-full min-w-[680px] text-left text-sm">
                        <thead className="text-text-muted border-border border-b">
                            <tr>
                                <th className="py-3 pr-4 font-medium">Title</th>
                                <th className="py-3 pr-4 font-medium">Category</th>
                                <th className="py-3 pr-4 font-medium">ISBN</th>
                                <th className="py-3 pr-4 font-medium">Copies</th>
                                <th className="py-3 font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody className="divide-border divide-y">
                            {resources.data.map((item) => (
                                <tr key={item.id}>
                                    <td className="py-3 pr-4">
                                        <p className="font-medium">{item.title}</p>
                                        <p className="text-text-muted text-xs">
                                            {item.authors || 'Author not listed'}
                                            {item.publication_year ? ` · ${item.publication_year}` : ''}
                                        </p>
                                    </td>
                                    <td className="py-3 pr-4">{item.category || 'Uncategorized'}</td>
                                    <td className="py-3 pr-4">{item.isbn || '—'}</td>
                                    <td className="py-3 pr-4">
                                        {item.available_copies} available / {item.total_copies} total
                                    </td>
                                    <td className="py-3">
                                        {permissions.manage && (
                                            <button
                                                type="button"
                                                className={secondary}
                                                onClick={() => setEditing(item)}
                                            >
                                                Edit
                                            </button>
                                        )}
                                        {permissions.ownMembershipId && item.available_copies === 0 && (
                                            <button
                                                type="button"
                                                className={secondary}
                                                onClick={() => reserve(item.id)}
                                            >
                                                Reserve
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {resources.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-text-muted py-8 text-center">
                                        No library resources match this search.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <nav className="mt-4 flex flex-wrap gap-2" aria-label="Catalog pages">
                    {resources.links.map((link) => (
                        <button
                            key={link.label}
                            disabled={!link.url}
                            className={link.active ? button : secondary}
                            onClick={() => link.url && router.visit(link.url, { preserveScroll: true })}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </nav>
            </section>

            {(permissions.manage || permissions.manageInventory) && (
                <section className="mt-5 grid gap-4 xl:grid-cols-2">
                    {permissions.manage && (
                        <form className={panel} onSubmit={(event) => postForm(event, `${base}/resources`)}>
                            <h2 className="mb-4 text-lg font-semibold">Add catalog record</h2>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <label className="text-sm">
                                    Title
                                    <input
                                        required
                                        name="title"
                                        className={`${field} mt-1`}
                                        maxLength={240}
                                    />
                                </label>
                                <label className="text-sm">
                                    Subtitle
                                    <input name="subtitle" className={`${field} mt-1`} maxLength={240} />
                                </label>
                                <label className="text-sm">
                                    Authors
                                    <input name="authors" className={`${field} mt-1`} maxLength={500} />
                                </label>
                                <label className="text-sm">
                                    ISBN
                                    <input name="isbn" className={`${field} mt-1`} maxLength={20} />
                                </label>
                                <label className="text-sm">
                                    Category
                                    <input name="category" className={`${field} mt-1`} maxLength={120} />
                                </label>
                                <label className="text-sm">
                                    Publisher
                                    <input name="publisher" className={`${field} mt-1`} maxLength={180} />
                                </label>
                                <label className="text-sm">
                                    Publication year
                                    <input
                                        name="publication_year"
                                        type="number"
                                        min="1000"
                                        max="2200"
                                        className={`${field} mt-1`}
                                    />
                                </label>
                                <label className="text-sm">
                                    Edition
                                    <input name="edition" className={`${field} mt-1`} maxLength={80} />
                                </label>
                                <label className="text-sm">
                                    Language
                                    <input name="language" className={`${field} mt-1`} maxLength={64} />
                                </label>
                                <label className="text-sm">
                                    Subject
                                    <input name="subject" className={`${field} mt-1`} maxLength={160} />
                                </label>
                                <label className="text-sm">
                                    Classification
                                    <input name="classification" className={`${field} mt-1`} maxLength={80} />
                                </label>
                                <label className="text-sm sm:col-span-2">
                                    Description
                                    <textarea
                                        name="description"
                                        className={`${field} mt-1`}
                                        rows={3}
                                        maxLength={5000}
                                    />
                                </label>
                            </div>
                            <button className={`${button} mt-4`}>Save catalog record</button>
                        </form>
                    )}
                    {permissions.manageInventory && (
                        <form className={panel} onSubmit={(event) => postForm(event, `${base}/copies`)}>
                            <h2 className="mb-4 text-lg font-semibold">Register physical copy</h2>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <label className="text-sm sm:col-span-2">
                                    Catalog title
                                    <select
                                        name="resource_id"
                                        className={`${field} mt-1`}
                                        required
                                        defaultValue=""
                                    >
                                        <option value="" disabled>
                                            Choose a title
                                        </option>
                                        {resourceOptions.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.title}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="text-sm sm:col-span-2">
                                    Library location
                                    <select
                                        name="library_location_id"
                                        className={`${field} mt-1`}
                                        required
                                        defaultValue=""
                                    >
                                        <option value="" disabled>
                                            Choose a location
                                        </option>
                                        {locations.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="text-sm">
                                    Accession number
                                    <input
                                        required
                                        name="accession_number"
                                        className={`${field} mt-1`}
                                        maxLength={64}
                                    />
                                </label>
                                <label className="text-sm">
                                    Barcode
                                    <input name="barcode" className={`${field} mt-1`} maxLength={80} />
                                </label>
                                <label className="text-sm">
                                    Shelf / rack
                                    <input name="shelf_location" className={`${field} mt-1`} maxLength={80} />
                                </label>
                                <label className="text-sm">
                                    Condition
                                    <select name="condition" className={`${field} mt-1`}>
                                        <option value="new">New</option>
                                        <option value="good">Good</option>
                                        <option value="worn">Worn</option>
                                        <option value="damaged">Damaged</option>
                                    </select>
                                </label>
                            </div>
                            <button
                                className={`${button} mt-4`}
                                disabled={locations.length === 0 || resourceOptions.length === 0}
                            >
                                Register copy
                            </button>
                        </form>
                    )}
                </section>
            )}

            {permissions.managePolicy && (
                <section className={`${panel} mt-5`}>
                    <h2 className="mb-4 text-lg font-semibold">
                        Create library location and borrowing policy
                    </h2>
                    <form
                        onSubmit={(event) => postForm(event, `${base}/locations`)}
                        className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                    >
                        <label className="text-sm">
                            Location name
                            <input required name="name" className={`${field} mt-1`} maxLength={120} />
                        </label>
                        <label className="text-sm">
                            Code
                            <input required name="code" className={`${field} mt-1`} maxLength={40} />
                        </label>
                        <label className="text-sm">
                            Campus
                            {campusScope !== null ? (
                                <input type="hidden" name="campus_id" value={campusScope} />
                            ) : (
                                <select name="campus_id" className={`${field} mt-1`} defaultValue="">
                                    <option value="">Institution wide</option>
                                    {campuses.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </label>
                        <label className="text-sm">
                            Loan period (days)
                            <input
                                required
                                name="loan_days"
                                type="number"
                                min="1"
                                max="365"
                                defaultValue="14"
                                className={`${field} mt-1`}
                            />
                        </label>
                        <label className="text-sm">
                            Active loan limit
                            <input
                                required
                                name="max_active_loans"
                                type="number"
                                min="1"
                                max="50"
                                defaultValue="5"
                                className={`${field} mt-1`}
                            />
                        </label>
                        <label className="text-sm">
                            Renewal limit
                            <input
                                required
                                name="renewal_limit"
                                type="number"
                                min="0"
                                max="20"
                                defaultValue="1"
                                className={`${field} mt-1`}
                            />
                        </label>
                        <label className="text-sm">
                            Reservation limit
                            <input
                                required
                                name="reservation_limit"
                                type="number"
                                min="0"
                                max="50"
                                defaultValue="3"
                                className={`${field} mt-1`}
                            />
                        </label>
                        <label className="flex items-center gap-2 self-end pb-2 text-sm">
                            <input type="hidden" name="allow_renewals" value="0" />
                            <input name="allow_renewals" type="checkbox" value="1" defaultChecked /> Allow
                            renewals
                        </label>
                        <div>
                            <button className={button}>Create location</button>
                        </div>
                    </form>
                    {locations.length > 0 && (
                        <div className="mt-4 grid gap-3 md:grid-cols-2">
                            {locations.map((location) => (
                                <form
                                    key={location.id}
                                    className="border-border grid gap-2 rounded-xl border p-3 sm:grid-cols-4"
                                    onSubmit={(event) =>
                                        postForm(event, `${base}/locations/${location.id}`, 'put')
                                    }
                                >
                                    <p className="font-medium sm:col-span-4">{location.name} · policy</p>
                                    <label className="text-xs">
                                        Loan days
                                        <input
                                            name="loan_days"
                                            type="number"
                                            min="1"
                                            max="365"
                                            defaultValue={location.loan_days}
                                            className={`${field} mt-1`}
                                        />
                                    </label>
                                    <label className="text-xs">
                                        Loan limit
                                        <input
                                            name="max_active_loans"
                                            type="number"
                                            min="1"
                                            max="50"
                                            defaultValue={location.max_active_loans}
                                            className={`${field} mt-1`}
                                        />
                                    </label>
                                    <label className="text-xs">
                                        Renewals
                                        <input
                                            name="renewal_limit"
                                            type="number"
                                            min="0"
                                            max="20"
                                            defaultValue={location.renewal_limit}
                                            className={`${field} mt-1`}
                                        />
                                    </label>
                                    <label className="text-xs">
                                        Holds
                                        <input
                                            name="reservation_limit"
                                            type="number"
                                            min="0"
                                            max="50"
                                            defaultValue={location.reservation_limit}
                                            className={`${field} mt-1`}
                                        />
                                    </label>
                                    <label className="flex items-center gap-2 text-xs sm:col-span-2">
                                        <input type="hidden" name="allow_renewals" value="0" />
                                        <input
                                            name="allow_renewals"
                                            type="checkbox"
                                            value="1"
                                            defaultChecked
                                        />{' '}
                                        Allow renewals
                                    </label>
                                    <button className={`${secondary} sm:col-span-2`}>Save policy</button>
                                </form>
                            ))}
                        </div>
                    )}
                </section>
            )}

            {permissions.manageInventory && (
                <section className={`${panel} mt-5`}>
                    <h2 className="mb-3 text-lg font-semibold">Copy inventory</h2>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-left text-sm">
                            <thead className="text-text-muted border-border border-b">
                                <tr>
                                    <th className="py-2 pr-3">Accession</th>
                                    <th className="py-2 pr-3">Title</th>
                                    <th className="py-2 pr-3">Location</th>
                                    <th className="py-2 pr-3">Condition</th>
                                    <th className="py-2 pr-3">Status</th>
                                    <th className="py-2">Inventory action</th>
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {copies.map((copy) => (
                                    <tr key={copy.id}>
                                        <td className="py-2 pr-3">{copy.accession_number}</td>
                                        <td className="py-2 pr-3">{copy.title}</td>
                                        <td className="py-2 pr-3">{copy.location}</td>
                                        <td className="py-2 pr-3">{copy.condition}</td>
                                        <td className="py-2 pr-3">{copy.status}</td>
                                        <td className="py-2">
                                            {['lost', 'damaged', 'maintenance', 'withdrawn'].map((status) => (
                                                <button
                                                    key={status}
                                                    type="button"
                                                    disabled={['issued', 'reserved', 'withdrawn'].includes(
                                                        copy.status,
                                                    )}
                                                    className={`${secondary} mr-1 mb-1`}
                                                    onClick={() =>
                                                        router.patch(
                                                            `${base}/copies/${copy.id}`,
                                                            {
                                                                status,
                                                                condition:
                                                                    status === 'damaged'
                                                                        ? 'damaged'
                                                                        : copy.condition,
                                                            },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    {status}
                                                </button>
                                            ))}
                                            {copy.status !== 'available' &&
                                                !['issued', 'reserved', 'withdrawn'].includes(
                                                    copy.status,
                                                ) && (
                                                    <button
                                                        className={secondary}
                                                        onClick={() =>
                                                            router.patch(
                                                                `${base}/copies/${copy.id}`,
                                                                {
                                                                    status: 'available',
                                                                    condition: copy.condition,
                                                                },
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                    >
                                                        Set available
                                                    </button>
                                                )}
                                        </td>
                                    </tr>
                                ))}
                                {copies.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-text-muted py-5 text-center">
                                            No copies registered in this library scope.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            {(permissions.circulate || permissions.ownMembershipId) && (
                <section
                    className={`mt-5 grid gap-4 ${permissions.circulate ? 'xl:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]' : ''}`}
                >
                    {permissions.circulate && (
                        <form className={panel} onSubmit={(event) => postForm(event, `${base}/checkout`)}>
                            <h2 className="mb-1 text-lg font-semibold">Check out</h2>
                            <p className="text-text-muted mb-4 text-sm">
                                Policy, membership, location and copy availability are checked before the
                                transaction is recorded.
                            </p>
                            <label className="mb-3 block text-sm">
                                Accession number
                                <input
                                    required
                                    name="accession_number"
                                    className={`${field} mt-1`}
                                    autoComplete="off"
                                />
                            </label>
                            <label className="mb-4 block text-sm">
                                Member number
                                <input
                                    required
                                    name="member_number"
                                    className={`${field} mt-1`}
                                    autoComplete="off"
                                />
                            </label>
                            <button className={button}>Record checkout</button>
                        </form>
                    )}
                    <div className={panel}>
                        <h2 className="mb-4 text-lg font-semibold">Active loans</h2>
                        <div className="space-y-3">
                            {loans.map((loan) => (
                                <div
                                    key={loan.id}
                                    className="border-border flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3"
                                >
                                    <div>
                                        <p className="font-medium">{loan.title}</p>
                                        <p className="text-text-muted text-sm">
                                            {loan.borrower || loan.member_number} · {loan.accession_number} ·
                                            Due {loan.due_at}
                                            {loan.overdue && (
                                                <span className="text-danger ml-2 font-medium">Overdue</span>
                                            )}
                                        </p>
                                    </div>
                                    <div className="flex gap-2">
                                        {permissions.circulate && (
                                            <button
                                                className={secondary}
                                                onClick={() =>
                                                    router.post(
                                                        `${base}/loans/${loan.id}/return`,
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Return
                                            </button>
                                        )}
                                        <button
                                            className={secondary}
                                            onClick={() =>
                                                router.post(
                                                    `${base}/loans/${loan.id}/renew`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Renew
                                        </button>
                                    </div>
                                </div>
                            ))}
                            {loans.length === 0 && (
                                <p className="text-text-muted py-4 text-sm">
                                    There are no active loans in this view.
                                </p>
                            )}
                        </div>
                    </div>
                </section>
            )}

            {(permissions.circulate || reservations.length > 0) && (
                <section className={`${panel} mt-5`}>
                    <h2 className="mb-3 text-lg font-semibold">Reservation queue</h2>
                    <div className="divide-border divide-y">
                        {reservations.map((item) => (
                            <div
                                key={item.id}
                                className="flex flex-wrap items-center justify-between gap-2 py-3"
                            >
                                <div>
                                    <p className="font-medium">{item.title}</p>
                                    <p className="text-text-muted text-sm">
                                        {item.borrower || item.member_number} · {item.status}
                                        {item.expires_at ? ` · Pickup by ${item.expires_at}` : ''}
                                    </p>
                                </div>
                                <button
                                    className={secondary}
                                    onClick={() =>
                                        router.post(
                                            `${base}/reservations/${item.id}/cancel`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Cancel hold
                                </button>
                            </div>
                        ))}
                        {reservations.length === 0 && (
                            <p className="text-text-muted py-4 text-sm">No active reservations.</p>
                        )}
                    </div>
                </section>
            )}

            {history.length > 0 && (
                <section className={`${panel} mt-5`}>
                    <h2 className="mb-3 text-lg font-semibold">Recent circulation history</h2>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[600px] text-left text-sm">
                            <thead className="text-text-muted border-border border-b">
                                <tr>
                                    <th className="py-2 pr-3">When</th>
                                    <th className="py-2 pr-3">Activity</th>
                                    <th className="py-2 pr-3">Title</th>
                                    <th className="py-2 pr-3">Member</th>
                                    <th className="py-2">Recorded by</th>
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {history.map((item, index) => (
                                    <tr key={`${item.event}-${item.at}-${index}`}>
                                        <td className="py-2 pr-3">{item.at}</td>
                                        <td className="py-2 pr-3">{item.event.replaceAll('_', ' ')}</td>
                                        <td className="py-2 pr-3">{item.title || '—'}</td>
                                        <td className="py-2 pr-3">{item.member_number || '—'}</td>
                                        <td className="py-2">{item.actor || 'System'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            {permissions.manageMembers && (
                <section className="mt-5 grid gap-4 xl:grid-cols-2">
                    <form className={panel} onSubmit={(event) => postForm(event, `${base}/members`)}>
                        <h2 className="mb-4 text-lg font-semibold">Activate library membership</h2>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="text-sm sm:col-span-2">
                                Institution member
                                <select
                                    name="membership_id"
                                    required
                                    className={`${field} mt-1`}
                                    defaultValue=""
                                >
                                    <option value="" disabled>
                                        Select account
                                    </option>
                                    {eligibleAccounts.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-sm sm:col-span-2">
                                Library location
                                <select
                                    name="library_location_id"
                                    required
                                    className={`${field} mt-1`}
                                    defaultValue=""
                                >
                                    <option value="" disabled>
                                        Select location
                                    </option>
                                    {locations.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-sm">
                                Member number
                                <input
                                    name="member_number"
                                    required
                                    maxLength={64}
                                    className={`${field} mt-1`}
                                />
                            </label>
                            <label className="text-sm">
                                Expires on
                                <input name="expires_on" type="date" className={`${field} mt-1`} />
                            </label>
                        </div>
                        <button
                            className={`${button} mt-4`}
                            disabled={eligibleAccounts.length === 0 || locations.length === 0}
                        >
                            Activate membership
                        </button>
                    </form>
                    <div className={panel}>
                        <h2 className="mb-4 text-lg font-semibold">Membership directory</h2>
                        <div className="space-y-2">
                            {members.map((member) => (
                                <div
                                    key={member.id}
                                    className="border-border flex items-center justify-between gap-3 rounded-lg border p-3"
                                >
                                    <div>
                                        <p className="font-medium">{member.name || member.member_number}</p>
                                        <p className="text-text-muted text-sm">
                                            {member.member_number} · {member.status}
                                        </p>
                                    </div>
                                    <button
                                        className={secondary}
                                        onClick={() =>
                                            router.patch(
                                                `${base}/members/${member.id}/status`,
                                                {
                                                    status:
                                                        member.status === 'active' ? 'suspended' : 'active',
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {member.status === 'active' ? 'Suspend' : 'Reactivate'}
                                    </button>
                                </div>
                            ))}
                            {members.length === 0 && (
                                <p className="text-text-muted py-4 text-sm">No active library memberships.</p>
                            )}
                        </div>
                    </div>
                </section>
            )}

            {editing && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="edit-resource-title"
                >
                    <form
                        className={`${panel} w-full max-w-xl`}
                        onSubmit={(event) => postForm(event, `${base}/resources/${editing.id}`, 'put')}
                    >
                        <h2 id="edit-resource-title" className="mb-4 text-lg font-semibold">
                            Edit catalog record
                        </h2>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="text-sm sm:col-span-2">
                                Title
                                <input
                                    required
                                    name="title"
                                    defaultValue={editing.title}
                                    className={`${field} mt-1`}
                                />
                            </label>
                            <label className="text-sm">
                                Subtitle
                                <input
                                    name="subtitle"
                                    defaultValue={editing.subtitle || ''}
                                    className={`${field} mt-1`}
                                />
                            </label>
                            <label className="text-sm">
                                Authors
                                <input
                                    name="authors"
                                    defaultValue={editing.authors || ''}
                                    className={`${field} mt-1`}
                                />
                            </label>
                            <label className="text-sm">
                                ISBN
                                <input
                                    name="isbn"
                                    defaultValue={editing.isbn || ''}
                                    className={`${field} mt-1`}
                                />
                            </label>
                            <label className="text-sm">
                                Publisher
                                <input
                                    name="publisher"
                                    defaultValue={editing.publisher || ''}
                                    className={`${field} mt-1`}
                                />
                            </label>
                            <label className="text-sm">
                                Edition
                                <input
                                    name="edition"
                                    defaultValue={editing.edition || ''}
                                    className={`${field} mt-1`}
                                />
                            </label>
                            <label className="text-sm">
                                Language
                                <input
                                    name="language"
                                    defaultValue={editing.language || ''}
                                    className={`${field} mt-1`}
                                />
                            </label>
                            <label className="text-sm">
                                Category
                                <input
                                    name="category"
                                    defaultValue={editing.category || ''}
                                    className={`${field} mt-1`}
                                />
                            </label>
                            <label className="text-sm">
                                Subject
                                <input
                                    name="subject"
                                    defaultValue={editing.subject || ''}
                                    className={`${field} mt-1`}
                                />
                            </label>
                            <label className="text-sm">
                                Classification
                                <input
                                    name="classification"
                                    defaultValue={editing.classification || ''}
                                    className={`${field} mt-1`}
                                />
                            </label>
                            <label className="text-sm">
                                Publication year
                                <input
                                    name="publication_year"
                                    type="number"
                                    defaultValue={editing.publication_year || ''}
                                    className={`${field} mt-1`}
                                />
                            </label>
                            <label className="text-sm sm:col-span-2">
                                Description
                                <textarea
                                    name="description"
                                    rows={3}
                                    defaultValue={editing.description || ''}
                                    className={`${field} mt-1`}
                                />
                            </label>
                        </div>
                        <div className="mt-4 flex justify-between gap-2">
                            <button
                                type="button"
                                className={`${secondary} text-danger`}
                                onClick={() => {
                                    router.patch(
                                        `${base}/resources/${editing.id}/status`,
                                        { status: 'archived' },
                                        { preserveScroll: true },
                                    );
                                    setEditing(null);
                                }}
                            >
                                Archive
                            </button>
                            <div className="flex gap-2">
                                <button type="button" className={secondary} onClick={() => setEditing(null)}>
                                    Close
                                </button>
                                <button className={button}>Save changes</button>
                            </div>
                        </div>
                    </form>
                </div>
            )}
        </WorkspaceLayout>
    );
}
