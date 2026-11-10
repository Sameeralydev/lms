import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Item = {
    id: number;
    type: 'practical' | 'oral';
    title: string;
    instructions: string | null;
    startsAt: string | null;
    endsAt: string | null;
    session: string;
    status: string;
};

export default function StudentExaminationComponents({ items }: { items: Item[] }) {
    return (
        <WorkspaceLayout area="tenant" title="My practical and oral exams">
            <Head title="My practical and oral exams" />
            <main className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title="My practical & oral exams"
                    description="Your published practical and viva schedule and completion status. Marks and examiner notes remain hidden until separately authorized for release."
                />
                {items.length === 0 ? (
                    <p role="status" className="surface text-text-secondary rounded-xl border p-6">
                        No practical or oral examinations are scheduled for your candidate registration.
                    </p>
                ) : (
                    <ul className="grid gap-4 md:grid-cols-2">
                        {items.map((item) => (
                            <li key={item.id} className="surface rounded-2xl border p-5">
                                <p className="text-text-secondary text-sm font-semibold uppercase">
                                    {item.type} · {item.session}
                                </p>
                                <h2 className="mt-1 text-xl font-semibold">{item.title}</h2>
                                <p className="mt-3">
                                    Status: <span className="font-medium">{item.status}</span>
                                </p>
                                {item.startsAt ? (
                                    <p className="text-text-secondary text-sm">
                                        Starts {new Date(item.startsAt).toLocaleString()}
                                    </p>
                                ) : null}
                                {item.endsAt ? (
                                    <p className="text-text-secondary text-sm">
                                        Ends {new Date(item.endsAt).toLocaleString()}
                                    </p>
                                ) : null}
                                {item.instructions ? (
                                    <details className="mt-4">
                                        <summary className="min-h-11 cursor-pointer font-medium">
                                            Candidate instructions
                                        </summary>
                                        <p className="mt-2 whitespace-pre-wrap">{item.instructions}</p>
                                    </details>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </main>
        </WorkspaceLayout>
    );
}
