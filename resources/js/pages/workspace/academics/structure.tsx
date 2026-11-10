import { router, useForm, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';
import { academicPlural, academicTerm, type AcademicWorkspaceConfig } from '@/types/academic';

type Page<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[] };
type Unit = {
    id: number;
    unit_type: string;
    name: string;
    code: string;
    description: string | null;
    valid_from: string | null;
    valid_until: string | null;
    parent_unit_id: number | null;
    child_units_count: number;
    campuses: { id: number; name: string }[];
};
type Level = {
    id: number;
    name: string;
    code: string;
    category: string;
    sequence: number;
    description: string | null;
    parent_level_id: number | null;
    status: string;
};
type Qualification = {
    id: number;
    name: string;
    code: string;
    award_type: string | null;
    framework_reference: string | null;
};
type Program = {
    id: number;
    title: string;
    code: string;
    academic_level_id: number;
    qualification_type_id: number | null;
    academic_unit_id: number | null;
    duration_amount: string | null;
    duration_unit: string | null;
    period_model: string | null;
    credit_bearing: boolean;
    required_credits: string | null;
    delivery_mode: string;
    award_type: string | null;
    description: string | null;
    effective_from: string | null;
    effective_until: string | null;
    credit_policy_id: number | null;
    status: string;
    campuses: { id: number; name: string }[];
};
type AcademicClass = {
    id: number;
    name: string;
    code: string;
    group_type: string;
    academic_level_id: number | null;
    academic_year_id: number | null;
    campus_id: number | null;
    academic_program_id: number | null;
    structural_capacity: number | null;
    capacity_policy: string;
    schedule_label: string | null;
    starts_on: string | null;
    ends_on: string | null;
    sections_count: number;
    sections: {
        id: number;
        code: string;
        name: string;
        status: string;
        structural_capacity: number | null;
    }[];
};
type Props = {
    tenant: { slug: string };
    units: Page<Unit>;
    levels: Page<Level>;
    qualifications: Page<Qualification>;
    programs: Page<Program>;
    classes: Page<AcademicClass>;
    campuses: { id: number; name: string; code: string }[];
    members: { id: number; name: string; email: string }[];
    years: { id: number; name: string; status: string }[];
    creditPolicies: { id: number; name: string; maximum_credits: string | null }[];
    campusScope: number | null;
};

function Pagination({ links, label }: { links: Page<unknown>['links']; label: string }) {
    return (
        <nav aria-label={`${label} pages`} className="mt-3 flex flex-wrap gap-2">
            {links.map((link) => (
                <a
                    key={link.label}
                    href={link.url ?? '#'}
                    aria-current={link.active ? 'page' : undefined}
                    className={`border-border rounded-lg border px-3 py-2 text-sm ${link.active ? 'bg-brand-action text-white' : ''}`}
                >
                    {link.label.replace(/&laquo;/g, '‹').replace(/&raquo;/g, '›')}
                </a>
            ))}
        </nav>
    );
}

function renderUnitBranch(unit: Unit, units: Unit[], ancestors: Set<number> = new Set()): ReactNode {
    if (ancestors.has(unit.id)) {
        return (
            <li key={unit.id} className="text-text-muted rounded-lg border p-3 text-sm">
                {unit.name} · cycle requires administrator review
            </li>
        );
    }

    const nextAncestors = new Set(ancestors);
    nextAncestors.add(unit.id);
    const children = units.filter((candidate) => candidate.parent_unit_id === unit.id);

    return (
        <li key={unit.id}>
            <div className="border-border bg-canvas rounded-lg border px-3 py-2">
                <strong className="text-sm">{unit.name}</strong>
                <span className="text-text-muted ml-2 text-xs">
                    {unit.code} · {unit.unit_type.replaceAll('_', ' ')} · {unit.child_units_count} children
                </span>
            </div>
            {children.length > 0 ? (
                <ul className="border-border mt-2 ml-4 grid gap-2 border-l pl-3">
                    {children.map((child) => renderUnitBranch(child, units, nextAncestors))}
                </ul>
            ) : null}
        </li>
    );
}

function UnitHierarchy({ units }: { units: Unit[] }) {
    const pageIds = new Set(units.map((unit) => unit.id));
    const roots = units.filter((unit) => unit.parent_unit_id === null || !pageIds.has(unit.parent_unit_id));
    const displayRoots = roots.length > 0 ? roots : units.slice(0, 1);

    return (
        <nav aria-label="Academic unit hierarchy" className="mt-3">
            {displayRoots.length > 0 ? (
                <ul className="grid gap-2">{displayRoots.map((unit) => renderUnitBranch(unit, units))}</ul>
            ) : (
                <p className="text-text-secondary text-sm">No academic units on this page.</p>
            )}
        </nav>
    );
}

