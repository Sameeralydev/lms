import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Props = {
    tenant: { slug: string };
    section: { id: number; code: string; course: string };
    roster: {
        data: { studentNumber: string; name: string; status: string }[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { q: string };
};

export default function InstructorRoster({ tenant, section, roster, filters }: Props) {
    return (
        <WorkspaceLayout area="tenant" title="Section roster">
            <Head title="Section roster" />
            <div className="space-y-5">
                <PageHeader
                    eyebrow="Instructor workspace"
                    title={`${section.course} · ${section.code}`}
                    description="Only confirmed learners in sections assigned to you are shown."
                />
                <a
                    href={`/t/${tenant.slug}/instructor/sections/${section.id}/roster.csv`}
                    className="border-border text-text-primary focus-visible:outline-focus inline-flex min-h-11 items-center rounded-lg border px-4 font-semibold focus-visible:outline-2"
                >
                    Export confirmed roster CSV
                </a>
                <form
                    method="get"
                    className="border-border bg-surface flex flex-wrap items-end gap-3 rounded-xl border p-4"
                    aria-label="Filter roster"
                >
                    <label className="text-text-primary text-sm">
                        Search student
                        <input
                            type="search"
                            name="q"
                            defaultValue={filters.q}
                            maxLength={100}
                            className="border-border bg-canvas mt-1 min-h-11 w-full rounded-lg border px-3 sm:w-80"
                        />
                    </label>
                    <button className="bg-brand-action min-h-11 rounded-lg px-4 font-semibold text-white">
                        Search roster
                    </button>
                </form>
                <div
                    className="border-border bg-surface overflow-x-auto rounded-xl border"
                    role="region"
                    aria-label="Confirmed learner roster table"
                    tabIndex={0}
                >
                    <table className="w-full min-w-[30rem] text-left text-sm">
                        <thead className="bg-surface-soft text-text-secondary">
                            <tr>
                                <th className="p-3">Student number</th>
                                <th className="p-3">Student</th>
                                <th className="p-3">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {roster.data.map((student) => (
                                <tr key={student.studentNumber} className="border-border border-t">
                                    <td className="text-text-primary p-3">{student.studentNumber}</td>
                                    <td className="text-text-primary p-3">{student.name}</td>
                                    <td className="text-text-secondary p-3">{student.status}</td>
                                </tr>
                            ))}
                            {roster.data.length === 0 && (
                                <tr>
                                    <td colSpan={3} className="text-text-muted p-5">
                                        No confirmed learners in this section.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <nav aria-label="Roster pages" className="flex flex-wrap gap-2">
                    {roster.links.map((link) => (
                        <a
                            key={link.label}
                            aria-current={link.active ? 'page' : undefined}
                            href={link.url ?? undefined}
                            className="border-border text-text-primary rounded border px-3 py-2 text-sm"
                        >
                            {link.label.replace('&laquo;', 'Previous').replace('&raquo;', 'Next')}
                        </a>
                    ))}
                </nav>
            </div>
        </WorkspaceLayout>
    );
}
