import { Head } from '@inertiajs/react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Props = {
    summary: {
        student_name: string;
        denominator: number;
        numerator: number;
        percentage: number | null;
        entries: { id: number; date: string; course: string | null; status: string }[];
    };
};

export default function GuardianAttendance({ summary }: Props) {
    return (
        <WorkspaceLayout title="Learner attendance" area="tenant">
            <Head title="Learner attendance" />
            <div className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <header>
                    <p className="text-brand-link text-sm font-semibold uppercase">Guardian portal</p>
                    <h1 className="text-text-primary mt-1 text-3xl font-semibold">
                        Attendance for {summary.student_name}
                    </h1>
                    <p className="text-text-secondary mt-2">
                        Only attendance covered by your verified, active institution relationship and access
                        grant is shown.
                    </p>
                </header>
                <section aria-label="Attendance summary" className="surface rounded-2xl border p-5">
                    <h2 className="text-text-secondary text-sm">Attendance rate</h2>
                    <p className="text-text-primary mt-2 text-3xl font-semibold">
                        {summary.percentage === null ? '—' : `${summary.percentage}%`}
                    </p>
                    <p className="text-text-secondary mt-1 text-sm">
                        {summary.numerator} attended of {summary.denominator} expected meetings
                    </p>
                </section>
                <section
                    className="surface overflow-hidden rounded-2xl border p-5"
                    aria-labelledby="history-heading"
                >
                    <h2 id="history-heading" className="text-text-primary text-xl font-semibold">
                        Attendance history
                    </h2>
                    <div
                        aria-label="Attendance history table"
                        className="focus-ring mt-4 overflow-x-auto rounded"
                        role="region"
                        tabIndex={0}
                    >
                        <table className="w-full min-w-[35rem] text-left text-sm">
                            <thead>
                                <tr className="border-border text-text-secondary border-b">
                                    <th scope="col" className="py-3 pr-4">
                                        Date
                                    </th>
                                    <th scope="col" className="py-3 pr-4">
                                        Course
                                    </th>
                                    <th scope="col" className="py-3">
                                        Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {summary.entries.map((entry) => (
                                    <tr key={entry.id} className="border-border border-b last:border-0">
                                        <td className="py-3 pr-4">{entry.date}</td>
                                        <td className="py-3 pr-4">{entry.course ?? 'Course'}</td>
                                        <td className="py-3">{entry.status}</td>
                                    </tr>
                                ))}
                                {summary.entries.length === 0 && (
                                    <tr>
                                        <td className="text-text-secondary py-8 text-center" colSpan={3}>
                                            No attendance is available.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </WorkspaceLayout>
    );
}
