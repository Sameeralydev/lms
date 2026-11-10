import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Result = {
    student: string;
    course: string;
    period: string;
    section: string;
    grade: string;
    grade_points: string | null;
    credits: string | null;
    publication_number: number;
    published_at: string;
};

export default function GuardianGrades({ results }: { tenant: { slug: string }; results: Result[] }) {
    return (
        <WorkspaceLayout area="tenant" title="Learner grades">
            <Head title="Learner grades" />
            <PageHeader
                title="Learner grades"
                description="Published results for learners linked to your account through verified, active guardian authority. Unreleased marks are not shown."
            />
            {results.length ? (
                <div className="border-border bg-surface overflow-x-auto rounded-2xl border">
                    <table className="w-full min-w-[42rem] text-left text-sm">
                        <caption className="text-text-secondary p-4 text-left">
                            Current published grade snapshots for your verified learners
                        </caption>
                        <thead>
                            <tr className="text-text-muted border-border border-y">
                                {['Learner', 'Course', 'Period', 'Section', 'Grade', 'Published'].map(
                                    (heading) => (
                                        <th key={heading} scope="col" className="px-4 py-3 font-semibold">
                                            {heading}
                                        </th>
                                    ),
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {results.map((result, index) => (
                                <tr
                                    key={`${result.student}-${result.course}-${result.section}`}
                                    className="border-border border-b last:border-0"
                                >
                                    <td className="px-4 py-3">{result.student}</td>
                                    <td className="px-4 py-3">{result.course}</td>
                                    <td className="px-4 py-3">{result.period}</td>
                                    <td className="px-4 py-3">{result.section}</td>
                                    <td className="px-4 py-3 font-semibold">{result.grade}</td>
                                    <td className="px-4 py-3">
                                        <span>{new Date(result.published_at).toLocaleDateString()}</span>
                                        <span className="text-text-muted ml-2 text-xs">
                                            Version {result.publication_number}
                                        </span>
                                        <span className="sr-only">Result {index + 1}</span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <section
                    aria-label="No published learner grades"
                    className="border-border bg-surface rounded-2xl border p-6"
                >
                    <p className="font-medium">
                        No published grades are available for your verified learners.
                    </p>
                    <p className="text-text-secondary mt-2 text-sm">
                        Results appear here after the institution publishes them and while guardian access
                        remains active.
                    </p>
                </section>
            )}
        </WorkspaceLayout>
    );
}