function AcademicClassCard({
    item,
    slug,
    levels,
    years,
    campuses,
    programs,
    campusScope,
}: {
    item: AcademicClass;
    slug: string;
    levels: Props['levels']['data'];
    years: Props['years'];
    campuses: Props['campuses'];
    programs: Props['programs']['data'];
    campusScope: number | null;
}) {
    const root = `/t/${slug}/academics`;
    const editForm = useForm({
        group_type: item.group_type,
        code: item.code,
        name: item.name,
        academic_level_id: String(item.academic_level_id ?? ''),
        academic_year_id: String(item.academic_year_id ?? ''),
        campus_id: String(item.campus_id ?? ''),
        academic_program_id: String(item.academic_program_id ?? ''),
        structural_capacity: String(item.structural_capacity ?? ''),
        capacity_policy: item.capacity_policy,
        schedule_label: item.schedule_label ?? '',
        display_order: 0,
        starts_on: item.starts_on ?? '',
        ends_on: item.ends_on ?? '',
    });
    const sectionForm = useForm({ code: '', name: '', structural_capacity: '' });
    return (
        <article className="border-border rounded-xl border p-4">
            <div className="flex flex-wrap justify-between gap-3">
                <div>
                    <strong>{item.name}</strong>
                    <span className="text-text-muted ml-2 text-sm">{item.code}</span>
                    <p className="text-text-secondary mt-1 text-sm capitalize">
                        {item.group_type.replaceAll('_', ' ')} · {item.sections_count} sections
                        {item.structural_capacity
                            ? ` · Capacity ${item.structural_capacity} (${item.capacity_policy})`
                            : ''}
                    </p>
                </div>
                <details>
                    <summary className="focus-ring text-brand-link cursor-pointer rounded text-sm font-semibold">
                        Edit group
                    </summary>
                    <form
                        className="mt-3 grid gap-3 sm:grid-cols-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            editForm.put(`${root}/classes/${item.id}`);
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            Group type
                            <select
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                value={editForm.data.group_type}
                                onChange={(e) => editForm.setData('group_type', e.currentTarget.value)}
                            >
                                {[
                                    'grade',
                                    'class',
                                    'section_group',
                                    'cohort',
                                    'batch',
                                    'training_cycle',
                                    'custom',
                                ].map((type) => (
                                    <option key={type} value={type}>
                                        {type.replaceAll('_', ' ')}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Name
                            <input
                                required
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                value={editForm.data.name}
                                onChange={(e) => editForm.setData('name', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Code
                            <input
                                required
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                value={editForm.data.code}
                                onChange={(e) => editForm.setData('code', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Level
                            <select
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                value={editForm.data.academic_level_id}
                                onChange={(e) => editForm.setData('academic_level_id', e.currentTarget.value)}
                            >
                                <option value="">None</option>
                                {levels.map((level) => (
                                    <option key={level.id} value={level.id}>
                                        {level.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Academic year
                            <select
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                value={editForm.data.academic_year_id}
                                onChange={(e) => editForm.setData('academic_year_id', e.currentTarget.value)}
                            >
                                <option value="">None</option>
                                {years.map((year) => (
                                    <option key={year.id} value={year.id}>
                                        {year.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Campus
                            <select
                                required={campusScope !== null}
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                value={editForm.data.campus_id}
                                onChange={(e) => editForm.setData('campus_id', e.currentTarget.value)}
                            >
                                {campusScope === null ? <option value="">None</option> : null}
                                {campuses.map((campus) => (
                                    <option key={campus.id} value={campus.id}>
                                        {campus.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Program
                            <select
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                value={editForm.data.academic_program_id}
                                onChange={(e) =>
                                    editForm.setData('academic_program_id', e.currentTarget.value)
                                }
                            >
                                <option value="">None</option>
                                {programs.map((program) => (
                                    <option key={program.id} value={program.id}>
                                        {program.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Structural capacity
                            <input
                                type="number"
                                min={1}
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                value={editForm.data.structural_capacity}
                                onChange={(e) =>
                                    editForm.setData('structural_capacity', e.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Capacity policy
                            <select
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                value={editForm.data.capacity_policy}
                                onChange={(e) => editForm.setData('capacity_policy', e.currentTarget.value)}
                            >
                                <option value="advisory">Advisory</option>
                                <option value="enforced">Enforced</option>
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm sm:col-span-2">
                            Schedule label
                            <input
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                value={editForm.data.schedule_label}
                                onChange={(e) => editForm.setData('schedule_label', e.currentTarget.value)}
                            />
                        </label>
                        <button
                            disabled={editForm.processing}
                            className="bg-brand-action focus-ring min-h-10 justify-self-start rounded-lg px-4 text-sm font-semibold text-white"
                        >
                            Save group
                        </button>
                        {Object.keys(editForm.errors).length > 0 && (
                            <div className="sm:col-span-2">
                                <FormErrors />
                            </div>
                        )}
                    </form>
                </details>
            </div>
            <section className="border-border mt-4 border-t pt-4">
                <h4 className="text-sm font-semibold">Sections</h4>
                {item.sections.length > 0 && (
                    <ul className="mt-2 grid gap-2 sm:grid-cols-2">
                        {item.sections.map((section) => (
                            <li key={section.id} className="bg-canvas rounded-lg p-3 text-sm">
                                <strong>{section.name}</strong>
                                <span className="text-text-muted ml-2">{section.code}</span>
                                {section.structural_capacity ? (
                                    <span className="text-text-secondary ml-2">
                                        Capacity {section.structural_capacity}
                                    </span>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
                <form
                    className="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_1fr_auto]"
                    onSubmit={(event) => {
                        event.preventDefault();
                        sectionForm.post(`${root}/classes/${item.id}/sections`, {
                            onSuccess: () => sectionForm.reset(),
                        });
                    }}
                >
                    <label className="grid gap-1 text-sm">
                        Section code
                        <input
                            required
                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                            value={sectionForm.data.code}
                            onChange={(e) => sectionForm.setData('code', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Section name
                        <input
                            required
                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                            value={sectionForm.data.name}
                            onChange={(e) => sectionForm.setData('name', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Structural capacity
                        <input
                            type="number"
                            min={1}
                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                            value={sectionForm.data.structural_capacity}
                            onChange={(e) =>
                                sectionForm.setData('structural_capacity', e.currentTarget.value)
                            }
                        />
                    </label>
                    <div className="flex items-end">
                        <button
                            disabled={sectionForm.processing}
                            className="border-border min-h-10 rounded-lg border px-4 text-sm font-semibold"
                        >
                            Add section
                        </button>
                    </div>
                    {Object.keys(sectionForm.errors).length > 0 && (
                        <div className="sm:col-span-2 lg:col-span-4">
                            <FormErrors />
                        </div>
                    )}
                </form>
            </section>
        </article>
    );
}

export default function AcademicStructurePage({
    tenant,
    units,
    levels,
    qualifications,
    programs,
    classes,
    campuses,
    years,
    creditPolicies,
    campusScope,
}: Props) {
    const { academicConfig } = usePage<{ academicConfig: AcademicWorkspaceConfig | null }>().props;
    const terminology = academicConfig?.terminology ?? {};
    const level = academicTerm(terminology, 'level', 'Academic level');
    const program = academicTerm(terminology, 'program', 'Program');
    const learningGroup = academicTerm(terminology, 'class', 'Academic group');
    const root = `/t/${tenant.slug}/academics`;
    const [editingUnit, setEditingUnit] = useState<Unit | null>(null);
    const [editingLevel, setEditingLevel] = useState<Level | null>(null);
    const [editingProgram, setEditingProgram] = useState<Program | null>(null);
    const unitForm = useForm({
        unit_type: 'department',
        name: '',
        code: '',
        parent_unit_id: '',
        description: '',
        valid_from: '',
        valid_until: '',
        manager_user_id: '',
        campus_ids: campusScope === null ? ([] as number[]) : [campusScope],
    });
    const levelForm = useForm({
        name: '',
        code: '',
        category: 'custom',
        sequence: 0,
        description: '',
        parent_level_id: '',
        status: 'active',
    });
    const qualificationForm = useForm({
        name: '',
        code: '',
        award_type: '',
        framework_reference: '',
        description: '',
    });
    const programForm = useForm({
        title: '',
        code: '',
        academic_level_id: '',
        qualification_type_id: '',
        academic_unit_id: '',
        credit_policy_id: '',
        duration_amount: '',
        duration_unit: '',
        period_model: '',
        credit_bearing: false,
        required_credits: '',
        delivery_mode: 'on_campus',
        award_type: '',
        description: '',
        entry_requirements_reference: '',
        effective_from: '',
        effective_until: '',
        campus_ids: campusScope === null ? ([] as number[]) : [campusScope],
    });
    const classForm = useForm({
        group_type: 'class',
        code: '',
        name: '',
        academic_level_id: '',
        academic_year_id: '',
        campus_id: campusScope === null ? '' : String(campusScope),
        academic_program_id: '',
        structural_capacity: '',
        capacity_policy: 'advisory',
        schedule_label: '',
        display_order: 0,
        starts_on: '',
        ends_on: '',
    });

    return (
        <WorkspaceLayout area="tenant" title="Academic organization">
            <PageHeader
                title="Organization & programs"
                description="Manage institution-specific academic units, levels, qualifications, programs, and learning groups."
            />
            <div className="mt-5 flex gap-3 text-sm">
                <a href={root} className="text-brand-link font-semibold">
                    Overview
                </a>
                <a href={`${root}/calendar`} className="text-brand-link font-semibold">
                    Academic calendar
                </a>
            </div>
            {academicConfig?.enabledFeatures.organization ? (
                <>
                    <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                        <h2 className="text-lg font-semibold">Academic units</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Build a flexible faculty, department, school, division, or center hierarchy.
                        </p>
                        <form
                            className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                const action = editingUnit ? 'put' : 'post';
                                unitForm[action](
                                    editingUnit ? `${root}/units/${editingUnit.id}` : `${root}/units`,
                                    {
                                        onSuccess: () => {
                                            unitForm.reset();
                                            if (campusScope !== null)
                                                unitForm.setData('campus_ids', [campusScope]);
                                            setEditingUnit(null);
                                        },
                                    },
                                );
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Unit type
                                <select
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    value={unitForm.data.unit_type}
                                    onChange={(e) => unitForm.setData('unit_type', e.currentTarget.value)}
                                >
                                    {[
                                        'faculty',
                                        'department',
                                        'school',
                                        'division',
                                        'center',
                                        'program_area',
                                        'custom',
                                    ].map((item) => (
                                        <option key={item} value={item}>
                                            {item.replace('_', ' ')}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Name
                                <input
                                    required
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    value={unitForm.data.name}
                                    onChange={(e) => unitForm.setData('name', e.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Code
                                <input
                                    required
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    value={unitForm.data.code}
                                    onChange={(e) => unitForm.setData('code', e.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Parent unit
                                <select
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    value={unitForm.data.parent_unit_id}
                                    onChange={(e) =>
                                        unitForm.setData('parent_unit_id', e.currentTarget.value)
                                    }
                                >
                                    <option value="">None</option>
                                    {units.data
                                        .filter((unit) => unit.id !== editingUnit?.id)
                                        .map((unit) => (
                                            <option key={unit.id} value={unit.id}>
                                                {unit.name}
                                            </option>
                                        ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm sm:col-span-2">
                                Description
                                <textarea
                                    className="border-border bg-surface min-h-16 rounded-lg border px-3 py-2"
                                    value={unitForm.data.description}
                                    onChange={(e) => unitForm.setData('description', e.currentTarget.value)}
                                />
                            </label>
                            <fieldset className="grid gap-2 sm:col-span-2">
                                <legend className="text-sm font-medium">Campus association</legend>
                                <div className="flex flex-wrap gap-3">
                                    {campuses.map((campus) => (
                                        <label
                                            key={campus.id}
                                            className="flex min-h-9 items-center gap-2 text-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={unitForm.data.campus_ids.includes(campus.id)}
                                                onChange={(e) =>
                                                    unitForm.setData(
                                                        'campus_ids',
                                                        e.currentTarget.checked
                                                            ? [...unitForm.data.campus_ids, campus.id]
                                                            : unitForm.data.campus_ids.filter(
                                                                  (id) => id !== campus.id,
                                                              ),
                                                    )
                                                }
                                            />
                                            {campus.name}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                            <div className="flex items-end gap-2">
                                <button
                                    disabled={unitForm.processing}
                                    className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                                >
                                    {editingUnit ? 'Save unit' : 'Create unit'}
                                </button>
                                {editingUnit && (
                                    <button
                                        type="button"
                                        className="border-border min-h-11 rounded-lg border px-4 text-sm"
                                        onClick={() => {
                                            setEditingUnit(null);
                                            unitForm.reset();
                                        }}
                                    >
                                        Cancel
                                    </button>
                                )}
                            </div>
                            {Object.keys(unitForm.errors).length > 0 && (
                                <div className="sm:col-span-2 xl:col-span-4">
                                    <FormErrors />
                                </div>
                            )}
                        </form>
                        <div className="border-border mt-5 border-t pt-4">
                            <h3 className="text-sm font-semibold">Academic unit hierarchy</h3>
                            <p className="text-text-muted mt-1 text-xs">
                                Nested list for the current unit page; the table below remains available for
                                parent names and quick editing.
                            </p>
                            <UnitHierarchy units={units.data} />
                        </div>
                        <div className="mt-5 overflow-x-auto">
                            <table className="w-full min-w-[34rem] text-left text-sm">
                                <thead>
                                    <tr className="text-text-muted border-border border-b">
                                        <th className="py-3 pr-3">Unit</th>
                                        <th className="py-3 pr-3">Type</th>
                                        <th className="py-3 pr-3">Parent</th>
                                        <th className="py-3 pr-3">Children</th>
                                        <th className="py-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {units.data.map((unit) => (
                                        <tr key={unit.id} className="border-border border-b last:border-0">
                                            <td className="py-3 pr-3 font-medium">
                                                {unit.name}
                                                <span className="text-text-muted ml-2 text-xs">
                                                    {unit.code}
                                                </span>
                                            </td>
                                            <td className="py-3 pr-3 capitalize">
                                                {unit.unit_type.replace('_', ' ')}
                                            </td>
                                            <td className="py-3 pr-3">
                                                {unit.parent_unit_id
                                                    ? (units.data.find(
                                                          (row) => row.id === unit.parent_unit_id,
                                                      )?.name ?? 'Parent unit')
                                                    : '—'}
                                            </td>
                                            <td className="py-3 pr-3">{unit.child_units_count}</td>
                                            <td className="py-3">
                                                <button
                                                    type="button"
                                                    disabled={
                                                        campusScope !== null && unit.campuses.length !== 1
                                                    }
                                                    className="text-brand-link font-semibold"
                                                    onClick={() => {
                                                        setEditingUnit(unit);
                                                        unitForm.setData({
                                                            unit_type: unit.unit_type,
                                                            name: unit.name,
                                                            code: unit.code,
                                                            parent_unit_id:
                                                                unit.parent_unit_id?.toString() ?? '',
                                                            description: unit.description ?? '',
                                                            valid_from: unit.valid_from ?? '',
                                                            valid_until: unit.valid_until ?? '',
                                                            manager_user_id: '',
                                                            campus_ids: unit.campuses.map(
                                                                (campus) => campus.id,
                                                            ),
                                                        });
                                                    }}
                                                >
                                                    Edit
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {units.data.length === 0 && (
                                <p className="text-text-secondary py-5 text-sm">
                                    No academic units configured.
                                </p>
                            )}
                            <Pagination links={units.links} label="Academic unit" />
                        </div>
                    </section>
                    <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                        <h2 className="text-lg font-semibold">{academicPlural(level)}</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Represent grade levels, qualification levels, or institution-defined progression.
                        </p>
                        <form
                            className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                const action = editingLevel ? 'put' : 'post';
                                levelForm[action](
                                    editingLevel ? `${root}/levels/${editingLevel.id}` : `${root}/levels`,
                                    {
                                        onSuccess: () => {
                                            levelForm.reset();
                                            setEditingLevel(null);
                                        },
                                    },
                                );
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Name
                                <input
                                    required
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    value={levelForm.data.name}
                                    onChange={(e) => levelForm.setData('name', e.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Code
                                <input
                                    required
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    value={levelForm.data.code}
                                    onChange={(e) => levelForm.setData('code', e.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Category
                                <input
                                    required
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    value={levelForm.data.category}
                                    onChange={(e) => levelForm.setData('category', e.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Sequence
                                <input
                                    type="number"
                                    min={0}
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    value={levelForm.data.sequence}
                                    onChange={(e) =>
                                        levelForm.setData('sequence', Number(e.currentTarget.value))
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Parent level
                                <select
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    value={levelForm.data.parent_level_id}
                                    onChange={(e) =>
                                        levelForm.setData('parent_level_id', e.currentTarget.value)
                                    }
                                >
                                    <option value="">None</option>
                                    {levels.data
                                        .filter((level) => level.id !== editingLevel?.id)
                                        .map((level) => (
                                            <option key={level.id} value={level.id}>
                                                {level.name}
                                            </option>
                                        ))}
                                </select>
                            </label>
                            <div className="flex items-end gap-2 sm:col-span-2">
                                <button
                                    disabled={levelForm.processing}
                                    className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                                >
                                    {editingLevel ? 'Save level' : 'Create level'}
                                </button>
                                {editingLevel && (
                                    <button
                                        type="button"
                                        className="border-border min-h-11 rounded-lg border px-4 text-sm"
                                        onClick={() => {
                                            setEditingLevel(null);
                                            levelForm.reset();
                                        }}
                                    >
                                        Cancel
                                    </button>
                                )}
                            </div>
                            {Object.keys(levelForm.errors).length > 0 && (
                                <div className="sm:col-span-2 xl:col-span-5">
                                    <FormErrors />
                                </div>
                            )}
                        </form>
                        <ul className="mt-5 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {levels.data.map((level) => (
                                <li
                                    key={level.id}
                                    className="border-border flex items-center justify-between rounded-xl border p-4"
                                >
                                    <span>
                                        <strong>{level.name}</strong>
                                        <span className="text-text-muted ml-2 text-sm">
                                            {level.code} · {level.category}
                                        </span>
                                    </span>
                                    <button
                                        type="button"
                                        className="text-brand-link text-sm font-semibold"
                                        onClick={() => {
                                            setEditingLevel(level);
                                            levelForm.setData({
                                                name: level.name,
                                                code: level.code,
                                                category: level.category,
                                                sequence: level.sequence,
                                                description: level.description ?? '',
                                                parent_level_id: level.parent_level_id?.toString() ?? '',
                                                status: level.status,
                                            });
                                        }}
                                    >
                                        Edit
                                    </button>
                                </li>
                            ))}
                        </ul>
                        {levels.data.length === 0 && (
                            <p className="text-text-secondary mt-4 text-sm">No academic levels configured.</p>
                        )}
                        <Pagination links={levels.links} label="Academic level" />
                    </section>
                    <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                        <h2 className="text-lg font-semibold">Qualification types</h2>
                        <form
                            className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                qualificationForm.post(`${root}/qualifications`, {
                                    onSuccess: () => qualificationForm.reset(),
                                });
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Name
                                <input
                                    required
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    value={qualificationForm.data.name}
                                    onChange={(e) => qualificationForm.setData('name', e.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Code
                                <input
                                    required
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    value={qualificationForm.data.code}
                                    onChange={(e) => qualificationForm.setData('code', e.currentTarget.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Award type
                                <input
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    value={qualificationForm.data.award_type}
                                    onChange={(e) =>
                                        qualificationForm.setData('award_type', e.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Framework reference
                                <input
                                    className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                    value={qualificationForm.data.framework_reference}
                                    onChange={(e) =>
                                        qualificationForm.setData(
                                            'framework_reference',
                                            e.currentTarget.value,
                                        )
                                    }
                                />
                            </label>
                            <button
                                disabled={qualificationForm.processing}
                                className="bg-brand-action focus-ring min-h-11 justify-self-start rounded-lg px-4 text-sm font-semibold text-white"
                            >
                                Create qualification
                            </button>
                            {Object.keys(qualificationForm.errors).length > 0 && (
                                <div className="sm:col-span-2 xl:col-span-4">
                                    <FormErrors />
                                </div>
                            )}
                        </form>
                        <ul className="mt-4 grid gap-2 sm:grid-cols-2">
                            {qualifications.data.map((item) => (
                                <li key={item.id} className="border-border rounded-xl border p-4">
                                    <strong>{item.name}</strong>
                                    <span className="text-text-muted ml-2 text-sm">{item.code}</span>
                                    <p className="text-text-secondary mt-1 text-sm">
                                        {item.award_type ??
                                            item.framework_reference ??
                                            'No framework reference'}
                                    </p>
                                </li>
                            ))}
                        </ul>
                        <Pagination links={qualifications.links} label="Qualification" />
                    </section>
                </>
            ) : null}
            {academicConfig?.enabledFeatures.programs ? (
                <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                    <h2 className="text-lg font-semibold">{academicPlural(program)}</h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Program structures remain institution-defined; durations and credit requirements are
                        configurable.
                    </p>
                    <form
                        className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const action = editingProgram ? 'put' : 'post';
                            programForm[action](
                                editingProgram ? `${root}/programs/${editingProgram.id}` : `${root}/programs`,
                                {
                                    onSuccess: () => {
                                        programForm.reset();
                                        if (campusScope !== null)
                                            programForm.setData('campus_ids', [campusScope]);
                                        setEditingProgram(null);
                                    },
                                },
                            );
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            Program title
                            <input
                                required
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={programForm.data.title}
                                onChange={(e) => programForm.setData('title', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Code
                            <input
                                required
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={programForm.data.code}
                                onChange={(e) => programForm.setData('code', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Academic level
                            <select
                                required
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={programForm.data.academic_level_id}
                                onChange={(e) =>
                                    programForm.setData('academic_level_id', e.currentTarget.value)
                                }
                            >
                                <option value="">Select level</option>
                                {levels.data.map((level) => (
                                    <option key={level.id} value={level.id}>
                                        {level.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Qualification
                            <select
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={programForm.data.qualification_type_id}
                                onChange={(e) =>
                                    programForm.setData('qualification_type_id', e.currentTarget.value)
                                }
                            >
                                <option value="">Not specified</option>
                                {qualifications.data.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Academic unit
                            <select
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={programForm.data.academic_unit_id}
                                onChange={(e) =>
                                    programForm.setData('academic_unit_id', e.currentTarget.value)
                                }
                            >
                                <option value="">Not specified</option>
                                {units.data.map((unit) => (
                                    <option key={unit.id} value={unit.id}>
                                        {unit.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Duration
                            <input
                                type="number"
                                min="0.01"
                                step="0.01"
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={programForm.data.duration_amount}
                                onChange={(e) =>
                                    programForm.setData('duration_amount', e.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Duration unit
                            <select
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={programForm.data.duration_unit}
                                onChange={(e) => programForm.setData('duration_unit', e.currentTarget.value)}
                            >
                                <option value="">Not specified</option>
                                {['day', 'week', 'month', 'year', 'term'].map((item) => (
                                    <option key={item}>{item}</option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Delivery mode
                            <select
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={programForm.data.delivery_mode}
                                onChange={(e) => programForm.setData('delivery_mode', e.currentTarget.value)}
                            >
                                {['on_campus', 'online', 'hybrid', 'workplace', 'self_paced', 'custom'].map(
                                    (item) => (
                                        <option key={item}>{item.replace('_', ' ')}</option>
                                    ),
                                )}
                            </select>
                        </label>
                        <label className="flex min-h-11 items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                disabled={!academicConfig?.enabledFeatures.credits}
                                checked={programForm.data.credit_bearing}
                                onChange={(e) =>
                                    programForm.setData('credit_bearing', e.currentTarget.checked)
                                }
                            />
                            {academicTerm(terminology, 'credit-bearing', 'Credit-bearing')}
                        </label>
                        <label className="grid gap-1 text-sm">
                            {academicConfig?.enabledFeatures.credits
                                ? 'Required credits'
                                : 'Credit requirements disabled'}
                            <input
                                type="number"
                                min="0"
                                step="0.001"
                                disabled={
                                    !academicConfig?.enabledFeatures.credits ||
                                    !programForm.data.credit_bearing
                                }
                                className="border-border bg-surface min-h-11 rounded-lg border px-3 disabled:opacity-60"
                                value={programForm.data.required_credits}
                                onChange={(e) =>
                                    programForm.setData('required_credits', e.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Active credit policy
                            <select
                                disabled={
                                    !academicConfig?.enabledFeatures.credits ||
                                    !programForm.data.credit_bearing
                                }
                                className="border-border bg-surface min-h-11 rounded-lg border px-3 disabled:opacity-60"
                                value={programForm.data.credit_policy_id}
                                onChange={(e) =>
                                    programForm.setData('credit_policy_id', e.currentTarget.value)
                                }
                            >
                                <option value="">Select policy</option>
                                {creditPolicies.map((policy) => (
                                    <option key={policy.id} value={policy.id}>
                                        {policy.name}
                                        {policy.maximum_credits ? ` · maximum ${policy.maximum_credits}` : ''}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Award type
                            <input
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={programForm.data.award_type}
                                onChange={(e) => programForm.setData('award_type', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Period model
                            <input
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={programForm.data.period_model}
                                onChange={(e) => programForm.setData('period_model', e.currentTarget.value)}
                            />
                        </label>
                        <fieldset className="grid gap-2 sm:col-span-2 xl:col-span-4">
                            <legend className="text-sm font-medium">Program campuses</legend>
                            <div className="flex flex-wrap gap-3">
                                {campuses.map((campus) => (
                                    <label
                                        key={campus.id}
                                        className="flex min-h-9 items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={programForm.data.campus_ids.includes(campus.id)}
                                            onChange={(e) =>
                                                programForm.setData(
                                                    'campus_ids',
                                                    e.currentTarget.checked
                                                        ? [...programForm.data.campus_ids, campus.id]
                                                        : programForm.data.campus_ids.filter(
                                                              (id) => id !== campus.id,
                                                          ),
                                                )
                                            }
                                        />
                                        {campus.name}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        <label className="grid gap-1 text-sm sm:col-span-2 xl:col-span-4">
                            Description
                            <textarea
                                className="border-border bg-surface min-h-20 rounded-lg border px-3 py-2"
                                value={programForm.data.description}
                                onChange={(e) => programForm.setData('description', e.currentTarget.value)}
                            />
                        </label>
                        <div className="sm:col-span-2 xl:col-span-4">
                            <button
                                disabled={programForm.processing}
                                className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                            >
                                {editingProgram ? 'Save program draft' : 'Create draft program'}
                            </button>
                            {editingProgram && (
                                <button
                                    type="button"
                                    className="border-border min-h-11 rounded-lg border px-4 text-sm"
                                    onClick={() => {
                                        setEditingProgram(null);
                                        programForm.reset();
                                    }}
                                >
                                    Cancel editing
                                </button>
                            )}
                        </div>
                        {Object.keys(programForm.errors).length > 0 && (
                            <div className="sm:col-span-2 xl:col-span-4">
                                <FormErrors />
                            </div>
                        )}
                    </form>
                    <div className="mt-5 grid gap-3 md:grid-cols-2">
                        {programs.data.map((program) => (
                            <article key={program.id} className="border-border rounded-xl border p-4">
                                <div className="flex flex-wrap justify-between gap-3">
                                    <h3 className="font-semibold">{program.title}</h3>
                                    <span className="text-text-muted text-xs">{program.code}</span>
                                </div>
                                <p className="text-text-secondary mt-2 text-sm">
                                    Level{' '}
                                    {levels.data.find((level) => level.id === program.academic_level_id)
                                        ?.name ?? 'configured'}{' '}
                                    · {program.delivery_mode.replaceAll('_', ' ')}
                                    {program.credit_bearing
                                        ? ` · ${program.required_credits ?? 'Credit-bearing'} credits`
                                        : ''}
                                </p>
                                <p className="text-text-muted mt-1 text-xs capitalize">
                                    {program.status}
                                    {program.credit_policy_id
                                        ? ` · credit policy ${program.credit_policy_id}`
                                        : ''}
                                </p>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {program.status === 'draft' && (
                                        <button
                                            type="button"
                                            className="text-brand-link rounded border px-3 py-2 text-sm font-semibold"
                                            onClick={() => {
                                                setEditingProgram(program);
                                                programForm.setData({
                                                    title: program.title,
                                                    code: program.code,
                                                    academic_level_id: String(program.academic_level_id),
                                                    qualification_type_id: String(
                                                        program.qualification_type_id ?? '',
                                                    ),
                                                    academic_unit_id: String(program.academic_unit_id ?? ''),
                                                    credit_policy_id: String(program.credit_policy_id ?? ''),
                                                    duration_amount: String(program.duration_amount ?? ''),
                                                    duration_unit: program.duration_unit ?? '',
                                                    period_model: program.period_model ?? '',
                                                    credit_bearing: program.credit_bearing,
                                                    required_credits: String(program.required_credits ?? ''),
                                                    delivery_mode: program.delivery_mode,
                                                    award_type: program.award_type ?? '',
                                                    description: program.description ?? '',
                                                    entry_requirements_reference: '',
                                                    effective_from: program.effective_from ?? '',
                                                    effective_until: program.effective_until ?? '',
                                                    campus_ids: program.campuses.map((campus) => campus.id),
                                                });
                                            }}
                                        >
                                            Edit draft
                                        </button>
                                    )}
                                    {program.status === 'draft' && (
                                        <button
                                            type="button"
                                            className="border-border rounded border px-3 py-2 text-sm font-semibold"
                                            onClick={() =>
                                                router.post(`${root}/programs/${program.id}/activate`)
                                            }
                                        >
                                            Activate
                                        </button>
                                    )}
                                </div>
                            </article>
                        ))}
                    </div>
                    <Pagination links={programs.links} label="Program" />
                </section>
            ) : null}
            {academicConfig?.enabledFeatures.classes ? (
                <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                    <h2 className="text-lg font-semibold">
                        {academicPlural(learningGroup)}, cohorts, and training cycles
                    </h2>
                    <p className="text-text-secondary mt-1 text-sm">
                        Structural capacity is advisory by default and is not an enrollment count.
                    </p>
                    <form
                        className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            classForm.post(`${root}/classes`, {
                                onSuccess: () => {
                                    classForm.reset();
                                    if (campusScope !== null)
                                        classForm.setData('campus_id', String(campusScope));
                                },
                            });
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            Group type
                            <select
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={classForm.data.group_type}
                                onChange={(e) => classForm.setData('group_type', e.currentTarget.value)}
                            >
                                {[
                                    'grade',
                                    'class',
                                    'section_group',
                                    'cohort',
                                    'batch',
                                    'training_cycle',
                                    'custom',
                                ].map((item) => (
                                    <option key={item} value={item}>
                                        {item.replace('_', ' ')}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Name
                            <input
                                required
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={classForm.data.name}
                                onChange={(e) => classForm.setData('name', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Code
                            <input
                                required
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={classForm.data.code}
                                onChange={(e) => classForm.setData('code', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Level
                            <select
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={classForm.data.academic_level_id}
                                onChange={(e) =>
                                    classForm.setData('academic_level_id', e.currentTarget.value)
                                }
                            >
                                <option value="">Not specified</option>
                                {levels.data.map((level) => (
                                    <option key={level.id} value={level.id}>
                                        {level.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Academic year
                            <select
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={classForm.data.academic_year_id}
                                onChange={(e) => classForm.setData('academic_year_id', e.currentTarget.value)}
                            >
                                <option value="">Not specified</option>
                                {years.map((year) => (
                                    <option key={year.id} value={year.id}>
                                        {year.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Campus
                            <select
                                required={campusScope !== null}
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={classForm.data.campus_id}
                                onChange={(e) => classForm.setData('campus_id', e.currentTarget.value)}
                            >
                                {campusScope === null ? <option value="">Not specified</option> : null}
                                {campuses.map((campus) => (
                                    <option key={campus.id} value={campus.id}>
                                        {campus.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Program
                            <select
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={classForm.data.academic_program_id}
                                onChange={(e) =>
                                    classForm.setData('academic_program_id', e.currentTarget.value)
                                }
                            >
                                <option value="">Not specified</option>
                                {programs.data.map((program) => (
                                    <option key={program.id} value={program.id}>
                                        {program.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Structural capacity
                            <input
                                type="number"
                                min={1}
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={classForm.data.structural_capacity}
                                onChange={(e) =>
                                    classForm.setData('structural_capacity', e.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Capacity policy
                            <select
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={classForm.data.capacity_policy}
                                onChange={(e) => classForm.setData('capacity_policy', e.currentTarget.value)}
                            >
                                <option value="advisory">Advisory</option>
                                <option value="enforced">Enforced</option>
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Schedule label
                            <input
                                className="border-border bg-surface min-h-11 rounded-lg border px-3"
                                value={classForm.data.schedule_label}
                                onChange={(e) => classForm.setData('schedule_label', e.currentTarget.value)}
                            />
                        </label>
                        <div className="sm:col-span-2 xl:col-span-4">
                            <button
                                disabled={classForm.processing}
                                className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                            >
                                Create academic group
                            </button>
                        </div>
                        {Object.keys(classForm.errors).length > 0 && (
                            <div className="sm:col-span-2 xl:col-span-4">
                                <FormErrors />
                            </div>
                        )}
                    </form>
                    <ul className="mt-5 grid gap-3 sm:grid-cols-2">
                        {classes.data.map((item) => (
                            <AcademicClassCard
                                key={item.id}
                                item={item}
                                slug={tenant.slug}
                                levels={levels.data}
                                years={years}
                                campuses={campuses}
                                programs={programs.data}
                                campusScope={campusScope}
                            />
                        ))}
                    </ul>
                    <Pagination links={classes.links} label={learningGroup} />
                </section>
            ) : null}
        </WorkspaceLayout>
    );
}
