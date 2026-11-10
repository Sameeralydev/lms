import { Head, router, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Props = {
    tenant: { slug: string };
    periods: { id: number; name: string; code: string }[];
    students: { id: number; student_number: string; legal_first_name: string; legal_last_name: string }[];
    policies: {
        id: number;
        name: string;
        policy_key: string;
        version: number;
        repeat_rule: string;
        status: string;
        bands: { label: string; minimum_gpa: string }[];
    }[];
};
const field = 'border-border bg-surface focus-ring min-h-11 w-full rounded-lg border px-3 py-2 text-sm';
const button =
    'bg-brand-action focus-ring rounded-lg px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60';

export default function GpaIndex({ tenant, periods, students, policies }: Props) {
    const root = `/t/${tenant.slug}/academics/gpa`;
    const policy = useForm({
        name: '',
        policy_key: '',
        repeat_rule: 'highest',
        exclude_pass_fail: true,
        bands: [
            { band_key: 'good', label: 'Good standing', minimum_gpa: '2.000' },
            { band_key: 'probation', label: 'Academic probation', minimum_gpa: '0.000' },
        ],
    });
    const calculation = useForm({ student_id: '', policy_id: '', academic_period_id: '' });
    const submitPolicy = (event: FormEvent) => {
        event.preventDefault();
        policy.post(`${root}/policies`, { preserveScroll: true, onSuccess: () => policy.reset() });
    };
    const calculate = (event: FormEvent) => {
        event.preventDefault();
        calculation.post(`${root}/snapshots`, { preserveScroll: true });
    };

    return (
        <WorkspaceLayout area="tenant" title="GPA and academic standing">
            <Head title="GPA and academic standing" />
            <PageHeader
                title="GPA and academic standing"
                description="Create versioned institutional rules and calculate immutable term or cumulative snapshots from released grades and confirmed credits."
            />
            <div className="grid gap-6 xl:grid-cols-2">
                <section
                    aria-labelledby="gpa-policy-heading"
                    className="border-border bg-surface rounded-2xl border p-5 sm:p-7"
                >
                    <h2 id="gpa-policy-heading" className="text-lg font-semibold">
                        Create policy version
                    </h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Only published grade-point outcomes contribute. Pass/fail entries without points are
                        excluded.
                    </p>
                    <form className="mt-5 space-y-4" onSubmit={submitPolicy}>
                        <label className="block text-sm font-medium">
                            Policy name
                            <input
                                className={`${field} mt-1`}
                                value={policy.data.name}
                                onChange={(e) => policy.setData('name', e.target.value)}
                                required
                            />
                        </label>
                        <label className="block text-sm font-medium">
                            Policy key
                            <input
                                className={`${field} mt-1`}
                                value={policy.data.policy_key}
                                onChange={(e) => policy.setData('policy_key', e.target.value)}
                                required
                            />
                        </label>
                        <label className="block text-sm font-medium">
                            Repeated course rule
                            <select
                                className={`${field} mt-1`}
                                value={policy.data.repeat_rule}
                                onChange={(e) => policy.setData('repeat_rule', e.target.value)}
                            >
                                <option value="highest">Use highest grade points</option>
                                <option value="latest">Use latest published grade</option>
                                <option value="all">Count every attempt</option>
                            </select>
                        </label>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="block text-sm font-medium">
                                Standing label
                                <input
                                    className={`${field} mt-1`}
                                    value={policy.data.bands[0].label}
                                    onChange={(e) =>
                                        policy.setData('bands', [
                                            { ...policy.data.bands[0], label: e.target.value },
                                            policy.data.bands[1],
                                        ])
                                    }
                                    required
                                />
                            </label>
                            <label className="block text-sm font-medium">
                                Minimum GPA
                                <input
                                    className={`${field} mt-1`}
                                    type="number"
                                    min="0"
                                    max="20"
                                    step="0.001"
                                    value={policy.data.bands[0].minimum_gpa}
                                    onChange={(e) =>
                                        policy.setData('bands', [
                                            { ...policy.data.bands[0], minimum_gpa: e.target.value },
                                            policy.data.bands[1],
                                        ])
                                    }
                                    required
                                />
                            </label>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="block text-sm font-medium">
                                Lower standing label
                                <input
                                    className={`${field} mt-1`}
                                    value={policy.data.bands[1].label}
                                    onChange={(e) =>
                                        policy.setData('bands', [
                                            policy.data.bands[0],
                                            { ...policy.data.bands[1], label: e.target.value },
                                        ])
                                    }
                                    required
                                />
                            </label>
                            <label className="block text-sm font-medium">
                                Minimum GPA
                                <input
                                    className={`${field} mt-1`}
                                    type="number"
                                    min="0"
                                    max="20"
                                    step="0.001"
                                    value={policy.data.bands[1].minimum_gpa}
                                    onChange={(e) =>
                                        policy.setData('bands', [
                                            policy.data.bands[0],
                                            { ...policy.data.bands[1], minimum_gpa: e.target.value },
                                        ])
                                    }
                                    required
                                />
                            </label>
                        </div>
                        <button className={button} disabled={policy.processing}>
                            Save policy draft
                        </button>
                    </form>
                </section>
                <section
                    aria-labelledby="gpa-calculate-heading"
                    className="border-border bg-surface rounded-2xl border p-5 sm:p-7"
                >
                    <h2 id="gpa-calculate-heading" className="text-lg font-semibold">
                        Calculate academic record
                    </h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        A blank period calculates cumulative GPA. Repeating the same source data is
                        idempotent.
                    </p>
                    <form className="mt-5 space-y-4" onSubmit={calculate}>
                        <label className="block text-sm font-medium">
                            Student
                            <select
                                className={`${field} mt-1`}
                                value={calculation.data.student_id}
                                onChange={(e) => calculation.setData('student_id', e.target.value)}
                                required
                            >
                                <option value="">Choose a student</option>
                                {students.map((student) => (
                                    <option key={student.id} value={student.id}>
                                        {student.student_number} · {student.legal_first_name}{' '}
                                        {student.legal_last_name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="block text-sm font-medium">
                            Active GPA policy
                            <select
                                className={`${field} mt-1`}
                                value={calculation.data.policy_id}
                                onChange={(e) => calculation.setData('policy_id', e.target.value)}
                                required
                            >
                                <option value="">Choose a policy</option>
                                {policies
                                    .filter((item) => item.status === 'active')
                                    .map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name} v{item.version}
                                        </option>
                                    ))}
                            </select>
                        </label>
                        <label className="block text-sm font-medium">
                            Period (leave blank for cumulative)
                            <select
                                className={`${field} mt-1`}
                                value={calculation.data.academic_period_id}
                                onChange={(e) => calculation.setData('academic_period_id', e.target.value)}
                            >
                                <option value="">Cumulative</option>
                                {periods.map((period) => (
                                    <option key={period.id} value={period.id}>
                                        {period.name} · {period.code}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <button className={button} disabled={calculation.processing}>
                            Calculate snapshot
                        </button>
                    </form>
                </section>
            </div>
            <section
                aria-labelledby="gpa-policies-heading"
                className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7"
            >
                <h2 id="gpa-policies-heading" className="text-lg font-semibold">
                    Institution policy versions
                </h2>
                {policies.length ? (
                    <ul className="mt-3 divide-y">
                        {policies.map((item) => (
                            <li
                                key={item.id}
                                className="flex flex-wrap items-center justify-between gap-3 py-3"
                            >
                                <div>
                                    <p className="font-medium">
                                        {item.name} · v{item.version}
                                    </p>
                                    <p className="text-text-secondary text-sm">
                                        {item.status} · repeated course: {item.repeat_rule} ·{' '}
                                        {item.bands
                                            .map((band) => `${band.label} ≥ ${band.minimum_gpa}`)
                                            .join(' / ')}
                                    </p>
                                </div>
                                {item.status === 'draft' && (
                                    <button
                                        type="button"
                                        className={button}
                                        onClick={() =>
                                            window.confirm('Activate this versioned GPA policy?') &&
                                            router.post(`${root}/policies/${item.id}/activate`)
                                        }
                                    >
                                        Activate policy
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-text-secondary mt-3 text-sm">
                        No GPA policy versions have been created.
                    </p>
                )}
            </section>
        </WorkspaceLayout>
    );
}
