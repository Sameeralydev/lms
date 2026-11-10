import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Entry = {
    id: number;
    earned_points: string | null;
    entry_status: string;
    item: { title: string; maximum_points: string; category: { name: string } };
    gradebook: {
        status: string;
        section: { section_code: string; offering: { course: { title: string } } };
        policy: { name: string };
    };
};
type OfficialResult = {
    id: number;
    percentage: string;
    scale_result: string;
    grade_points: string | null;
    passed: boolean | null;
    publication_number: number;
    published_at: string;
    course: string;
    section: string;
};

export default function MyGrades({
    entries,
    results,
}: {
    tenant: { slug: string };
    entries: Entry[];
    results: OfficialResult[];
}) {
    const published = entries.filter((entry) => entry.gradebook.status === 'published');
    return (
        <WorkspaceLayout area="tenant" title="My grades">
            <Head title="My grades" />
            <PageHeader
                title="My grades"
                description="Official assessment entries released by your institution. Draft and unreleased marks are never shown here."
            />
            <section
                aria-label="Official published results"
                className="border-border bg-surface mb-6 rounded-2xl border p-5 sm:p-7"
            >
                <h2 className="text-lg font-semibold">Official results</h2>
                {results.length ? (
                    <ul className="mt-3 divide-y">
                        {results.map((result) => (
                            <li key={result.id} className="flex flex-wrap justify-between gap-2 py-3">
                                <span>
                                    {result.course} · {result.section}
                                </span>
                                <span className="font-semibold">
                                    {result.scale_result}
                                    {result.grade_points !== null && ` · ${result.grade_points} points`}
                                    {result.passed !== null && ` · ${result.passed ? 'Pass' : 'Fail'}`}
                                </span>
                                <span className="text-text-muted text-sm">
                                    Publication {result.publication_number} ·{' '}
                                    {new Date(result.published_at).toLocaleDateString()}
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-text-secondary mt-3 text-sm">
                        No official result publication is available yet.
                    </p>
                )}
            </section>
            <section
                aria-label="Published grades"
                className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7"
            >
                {published.length ? (
                    <div
                        className="overflow-x-auto"
                        role="region"
                        aria-label="Published grade details, horizontally scrollable"
                        tabIndex={0}
                    >
                        <table className="w-full min-w-[40rem] text-left text-sm">
                            <thead>
                                <tr className="text-text-muted border-border border-b">
                                    <th scope="col" className="py-3 pr-4">
                                        Course
                                    </th>
                                    <th scope="col" className="py-3 pr-4">
                                        Section
                                    </th>
                                    <th scope="col" className="py-3 pr-4">
                                        Assessment
                                    </th>
                                    <th scope="col" className="py-3 pr-4">
                                        Category
                                    </th>
                                    <th scope="col" className="py-3 pr-4">
                                        Result
                                    </th>
                                    <th scope="col" className="py-3">
                                        Policy
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {published.map((entry) => (
                                    <tr key={entry.id} className="border-border border-b last:border-0">
                                        <td className="py-3 pr-4">
                                            {entry.gradebook.section.offering.course.title}
                                        </td>
                                        <td className="py-3 pr-4">{entry.gradebook.section.section_code}</td>
                                        <td className="py-3 pr-4">{entry.item.title}</td>
                                        <td className="py-3 pr-4">{entry.item.category.name}</td>
                                        <td className="py-3 pr-4">
                                            {entry.entry_status === 'graded'
                                                ? `${entry.earned_points} / ${entry.item.maximum_points}`
                                                : entry.entry_status}
                                        </td>
                                        <td className="py-3">{entry.gradebook.policy.name}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <p className="text-text-secondary rounded-xl border border-dashed p-6 text-sm">
                        No grades have been released to your account yet.
                    </p>
                )}
            </section>
        </WorkspaceLayout>
    );
}
