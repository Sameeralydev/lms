import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Student = {
    id: number;
    studentNumber: string;
    registrationReference: string;
    name: string;
    status: string;
    studentType: string;
    admissionDate: string;
    homeCampus: { id: number; name: string; code: string } | null;
};
type AcademicOption = {
    id: number;
    name: string;
    code?: string | null;
    yearId?: number | null;
    programId?: number | null;
    levelId?: number | null;
    classId?: number | null;
    campusId?: number | null;
};
type Props = {
    tenant: { slug: string };
    students: {
        data: Student[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: {
        q?: string;
        status?: string;
        campus_id?: string;
        student_type?: string;
        academic_year_id?: string;
        academic_program_id?: string;
        academic_level_id?: string;
        academic_class_id?: string;
        academic_section_id?: string;
        custom_field_id?: string;
        custom_field_value?: string;
        sort?: string;
        direction?: string;
    };
    campuses: { id: number; name: string; code: string }[];
    academicOptions: {
        campuses: AcademicOption[];
        years: AcademicOption[];
        levels: AcademicOption[];
        programs: AcademicOption[];
        classes: AcademicOption[];
        sections: AcademicOption[];
    };
    filterCustomFields: { id: number; key: string; label: string; type: string }[];
    bulkPromotionPreview: { token: string; count: number; expires_at: number } | null;
    promotionApprovalRequired: boolean;
    promotionApprovals: {
        id: number;
        status: string;
        requestedBy: { id: number; name: string | null };
        requestedAt: string;
        destinationClass: string | null;
        effectiveFrom: string;
        reason: string | null;
        reasonCode: string;
        students: { id: number; number: string | null; name: string }[];
        canDecide: boolean;
    }[];
    permissions: {
        create: boolean;
        export: boolean;
        import: boolean;
        promotion: boolean;
        approvePromotions: boolean;
    };
    importBatches: {
        reference: string;
        status: string;
        rowCount: number;
        processedCount: number;
        invalidCount: number;
        errorsUrl: string | null;
    }[];
};

const fieldClass =
    'border-border bg-surface text-text-primary min-h-11 w-full rounded-lg border px-3 text-sm focus-visible:outline-brand-blue focus-visible:outline-2';
const statusOptions = [
    'pre_admitted',
    'admitted',
    'active',
    'on_leave',
    'suspended',
    'transferred',
    'withdrawn',
    'graduated',
    'alumni',
    'archived',
];

export default function StudentsIndex({
    tenant,
    students,
    filters,
    campuses,
    academicOptions,
    filterCustomFields,
    bulkPromotionPreview,
    promotionApprovalRequired,
    promotionApprovals,
    permissions,
    importBatches,
}: Props) {
    const page = usePage<{
        flash: {
            status?: string;
            sisImportPreview?: {
                batchReference: string;
                valid: number;
                invalid: number;
                errors: { row: number; errors: string[] }[];
                errorsTruncated: boolean;
                mappedFields: Record<string, string>;
                errorReportUrl?: string | null;
            } | null;
            sisImportMapping?: {
                headers: string[];
                fields: Record<string, string>;
                required: string[];
                mapping: Record<string, string>;
                missing: string[];
            } | null;
        };
    }>();
    const [q, setQ] = useState(filters.q ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [campus, setCampus] = useState(filters.campus_id ?? '');
    const [studentType, setStudentType] = useState(filters.student_type ?? '');
    const [academicYear, setAcademicYear] = useState(filters.academic_year_id ?? '');
    const [academicProgram, setAcademicProgram] = useState(filters.academic_program_id ?? '');
    const [academicLevel, setAcademicLevel] = useState(filters.academic_level_id ?? '');
    const [academicClass, setAcademicClass] = useState(filters.academic_class_id ?? '');
    const [academicSection, setAcademicSection] = useState(filters.academic_section_id ?? '');
    const [customField, setCustomField] = useState(filters.custom_field_id ?? '');
    const [customValue, setCustomValue] = useState(filters.custom_field_value ?? '');
    const [createOpen, setCreateOpen] = useState(false);
    const createForm = useForm({
        legal_first_name: '',
        legal_middle_name: '',
        legal_last_name: '',
        preferred_name: '',
        admission_date: new Date().toISOString().slice(0, 10),
        date_of_birth: '',
        student_type: 'standard',
        home_campus_id: '',
    });
    const importForm = useForm<{
        file: File | null;
        confirm: boolean;
        column_map: Record<string, string>;
    }>({ file: null, confirm: false, column_map: {} });
    const importMappingPrompt = page.props.flash.sisImportMapping;
    const setImportData = importForm.setData;
    useEffect(() => {
        if (importMappingPrompt) {
            setImportData('column_map', importMappingPrompt.mapping);
        }
    }, [importMappingPrompt, setImportData]);
    const promotionForm = useForm({
        student_ids: [] as number[],
        campus_id: '',
        academic_year_id: '',
        academic_period_id: '',
        academic_program_id: '',
        academic_class_id: '',
        academic_section_id: '',
        effective_from: new Date().toISOString().slice(0, 10),
        reason_code: 'authorized_manual_promotion',
        reason: '',
    });

    function togglePromotionStudent(studentId: number, selected: boolean) {
        const ids = new Set(promotionForm.data.student_ids);
        if (selected) ids.add(studentId);
        else ids.delete(studentId);
        promotionForm.setData('student_ids', [...ids]);
    }

    function search(event: FormEvent) {
        event.preventDefault();
        router.get(
            `/t/${tenant.slug}/sis/students`,
            {
                q,
                status: status || undefined,
                campus_id: campus || undefined,
                student_type: studentType || undefined,
                academic_year_id: academicYear || undefined,
                academic_program_id: academicProgram || undefined,
                academic_level_id: academicLevel || undefined,
                academic_class_id: academicClass || undefined,
                academic_section_id: academicSection || undefined,
                custom_field_id: customField || undefined,
                custom_field_value: customField ? customValue : undefined,
            },
            { preserveState: true, preserveScroll: true },
        );
    }

    return (
        <WorkspaceLayout area="tenant" title="Student registry">
            <Head title="Student registry" />
            <div className="space-y-6">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-brand-link text-xs font-bold tracking-[0.16em] uppercase">
                            Student information system
                        </p>
                        <h1 className="text-text-primary mt-2 text-3xl font-semibold tracking-tight">
                            Student registry
                        </h1>
                        <p className="text-text-muted mt-2 text-sm">
                            Search and manage institution-scoped student records.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {permissions.export && (
                            <a
                                href={`/t/${tenant.slug}/sis/students/export`}
                                className="border-border bg-surface text-text-primary inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-semibold"
                            >
                                Export CSV
                            </a>
                        )}
                        {permissions.create && (
                            <button
                                type="button"
                                onClick={() => setCreateOpen((open) => !open)}
                                className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                            >
                                {createOpen ? 'Close registration' : 'Register student'}
                            </button>
                        )}
                    </div>
                </header>

                {createOpen && permissions.create && (
                    <section className="bg-surface border-border rounded-2xl border p-5 sm:p-6">
                        <h2 className="text-text-primary text-lg font-semibold">Register a student</h2>
                        <p className="text-text-muted mt-1 text-sm">
                            A unique institutional number is assigned when this record is saved.
                        </p>
                        <form
                            className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                createForm.post(`/t/${tenant.slug}/sis/students`, {
                                    onSuccess: () => createForm.reset(),
                                });
                            }}
                        >
                            <label className="text-text-primary text-sm font-medium">
                                Legal first name
                                <input
                                    className={`${fieldClass} mt-1`}
                                    value={createForm.data.legal_first_name}
                                    onChange={(event) =>
                                        createForm.setData('legal_first_name', event.target.value)
                                    }
                                    required
                                    maxLength={100}
                                    autoComplete="given-name"
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Middle name
                                <input
                                    className={`${fieldClass} mt-1`}
                                    value={createForm.data.legal_middle_name}
                                    onChange={(event) =>
                                        createForm.setData('legal_middle_name', event.target.value)
                                    }
                                    maxLength={100}
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Legal family name
                                <input
                                    className={`${fieldClass} mt-1`}
                                    value={createForm.data.legal_last_name}
                                    onChange={(event) =>
                                        createForm.setData('legal_last_name', event.target.value)
                                    }
                                    required
                                    maxLength={100}
                                    autoComplete="family-name"
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Preferred name
                                <input
                                    className={`${fieldClass} mt-1`}
                                    value={createForm.data.preferred_name}
                                    onChange={(event) =>
                                        createForm.setData('preferred_name', event.target.value)
                                    }
                                    maxLength={120}
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Date of birth
                                <input
                                    type="date"
                                    className={`${fieldClass} mt-1`}
                                    value={createForm.data.date_of_birth}
                                    onChange={(event) =>
                                        createForm.setData('date_of_birth', event.target.value)
                                    }
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Admission date
                                <input
                                    type="date"
                                    className={`${fieldClass} mt-1`}
                                    value={createForm.data.admission_date}
                                    onChange={(event) =>
                                        createForm.setData('admission_date', event.target.value)
                                    }
                                    required
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Student category
                                <input
                                    className={`${fieldClass} mt-1`}
                                    value={createForm.data.student_type}
                                    onChange={(event) =>
                                        createForm.setData('student_type', event.target.value)
                                    }
                                    maxLength={48}
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Home campus
                                <select
                                    className={`${fieldClass} mt-1`}
                                    value={createForm.data.home_campus_id}
                                    onChange={(event) =>
                                        createForm.setData('home_campus_id', event.target.value)
                                    }
                                >
                                    <option value="">Institution wide</option>
                                    {campuses.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <div className="sm:col-span-2 xl:col-span-4">
                                <button
                                    disabled={createForm.processing}
                                    className="bg-brand-action min-h-11 rounded-lg px-5 text-sm font-semibold text-white disabled:opacity-60"
                                >
                                    {createForm.processing ? 'Saving…' : 'Save student record'}
                                </button>
                                {Object.values(createForm.errors).length > 0 && (
                                    <p role="alert" className="mt-3 text-sm text-rose-700">
                                        {Object.values(createForm.errors).join(' ')}
                                    </p>
                                )}
                            </div>
                        </form>
                    </section>
                )}

                <form
                    onSubmit={search}
                    className="bg-surface border-border grid gap-3 rounded-xl border p-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <label className="text-text-primary text-sm font-medium">
                        Search
                        <input
                            className={`${fieldClass} mt-1`}
                            value={q}
                            onChange={(event) => setQ(event.target.value)}
                            placeholder="Name or registration number"
                        />
                    </label>
                    <label className="text-text-primary text-sm font-medium">
                        Status
                        <select
                            className={`${fieldClass} mt-1`}
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                        >
                            <option value="">All statuses</option>
                            {statusOptions.map((value) => (
                                <option key={value} value={value}>
                                    {value.replaceAll('_', ' ')}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-text-primary text-sm font-medium">
                        Campus
                        <select
                            className={`${fieldClass} mt-1`}
                            value={campus}
                            onChange={(event) => setCampus(event.target.value)}
                        >
                            <option value="">All visible campuses</option>
                            {campuses.map((row) => (
                                <option key={row.id} value={row.id}>
                                    {row.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-text-primary text-sm font-medium">
                        Student type
                        <input
                            className={`${fieldClass} mt-1`}
                            value={studentType}
                            onChange={(event) => setStudentType(event.target.value)}
                            maxLength={48}
                        />
                    </label>
                    <label className="text-text-primary text-sm font-medium">
                        Academic year
                        <select
                            className={`${fieldClass} mt-1`}
                            value={academicYear}
                            onChange={(event) => setAcademicYear(event.target.value)}
                        >
                            <option value="">All academic years</option>
                            {academicOptions.years.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-text-primary text-sm font-medium">
                        Program
                        <select
                            className={`${fieldClass} mt-1`}
                            value={academicProgram}
                            onChange={(event) => setAcademicProgram(event.target.value)}
                        >
                            <option value="">All programs</option>
                            {academicOptions.programs.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-text-primary text-sm font-medium">
                        Academic level
                        <select
                            className={`${fieldClass} mt-1`}
                            value={academicLevel}
                            onChange={(event) => setAcademicLevel(event.target.value)}
                        >
                            <option value="">All visible levels</option>
                            {academicOptions.levels.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-text-primary text-sm font-medium">
                        Class
                        <select
                            className={`${fieldClass} mt-1`}
                            value={academicClass}
                            onChange={(event) => setAcademicClass(event.target.value)}
                        >
                            <option value="">All classes</option>
                            {academicOptions.classes.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-text-primary text-sm font-medium">
                        Section
                        <select
                            className={`${fieldClass} mt-1`}
                            value={academicSection}
                            onChange={(event) => setAcademicSection(event.target.value)}
                        >
                            <option value="">All sections</option>
                            {academicOptions.sections.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-text-primary text-sm font-medium">
                        Non-sensitive custom field
                        <select
                            className={`${fieldClass} mt-1`}
                            value={customField}
                            onChange={(event) => setCustomField(event.target.value)}
                        >
                            <option value="">No custom field filter</option>
                            {filterCustomFields.map((field) => (
                                <option key={field.id} value={field.id}>
                                    {field.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    {customField && (
                        <label className="text-text-primary text-sm font-medium">
                            Custom field value
                            <input
                                className={`${fieldClass} mt-1`}
                                value={customValue}
                                onChange={(event) => setCustomValue(event.target.value)}
                                maxLength={180}
                                required
                            />
                        </label>
                    )}
                    <button className="bg-navy-900 min-h-11 rounded-lg px-5 text-sm font-semibold text-white">
                        Apply filters
                    </button>
                </form>

                {permissions.promotion && (
                    <section className="bg-surface border-border rounded-2xl border p-5 sm:p-6">
                        <h2 className="text-text-primary text-lg font-semibold">Bulk student promotion</h2>
                        <p className="text-text-muted mt-1 text-sm">
                            Select students from this result page, choose an active destination, review the
                            capacity check, then confirm. Promotion is a manual administrative decision; no
                            grades are inferred.
                        </p>
                        {promotionApprovalRequired && (
                            <p className="mt-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950">
                                Independent approval is required. Confirmation will submit a review request;
                                it will not promote students until a different authorized administrator
                                approves it.
                            </p>
                        )}
                        <form
                            className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                promotionForm.transform((data) => ({
                                    ...data,
                                    confirm: Boolean(bulkPromotionPreview),
                                    preview_token: bulkPromotionPreview?.token ?? '',
                                }));
                                promotionForm.post(`/t/${tenant.slug}/sis/students/bulk-promotion`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        if (bulkPromotionPreview) promotionForm.reset();
                                    },
                                });
                            }}
                        >
                            <label className="text-text-primary block text-sm font-medium">
                                Destination campus
                                <select
                                    disabled={Boolean(bulkPromotionPreview)}
                                    className={`${fieldClass} mt-1`}
                                    value={promotionForm.data.campus_id}
                                    onChange={(event) =>
                                        promotionForm.setData('campus_id', event.target.value)
                                    }
                                >
                                    <option value="">Use class campus</option>
                                    {campuses.map((campusOption) => (
                                        <option key={campusOption.id} value={campusOption.id}>
                                            {campusOption.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-text-primary block text-sm font-medium">
                                Academic session
                                <select
                                    disabled={Boolean(bulkPromotionPreview)}
                                    className={`${fieldClass} mt-1`}
                                    value={promotionForm.data.academic_year_id}
                                    onChange={(event) =>
                                        promotionForm.setData('academic_year_id', event.target.value)
                                    }
                                >
                                    <option value="">Use destination class session</option>
                                    {academicOptions.years.map((option) => (
                                        <option key={option.id} value={option.id}>
                                            {option.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-text-primary block text-sm font-medium">
                                Program
                                <select
                                    disabled={Boolean(bulkPromotionPreview)}
                                    className={`${fieldClass} mt-1`}
                                    value={promotionForm.data.academic_program_id}
                                    onChange={(event) =>
                                        promotionForm.setData('academic_program_id', event.target.value)
                                    }
                                >
                                    <option value="">Use destination class program</option>
                                    {academicOptions.programs.map((option) => (
                                        <option key={option.id} value={option.id}>
                                            {option.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-text-primary block text-sm font-medium">
                                Destination class or cohort
                                <select
                                    disabled={Boolean(bulkPromotionPreview)}
                                    required
                                    className={`${fieldClass} mt-1`}
                                    value={promotionForm.data.academic_class_id}
                                    onChange={(event) => {
                                        promotionForm.setData('academic_class_id', event.target.value);
                                        promotionForm.setData('academic_section_id', '');
                                    }}
                                >
                                    <option value="">Choose destination class</option>
                                    {academicOptions.classes.map((option) => (
                                        <option key={option.id} value={option.id}>
                                            {option.name} ({option.code ?? option.id})
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-text-primary block text-sm font-medium">
                                Destination section
                                <select
                                    disabled={Boolean(bulkPromotionPreview)}
                                    className={`${fieldClass} mt-1`}
                                    value={promotionForm.data.academic_section_id}
                                    onChange={(event) =>
                                        promotionForm.setData('academic_section_id', event.target.value)
                                    }
                                >
                                    <option value="">No section</option>
                                    {academicOptions.sections
                                        .filter(
                                            (option) =>
                                                option.classId ===
                                                Number(promotionForm.data.academic_class_id),
                                        )
                                        .map((option) => (
                                            <option key={option.id} value={option.id}>
                                                {option.name}
                                            </option>
                                        ))}
                                </select>
                            </label>
                            <label className="text-text-primary block text-sm font-medium">
                                Effective date
                                <input
                                    disabled={Boolean(bulkPromotionPreview)}
                                    required
                                    type="date"
                                    className={`${fieldClass} mt-1`}
                                    value={promotionForm.data.effective_from}
                                    onChange={(event) =>
                                        promotionForm.setData('effective_from', event.target.value)
                                    }
                                />
                            </label>
                            <label className="text-text-primary block text-sm font-medium">
                                Reason code
                                <input
                                    disabled={Boolean(bulkPromotionPreview)}
                                    required
                                    maxLength={48}
                                    className={`${fieldClass} mt-1`}
                                    value={promotionForm.data.reason_code}
                                    onChange={(event) =>
                                        promotionForm.setData('reason_code', event.target.value)
                                    }
                                />
                            </label>
                            <label className="text-text-primary block text-sm font-medium sm:col-span-2">
                                Administrative evidence or note
                                <textarea
                                    disabled={Boolean(bulkPromotionPreview)}
                                    maxLength={500}
                                    className={`${fieldClass} mt-1 min-h-20 py-2`}
                                    value={promotionForm.data.reason}
                                    onChange={(event) => promotionForm.setData('reason', event.target.value)}
                                />
                            </label>
                            {bulkPromotionPreview && (
                                <p
                                    className="text-text-primary bg-canvas rounded-lg p-3 text-sm sm:col-span-2 xl:col-span-4"
                                    role="status"
                                >
                                    Review: {bulkPromotionPreview.count} students will be promoted. No records
                                    changed during preview.
                                </p>
                            )}
                            {Object.keys(promotionForm.errors).length > 0 && (
                                <ul className="text-sm text-red-700 sm:col-span-2 xl:col-span-4" role="alert">
                                    {Object.entries(promotionForm.errors).map(([key, message]) => (
                                        <li key={key}>{message}</li>
                                    ))}
                                </ul>
                            )}
                            <div className="sm:col-span-2 xl:col-span-4">
                                <button
                                    disabled={
                                        promotionForm.processing ||
                                        promotionForm.data.student_ids.length === 0 ||
                                        !promotionForm.data.academic_class_id
                                    }
                                    className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-50"
                                >
                                    {promotionForm.processing
                                        ? 'Checking…'
                                        : bulkPromotionPreview
                                          ? 'Confirm bulk promotion'
                                          : `Review ${promotionForm.data.student_ids.length} selected students`}
                                </button>
                            </div>
                        </form>
                    </section>
                )}

                {permissions.approvePromotions && promotionApprovals.length > 0 && (
                    <section
                        className="bg-surface border-border rounded-2xl border p-5 sm:p-6"
                        aria-labelledby="promotion-approvals-heading"
                    >
                        <h2
                            id="promotion-approvals-heading"
                            className="text-text-primary text-lg font-semibold"
                        >
                            Promotion requests for review
                        </h2>
                        <div className="mt-4 space-y-4">
                            {promotionApprovals.map((batch) => {
                                return (
                                    <article key={batch.id} className="border-border rounded-xl border p-4">
                                        <h3 className="font-semibold">
                                            Request #{batch.id} ·{' '}
                                            {batch.destinationClass ?? 'Destination class'}
                                        </h3>
                                        <p className="text-text-muted mt-1 text-sm">
                                            Requested by{' '}
                                            {batch.requestedBy.name ?? 'institution administrator'} on{' '}
                                            {new Date(batch.requestedAt).toLocaleString()} · effective{' '}
                                            {batch.effectiveFrom} · {batch.students.length} students
                                        </p>
                                        <ul className="mt-2 flex flex-wrap gap-2 text-xs">
                                            {batch.students.map((student) => (
                                                <li
                                                    key={student.id}
                                                    className="bg-canvas rounded-full px-3 py-1"
                                                >
                                                    {student.number} · {student.name}
                                                </li>
                                            ))}
                                        </ul>
                                        {batch.reason && <p className="mt-2 text-sm">{batch.reason}</p>}
                                        {!batch.canDecide ? (
                                            <p className="text-warning-text mt-3 text-sm">
                                                You submitted this request. Another authorized administrator
                                                must decide it.
                                            </p>
                                        ) : (
                                            <form
                                                className="mt-4 grid gap-3 sm:grid-cols-[12rem_minmax(0,1fr)_auto]"
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    const formData = new FormData(event.currentTarget);
                                                    router.post(
                                                        `/t/${tenant.slug}/sis/promotion-batches/${batch.id}/decision`,
                                                        Object.fromEntries(formData.entries()),
                                                        { preserveScroll: true },
                                                    );
                                                }}
                                            >
                                                <label className="text-sm font-medium">
                                                    Decision
                                                    <select
                                                        name="decision"
                                                        className={`${fieldClass} mt-1`}
                                                        defaultValue="approved"
                                                    >
                                                        <option value="approved">Approve</option>
                                                        <option value="rejected">Reject</option>
                                                    </select>
                                                </label>
                                                <label className="text-sm font-medium">
                                                    Decision reason
                                                    <input
                                                        name="reason"
                                                        className={`${fieldClass} mt-1`}
                                                        minLength={10}
                                                        maxLength={500}
                                                        required
                                                    />
                                                </label>
                                                <button className="bg-brand-action min-h-11 self-end rounded-lg px-4 text-sm font-semibold text-white">
                                                    Record decision
                                                </button>
                                            </form>
                                        )}
                                    </article>
                                );
                            })}
                        </div>
                    </section>
                )}

                <section className="bg-surface border-border overflow-hidden rounded-2xl border">
                    <div className="border-border flex flex-wrap items-center justify-between gap-2 border-b px-5 py-4">
                        <h2 className="text-text-primary font-semibold">Registry results</h2>
                        <p className="text-text-muted text-sm">
                            {students.total.toLocaleString()} records · showing {students.from ?? 0}–
                            {students.to ?? 0}
                        </p>
                    </div>
                    {students.data.length === 0 ? (
                        <div className="px-5 py-14 text-center">
                            <h3 className="text-text-primary font-semibold">No matching student records</h3>
                            <p className="text-text-muted mt-2 text-sm">
                                Change your search or clear one of the filters.
                            </p>
                        </div>
                    ) : (
                        <>
                            <ul className="divide-border divide-y">
                                {students.data.map((student) => (
                                    <li key={student.id}>
                                        <div
                                            className={`hover:bg-canvas grid gap-2 px-5 py-4 sm:items-center ${permissions.promotion ? 'sm:grid-cols-[auto_minmax(0,1fr)_12rem_10rem_8rem]' : 'sm:grid-cols-[minmax(0,1fr)_12rem_10rem_8rem]'}`}
                                        >
                                            {permissions.promotion && (
                                                <label className="text-text-muted flex min-h-11 items-center gap-2 text-xs">
                                                    <input
                                                        type="checkbox"
                                                        aria-label={`Select ${student.name} for bulk promotion`}
                                                        checked={promotionForm.data.student_ids.includes(
                                                            student.id,
                                                        )}
                                                        disabled={Boolean(bulkPromotionPreview)}
                                                        onChange={(event) =>
                                                            togglePromotionStudent(
                                                                student.id,
                                                                event.target.checked,
                                                            )
                                                        }
                                                    />
                                                    Select
                                                </label>
                                            )}
                                            <Link
                                                href={`/t/${tenant.slug}/sis/students/${student.id}`}
                                                className="focus-visible:outline-brand-blue min-w-0 rounded focus-visible:outline-2"
                                            >
                                                <span className="min-w-0">
                                                    <span className="text-text-primary block truncate font-semibold">
                                                        {student.name}
                                                    </span>
                                                    <span className="text-text-muted mt-1 block text-xs">
                                                        {student.studentNumber} · Ref{' '}
                                                        {student.registrationReference}
                                                    </span>
                                                </span>
                                            </Link>
                                            <span className="text-text-muted text-sm">
                                                {student.homeCampus?.name ?? 'Institution wide'}
                                            </span>
                                            <span className="text-text-muted text-sm">
                                                {student.admissionDate}
                                            </span>
                                            <span className="bg-canvas text-text-primary w-fit rounded-full px-3 py-1 text-xs font-medium capitalize">
                                                {student.status.replaceAll('_', ' ')}
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            <nav
                                aria-label="Student results pages"
                                className="border-border flex flex-wrap gap-2 border-t p-4"
                            >
                                {students.links.map((link, index) =>
                                    link.url ? (
                                        <Link
                                            key={`${link.label}-${index}`}
                                            href={link.url}
                                            aria-current={link.active ? 'page' : undefined}
                                            className={`min-h-10 rounded-md px-3 py-2 text-sm ${link.active ? 'bg-navy-900 text-white' : 'border-border text-text-primary border'}`}
                                        >
                                            {link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}
                                        </Link>
                                    ) : (
                                        <span
                                            key={`${link.label}-${index}`}
                                            aria-disabled="true"
                                            className="text-text-muted px-3 py-2 text-sm"
                                        >
                                            {link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}
                                        </span>
                                    ),
                                )}
                            </nav>
                        </>
                    )}
                </section>

                {permissions.import && (
                    <section className="bg-surface border-border rounded-xl border p-5">
                        <h2 className="text-text-primary font-semibold">Bulk import</h2>
                        <p className="text-text-muted mt-1 text-sm">
                            Preview up to 500 rows, review any row errors, then reselect the same CSV to queue
                            valid records. Invalid rows are skipped and reported. Duplicate file content
                            cannot create a second batch.
                        </p>
                        <a
                            href={`/t/${tenant.slug}/sis/students/import-template`}
                            className="text-brand-link mt-3 inline-flex min-h-10 items-center text-sm font-semibold underline"
                        >
                            Download CSV template
                        </a>
                        {page.props.flash.sisImportPreview && (
                            <div className="border-border bg-canvas mt-3 rounded-lg border p-4 text-sm">
                                <p className="text-text-primary font-semibold">
                                    Preview: {page.props.flash.sisImportPreview.valid} valid,{' '}
                                    {page.props.flash.sisImportPreview.invalid} invalid
                                </p>
                                {page.props.flash.sisImportPreview.errors.slice(0, 10).map((error) => (
                                    <p key={error.row} className="text-text-muted mt-1">
                                        Row {error.row}: {error.errors.join(', ')}
                                    </p>
                                ))}
                                {page.props.flash.sisImportPreview.errorsTruncated && (
                                    <p className="text-text-muted text-sm">
                                        The preview lists the first 500 row errors; download the complete
                                        report after processing.
                                    </p>
                                )}
                                {page.props.flash.sisImportPreview.errorReportUrl && (
                                    <a
                                        href={page.props.flash.sisImportPreview.errorReportUrl}
                                        className="text-brand-link mt-3 inline-flex min-h-10 items-center text-sm font-semibold underline"
                                    >
                                        Download row-error CSV
                                    </a>
                                )}
                                <p className="text-text-muted mt-2">
                                    Mapped fields:{' '}
                                    {Object.entries(page.props.flash.sisImportPreview.mappedFields)
                                        .map(([field, header]) => `${field} ← ${header}`)
                                        .join(' · ')}
                                </p>
                                <p className="text-text-muted mt-2">
                                    Batch reference: {page.props.flash.sisImportPreview.batchReference}
                                </p>
                            </div>
                        )}
                        {importMappingPrompt && (
                            <div className="border-border bg-canvas mt-3 rounded-lg border p-4">
                                <h3 className="text-text-primary text-sm font-semibold">Map CSV columns</h3>
                                <p className="text-text-muted mt-1 text-sm">
                                    Choose the source column for each student field. Required fields are
                                    marked. Reselect the same file after mapping to preview the records.
                                </p>
                                <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    {Object.entries(importMappingPrompt.fields).map(([field, label]) => (
                                        <label
                                            key={field}
                                            className="text-text-primary block text-sm font-medium"
                                        >
                                            {label}
                                            {importMappingPrompt.required.includes(field) ? ' *' : ''}
                                            <select
                                                className={`${fieldClass} mt-1`}
                                                value={importForm.data.column_map[field] ?? ''}
                                                onChange={(event) =>
                                                    importForm.setData('column_map', {
                                                        ...importForm.data.column_map,
                                                        [field]: event.target.value,
                                                    })
                                                }
                                            >
                                                <option value="">Leave unmapped</option>
                                                {importMappingPrompt.headers.map((header) => (
                                                    <option key={header} value={header}>
                                                        {header}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                    ))}
                                </div>
                                {importMappingPrompt.missing.length > 0 && (
                                    <p className="text-warning-text mt-3 text-sm" role="status">
                                        Required mappings missing:{' '}
                                        {importMappingPrompt.missing
                                            .map((field) => importMappingPrompt.fields[field] ?? field)
                                            .join(', ')}
                                    </p>
                                )}
                            </div>
                        )}
                        <form
                            className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center"
                            onSubmit={(event) => {
                                event.preventDefault();
                                importForm.post(`/t/${tenant.slug}/sis/students/import`, {
                                    forceFormData: true,
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <label className="sr-only" htmlFor="sis-student-import-file">
                                CSV file to import
                            </label>
                            <input
                                id="sis-student-import-file"
                                type="file"
                                accept=".csv,text/csv"
                                className="text-text-primary min-h-11 max-w-full text-sm"
                                onChange={(event) =>
                                    importForm.setData('file', event.target.files?.[0] ?? null)
                                }
                                required
                            />
                            <label className="text-text-primary flex min-h-11 items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={importForm.data.confirm}
                                    onChange={(event) => importForm.setData('confirm', event.target.checked)}
                                />
                                Confirm after preview
                            </label>
                            <button
                                disabled={importForm.processing || !importForm.data.file}
                                className="bg-navy-900 min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                {importForm.processing
                                    ? 'Checking…'
                                    : importForm.data.confirm
                                      ? 'Confirm import'
                                      : 'Preview import'}
                            </button>
                        </form>
                        {Object.values(importForm.errors).length > 0 && (
                            <p role="alert" className="mt-2 text-sm text-rose-700">
                                {Object.values(importForm.errors).join(' ')}
                            </p>
                        )}
                    </section>
                )}

                {permissions.import && importBatches.length > 0 && (
                    <section className="bg-surface border-border rounded-xl border p-5">
                        <h2 className="text-text-primary font-semibold">Recent student imports</h2>
                        <ul className="divide-border mt-3 divide-y">
                            {importBatches.map((batch) => (
                                <li
                                    key={batch.reference}
                                    className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div>
                                        <p className="text-text-primary text-sm font-medium">
                                            {batch.status.replaceAll('_', ' ')} · {batch.processedCount} of{' '}
                                            {batch.rowCount} rows processed
                                        </p>
                                        <p className="text-text-muted text-xs">
                                            Batch {batch.reference}
                                            {batch.invalidCount > 0
                                                ? ` · ${batch.invalidCount} rows need review`
                                                : ''}
                                        </p>
                                    </div>
                                    {batch.errorsUrl && (
                                        <a
                                            href={batch.errorsUrl}
                                            className="text-brand-link min-h-10 text-sm font-semibold underline"
                                        >
                                            Download row errors
                                        </a>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </WorkspaceLayout>
    );
}
