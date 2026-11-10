import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Props = {
    tenant: { slug: string };
    sections: {
        data: {
            id: number;
            code: string;
            course: string;
            offering: string;
            status: string;
            confirmed: number;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
    };
};

export default function InstructorSections({ tenant, sections }: Props) {
    return (
        <WorkspaceLayout area="tenant" title="My teaching sections">
            <Head title="My teaching sections" />
            <div className="space-y-5">
                <PageHeader
                    eyebrow="Instructor workspace"
                    title="My teaching sections"
                    description="Sections assigned to your active institutional membership."
                />
                {sections.data.length === 0 ? (
                    <p className="border-border bg-surface text-text-muted rounded-xl border p-5">
                        No sections are assigned to you.
                    </p>
                ) : (
                    <div className="border-border bg-surface overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[36rem] text-left text-sm">
                            <thead className="bg-surface-soft text-text-secondary">
                                <tr>
                                    <th className="p-3">Offering</th>
                                    <th className="p-3">Course</th>
                                    <th className="p-3">Section</th>
                                    <th className="p-3">Status</th>
                                    <th className="p-3">Confirmed</th>
                                    <th className="p-3">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {sections.data.map((section) => (
                                    <tr key={section.id} className="border-border border-t">
                                        <td className="p-3">{section.offering}</td>
                                        <td className="p-3">{section.course}</td>
                                        <td className="p-3">{section.code}</td>
                                        <td className="p-3">{section.status}</td>
                                        <td className="p-3">{section.confirmed}</td>
                                        <td className="p-3">
                                            <a
                                                className="text-brand-action focus-visible:outline-focus font-semibold underline focus-visible:outline-2"
                                                href={`/t/${tenant.slug}/instructor/sections/${section.id}/roster`}
                                            >
                                                Open roster
                                            </a>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <nav aria-label="Teaching sections pages" className="flex flex-wrap gap-2">
                    {sections.links.map((link) => (
                        <a
                            key={link.label}
                            aria-current={link.active ? 'page' : undefined}
                            href={link.url ?? undefined}
                            className="border-border text-text-primary rounded border px-3 py-2 text-sm"
                        >
                            {link.label}
                        </a>
                    ))}
                </nav>
            </div>
        </WorkspaceLayout>
    );
}
