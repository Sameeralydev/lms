import { Head, Link, router, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Section = { id: number; code: string; course: string | null; period_id: number };
type Policy = {
    id: number;
    name: string;
    status: string;
    scale_type: string;
    version: number;
    period_id: number;
};
type GradeScaleBandForm = {
    band_key: string;
    label: string;
    minimum_percentage_basis_points: number;
    grade_points: string | null;
    passing: boolean | null;
};
type Gradebook = {
    id: number;
    status: string;
    section: string | null;
    course: string | null;
    policy: string | null;
    period_id: number;
};
type Props = {
    tenant: { slug: string };
    periods: { id: number; name: string; code: string }[];
    campuses: { id: number; name: string; code: string }[];
    campusScope: number | null;
    sections: Section[];
    policies: Policy[];
    gradebooks: Gradebook[];
    permissions: { managePolicy: boolean; manageBook: boolean; manageAll: boolean };
};

const field = 'border-border bg-surface focus-ring min-h-11 w-full rounded-lg border px-3 py-2 text-sm';
const button =
    'bg-brand-action focus-ring rounded-lg px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60';

export default function GradebooksIndex({
    tenant,
    periods,
    campuses,
    campusScope,
    sections,
    policies,
    gradebooks,
    permissions,
}: Props) {
    const root = `/t/${tenant.slug}/academics/grades`;
    const policyForm = useForm({
        name: '',
        policy_key: '',
        academic_period_id: '',
        campus_id: campusScope === null ? '' : String(campusScope),
        scale_type: 'percentage',
        scale_bands: [] as GradeScaleBandForm[],
        rounding_mode: 'half_up',
        precision: 2,
        effective_from: new Date().toISOString().slice(0, 10),
        effective_until: '',
        categories: [
            { category_key: 'coursework', name: 'Coursework', weight_basis_points: 4000, required: true },
            { category_key: 'examination', name: 'Examination', weight_basis_points: 6000, required: true },
        ],
    });
    const bookForm = useForm({ section_id: '', policy_id: '' });
    const submitPolicy = (event: FormEvent) => {
        event.preventDefault();
        policyForm.post(`${root}/policies`, { preserveScroll: true });
    };
    const changeScaleType = (scaleType: string) => {
        policyForm.setData('scale_type', scaleType);
        if (scaleType === 'letter') {
            policyForm.setData('scale_bands', [
                {
                    band_key: 'A',
                    label: 'A',
                    minimum_percentage_basis_points: 9000,
                    grade_points: '4.000',
                    passing: null,
                },
                {
                    band_key: 'B',
                    label: 'B',
                    minimum_percentage_basis_points: 8000,
                    grade_points: '3.000',
                    passing: null,
                },
                {
                    band_key: 'C',
                    label: 'C',
                    minimum_percentage_basis_points: 7000,
                    grade_points: '2.000',
                    passing: null,
                },
                {
                    band_key: 'F',
                    label: 'F',
                    minimum_percentage_basis_points: 0,
                    grade_points: '0.000',
                    passing: null,
                },
            ]);
        } else if (scaleType === 'pass_fail') {
            policyForm.setData('scale_bands', [
                {
                    band_key: 'pass',
                    label: 'Pass',
                    minimum_percentage_basis_points: 5000,
                    grade_points: null,
                    passing: true,
                },
                {
                    band_key: 'fail',
                    label: 'Fail',
                    minimum_percentage_basis_points: 0,
                    grade_points: null,
                    passing: false,
                },
            ]);
        } else {
            policyForm.setData('scale_bands', []);
        }
    };
    const submitBook = (event: FormEvent) => {
        event.preventDefault();
        bookForm.post(`${root}/gradebooks`, { preserveScroll: true });
    };

    return (
        <WorkspaceLayout area="tenant" title="Gradebooks">
            <Head title="Gradebooks" />
            <PageHeader
                title="Gradebooks"
                description="Configure weighted grading policies, manage scoped course gradebooks, and publish reviewed results."
            />
            {permissions.managePolicy && (
                <section
                    aria-labelledby="policy-heading"
                    className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7"
                >
                    <h2 id="policy-heading" className="text-lg font-semibold">
                        Create grading policy
                    </h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Category weights must total exactly 100.00% before activation.
                    </p>
                    <form onSubmit={submitPolicy} className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <label className="text-sm font-medium">
                            Policy name
                            <input
                                className={`${field} mt-1`}
                                value={policyForm.data.name}
                                onChange={(e) => policyForm.setData('name', e.currentTarget.value)}
                                required
                            />
                        </label>
                        <label className="text-sm font-medium">
                            Policy key
                            <input
                                className={`${field} mt-1`}
                                value={policyForm.data.policy_key}
                                onChange={(e) => policyForm.setData('policy_key', e.currentTarget.value)}
                                required
                            />
                        </label>
                        <label className="text-sm font-medium">
                            Academic period
                            <select
                                className={`${field} mt-1`}
                                value={policyForm.data.academic_period_id}
                                onChange={(e) =>
                                    policyForm.setData('academic_period_id', e.currentTarget.value)
                                }
                                required
                            >
                                <option value="">Select period</option>
                                {periods.map((period) => (
                                    <option key={period.id} value={period.id}>
                                        {period.name} · {period.code}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-medium">
                            Campus scope
                            <select
                                className={`${field} mt-1`}
                                value={policyForm.data.campus_id}
                                onChange={(e) => policyForm.setData('campus_id', e.currentTarget.value)}
                            >
                                {campusScope === null && <option value="">Institution-wide</option>}
                                {campuses.map((campus) => (
                                    <option key={campus.id} value={campus.id}>
                                        {campus.name} · {campus.code}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-medium">
                            Scale
                            <select
                                className={`${field} mt-1`}
                                value={policyForm.data.scale_type}
                                onChange={(e) => changeScaleType(e.currentTarget.value)}
                            >
                                <option value="percentage">Percentage</option>
                                <option value="numeric">Numeric (0–100)</option>
                                <option value="letter">Letter with grade points</option>
                                <option value="pass_fail">Pass / fail</option>
                            </select>
                        </label>
                        {policyForm.data.scale_bands.length > 0 && (
                            <fieldset className="border-border rounded-xl border p-3 sm:col-span-2 xl:col-span-4">
                                <legend className="px-1 text-sm font-semibold">
                                    Institution grade bands
                                </legend>
                                <p className="text-text-secondary mb-3 text-sm">
                                    Each band applies at or above its threshold. Include a zero-threshold band
                                    to cover every result.
                                </p>
                                <div className="grid gap-3">
                                    {policyForm.data.scale_bands.map((band, index) => (
                                        <div key={band.band_key} className="grid gap-3 sm:grid-cols-4">
                                            <label className="text-sm">
                                                Band key
                                                <input
                                                    className={`${field} mt-1`}
                                                    value={band.band_key}
                                                    onChange={(e) =>
                                                        policyForm.setData(
                                                            'scale_bands',
                                                            policyForm.data.scale_bands.map((item, i) =>
                                                                i === index
                                                                    ? {
                                                                          ...item,
                                                                          band_key: e.currentTarget.value,
                                                                      }
                                                                    : item,
                                                            ),
                                                        )
                                                    }
                                                    required
                                                />
                                            </label>
                                            <label className="text-sm">
                                                Result label
                                                <input
                                                    className={`${field} mt-1`}
                                                    value={band.label}
                                                    onChange={(e) =>
                                                        policyForm.setData(
                                                            'scale_bands',
                                                            policyForm.data.scale_bands.map((item, i) =>
                                                                i === index
                                                                    ? {
                                                                          ...item,
                                                                          label: e.currentTarget.value,
                                                                      }
                                                                    : item,
                                                            ),
                                                        )
                                                    }
                                                    required
                                                />
                                            </label>
                                            <label className="text-sm">
                                                Minimum percentage (basis points)
                                                <input
                                                    className={`${field} mt-1`}
                                                    type="number"
                                                    min="0"
                                                    max="10000"
                                                    step="1"
                                                    value={band.minimum_percentage_basis_points}
                                                    onChange={(e) =>
                                                        policyForm.setData(
                                                            'scale_bands',
                                                            policyForm.data.scale_bands.map((item, i) =>
                                                                i === index
                                                                    ? {
                                                                          ...item,
                                                                          minimum_percentage_basis_points:
                                                                              Number(e.currentTarget.value),
                                                                      }
                                                                    : item,
                                                            ),
                                                        )
                                                    }
                                                    required
                                                />
                                            </label>
                                            {policyForm.data.scale_type === 'letter' ? (
                                                <label className="text-sm">
                                                    Grade points
                                                    <input
                                                        className={`${field} mt-1`}
                                                        inputMode="decimal"
                                                        value={band.grade_points ?? ''}
                                                        onChange={(e) =>
                                                            policyForm.setData(
                                                                'scale_bands',
                                                                policyForm.data.scale_bands.map((item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              grade_points:
                                                                                  e.currentTarget.value,
                                                                          }
                                                                        : item,
                                                                ),
                                                            )
                                                        }
                                                        required
                                                    />
                                                </label>
                                            ) : (
                                                <label className="text-sm">
                                                    Outcome
                                                    <select
                                                        className={`${field} mt-1`}
                                                        value={band.passing ? 'pass' : 'fail'}
                                                        onChange={(e) =>
                                                            policyForm.setData(
                                                                'scale_bands',
                                                                policyForm.data.scale_bands.map((item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              passing:
                                                                                  e.currentTarget.value ===
                                                                                  'pass',
                                                                          }
                                                                        : item,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <option value="pass">Pass</option>
                                                        <option value="fail">Fail</option>
                                                    </select>
                                                </label>
                                            )}
                                        </div>
                                    ))}
                                </div>
                                <button
                                    type="button"
                                    className={`${button} mt-3`}
                                    disabled={policyForm.data.scale_bands.length >= 20}
                                    onClick={() => {
                                        const index = policyForm.data.scale_bands.length + 1;
                                        const thresholds = new Set(
                                            policyForm.data.scale_bands.map(
                                                (band) => band.minimum_percentage_basis_points,
                                            ),
                                        );
                                        let minimum = 500;
                                        while (thresholds.has(minimum) && minimum < 10000) minimum += 500;
                                        policyForm.setData('scale_bands', [
                                            ...policyForm.data.scale_bands,
                                            policyForm.data.scale_type === 'letter'
                                                ? {
                                                      band_key: `band-${index}`,
                                                      label: `Band ${index}`,
                                                      minimum_percentage_basis_points: minimum,
                                                      grade_points: '0.000',
                                                      passing: null,
                                                  }
                                                : {
                                                      band_key: `band-${index}`,
                                                      label: `Outcome ${index}`,
                                                      minimum_percentage_basis_points: minimum,
                                                      grade_points: null,
                                                      passing: false,
                                                  },
                                        ]);
                                    }}
                                >
                                    Add grade band
                                </button>
                            </fieldset>
                        )}
                        {policyForm.data.categories.map((category, index) => (
                            <fieldset
                                key={index}
                                className="border-border rounded-xl border p-3 sm:col-span-2"
                            >
                                <legend className="px-1 text-sm font-semibold">Category {index + 1}</legend>
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <label className="text-sm">
                                        Key
                                        <input
                                            className={`${field} mt-1`}
                                            value={category.category_key}
                                            onChange={(e) =>
                                                policyForm.setData(
                                                    'categories',
                                                    policyForm.data.categories.map((item, i) =>
                                                        i === index
                                                            ? { ...item, category_key: e.currentTarget.value }
                                                            : item,
                                                    ),
                                                )
                                            }
                                        />
                                    </label>
                                    <label className="text-sm">
                                        Name
                                        <input
                                            className={`${field} mt-1`}
                                            value={category.name}
                                            onChange={(e) =>
                                                policyForm.setData(
                                                    'categories',
                                                    policyForm.data.categories.map((item, i) =>
                                                        i === index
                                                            ? { ...item, name: e.currentTarget.value }
                                                            : item,
                                                    ),
                                                )
                                            }
                                        />
                                    </label>
                                    <label className="text-sm">
                                        Weight (basis points)
                                        <input
                                            type="number"
                                            min="0"
                                            max="10000"
                                            className={`${field} mt-1`}
                                            value={category.weight_basis_points}
                                            onChange={(e) =>
                                                policyForm.setData(
                                                    'categories',
                                                    policyForm.data.categories.map((item, i) =>
                                                        i === index
                                                            ? {
                                                                  ...item,
                                                                  weight_basis_points: Number(
                                                                      e.currentTarget.value,
                                                                  ),
                                                              }
                                                            : item,
                                                    ),
                                                )
                                            }
                                        />
                                    </label>
                                </div>
                            </fieldset>
                        ))}
                        <label className="text-sm font-medium">
                            Effective from
                            <input
                                type="date"
                                className={`${field} mt-1`}
                                value={policyForm.data.effective_from}
                                onChange={(e) => policyForm.setData('effective_from', e.currentTarget.value)}
                                required
                            />
                        </label>
                        <label className="text-sm font-medium">
                            Effective until
                            <input
                                type="date"
                                className={`${field} mt-1`}
                                value={policyForm.data.effective_until}
                                onChange={(e) => policyForm.setData('effective_until', e.currentTarget.value)}
                            />
                        </label>
                        <div className="sm:col-span-2 xl:col-span-4">
                            <p role="alert" className="text-danger text-sm">
                                {Object.values(policyForm.errors).join(' ')}
                            </p>
                            <button className={button} disabled={policyForm.processing}>
                                Save policy draft
                            </button>
                        </div>
                    </form>
                </section>
            )}
            <section
                aria-labelledby="grade-policy-list"
                className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7"
            >
                <h2 id="grade-policy-list" className="text-lg font-semibold">
                    Institution grading policies
                </h2>
                {policies.length ? (
                    <ul className="mt-3 divide-y">
                        {policies.map((policy) => (
                            <li
                                key={policy.id}
                                className="flex flex-wrap items-center justify-between gap-3 py-3"
                            >
                                <div>
                                    <p className="font-medium">{policy.name}</p>
                                    <p className="text-text-secondary text-sm">
                                        {policy.scale_type.replace('_', ' ')} · v{policy.version} ·{' '}
                                        {policy.status}
                                    </p>
                                </div>
                                {permissions.managePolicy && policy.status === 'draft' && (
                                    <button
                                        className={`${button} min-h-10 px-3 py-2`}
                                        onClick={() =>
                                            router.post(
                                                `${root}/policies/${policy.id}/activate`,
                                                {},
                                                { preserveScroll: true },
                                            )
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
                        No grading policies have been configured.
                    </p>
                )}
            </section>
            {permissions.manageBook && (
                <section
                    aria-labelledby="book-heading"
                    className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7"
                >
                    <h2 id="book-heading" className="text-lg font-semibold">
                        Open a course gradebook
                    </h2>
                    {sections.length && policies.some((policy) => policy.status === 'active') ? (
                        <form onSubmit={submitBook} className="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                            <label className="text-sm font-medium">
                                Assigned section
                                <select
                                    className={`${field} mt-1`}
                                    value={bookForm.data.section_id}
                                    onChange={(e) => bookForm.setData('section_id', e.currentTarget.value)}
                                    required
                                >
                                    <option value="">Select section</option>
                                    {sections.map((section) => (
                                        <option key={section.id} value={section.id}>
                                            {section.code} · {section.course ?? 'Course'}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-sm font-medium">
                                Active policy
                                <select
                                    className={`${field} mt-1`}
                                    value={bookForm.data.policy_id}
                                    onChange={(e) => bookForm.setData('policy_id', e.currentTarget.value)}
                                    required
                                >
                                    <option value="">Select policy</option>
                                    {policies
                                        .filter((policy) => policy.status === 'active')
                                        .map((policy) => (
                                            <option key={policy.id} value={policy.id}>
                                                {policy.name} · v{policy.version} · {policy.scale_type}
                                            </option>
                                        ))}
                                </select>
                            </label>
                            <button className={`${button} self-end`} disabled={bookForm.processing}>
                                Create gradebook
                            </button>
                            <p role="alert" className="text-danger text-sm sm:col-span-3">
                                {Object.values(bookForm.errors).join(' ')}
                            </p>
                        </form>
                    ) : (
                        <p className="text-text-secondary mt-3 text-sm">
                            An assigned section and an active grading policy are required.
                        </p>
                    )}
                </section>
            )}
            <section
                aria-labelledby="books-list"
                className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7"
            >
                <h2 id="books-list" className="text-lg font-semibold">
                    Course gradebooks
                </h2>
                {gradebooks.length ? (
                    <div className="mt-4 divide-y">
                        {gradebooks.map((book) => (
                            <article
                                key={book.id}
                                className="flex flex-wrap items-center justify-between gap-3 py-4"
                            >
                                <div>
                                    <h3 className="font-semibold">
                                        {book.course ?? 'Course'} · {book.section ?? 'Section'}
                                    </h3>
                                    <p className="text-text-secondary mt-1 text-sm">
                                        {book.policy} · <span className="capitalize">{book.status}</span>
                                    </p>
                                </div>
                                <Link
                                    className="text-brand-link focus-ring rounded px-2 py-1 text-sm font-semibold"
                                    href={`${root}/gradebooks/${book.id}`}
                                >
                                    Open gradebook
                                </Link>
                            </article>
                        ))}
                    </div>
                ) : (
                    <p className="text-text-secondary mt-4 rounded-xl border border-dashed p-5 text-sm">
                        No gradebooks are available in your authorized section scope.
                    </p>
                )}
            </section>
        </WorkspaceLayout>
    );
}
