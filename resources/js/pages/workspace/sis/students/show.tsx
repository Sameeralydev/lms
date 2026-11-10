import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Option = {
    id: number;
    name: string;
    code?: string | null;
    yearId?: number | null;
    programId?: number | null;
    classId?: number | null;
    campusId?: number | null;
};
type TransferRecord = {
    id: number;
    direction: 'incoming' | 'outgoing';
    status: string;
    externalInstitutionName: string;
    externalInstitutionCode: string | null;
    externalCountryCode: string | null;
    effectiveOn: string;
    reason: string;
    reviewDecision: string | null;
    reviewNote: string | null;
    completionNote: string | null;
    requestedBy: number;
    canReview: boolean;
    canComplete: boolean;
};
type IdentityReconciliationRecord = {
    id: number;
    status: string;
    currentIdentity: { id: number; name: string; email: string } | null;
    proposedIdentity: { id: number; name: string; email: string } | null;
    requestedBy: string | null;
    requestedAt: string;
    reasonCode: string;
    requestReason: string;
    reviewedBy: string | null;
    reviewedAt: string | null;
    decisionReason: string | null;
    canReview: boolean;
};
type Props = {
    tenant: { slug: string };
    student: {
        id: number;
        studentNumber: string;
        registrationReference: string;
        name: string;
        legalFirstName: string;
        legalMiddleName: string | null;
        legalLastName: string;
        preferredName: string | null;
        localizedNames: Record<string, string> | null;
        dateOfBirth: string | null;
        admissionDate: string;
        status: string;
        studentType: string;
        homeCampus: { id: number; name: string; code: string } | null;
        emergencyContact: { name: string | null; phone: string | null; relationship: string | null };
        previousInstitution: { name: string | null; reference: string | null };
        user: { id: number; name: string; email: string } | null;
        accountAccessStatus: string;
        handoff: { id: number; status: string; processed_at: string | null } | null;
        guardians: {
            id: number;
            name: string;
            email: string | null;
            phone: string | null;
            relationship: string;
            authorityStatus: string;
            primary: boolean;
            emergency: boolean;
            canViewProfile: boolean;
            verifiedAt: string | null;
            revokedAt: string | null;
            identityUser: { id: number; name: string; email: string } | null;
            identityInvitation: { email: string; expiresAt: string } | null;
            identityCandidates: { id: number; name: string | null; email: string | null }[];
        }[];
        placements: {
            id: number;
            type: string;
            status: string;
            campusId: number | null;
            year: string | null;
            program: string | null;
            class: string | null;
            section: string | null;
            effectiveFrom: string;
            effectiveUntil: string | null;
            reason: string | null;
        }[];
        promotionHolds: {
            id: number;
            status: string;
            reasonCode: string;
            reason: string;
            evidenceReference: string | null;
            createdAt: string | null;
            releasedAt: string | null;
            releaseReason: string | null;
        }[];
        transfers: TransferRecord[];
        transferEvidenceOptions: { id: number; name: string }[];
        customFields: { key: string; label: string; value: unknown; sensitive: boolean }[];
        customFieldDefinitions: {
            id: number;
            field_key: string;
            label: string;
            field_type: string;
            is_required: boolean;
            is_sensitive: boolean;
            options: string[] | null;
        }[];
        documents: {
            id: number;
            source: 'admissions' | 'sis';
            category: string;
            name: string;
            scanStatus: string;
            version?: number;
            expiresAt?: string | null;
            expired?: boolean;
            downloadUrl: string | null;
        }[];
    };
    events: {
        id: number;
        type: string;
        from: string | null;
        to: string | null;
        effectiveAt: string;
        reasonCode: string | null;
        reason: string | null;
    }[];
    familyGroups: {
        id: number;
        reference: string;
        label: string | null;
        currentRelationshipLabel: string | null;
        students: { id: number | null; number: string | null; name: string }[];
    }[];
    options: {
        campuses: Option[];
        years: Option[];
        programs: Option[];
        classes: Option[];
        sections: Option[];
    };
    identityCandidates: { id: number; name: string | null; email: string | null }[];
    identityInvitation: { email: string; expiresAt: string } | null;
    identityReconciliations: IdentityReconciliationRecord[];
    permissions: {
        update: boolean;
        status: boolean;
        placement: boolean;
        guardians: boolean;
        verifyGuardian: boolean;
        family: boolean;
        documents: boolean;
        manageDocuments: boolean;
        import: boolean;
        audit: boolean;
        customFields: boolean;
        sensitiveCustomFields: boolean;
        manageTransfers: boolean;
        approveTransfers: boolean;
        requestIdentityReconciliation: boolean;
        managePromotionHolds: boolean;
        correctPromotion: boolean;
    };
};

const inputClass =
    'border-border bg-surface text-text-primary min-h-11 w-full rounded-lg border px-3 text-sm focus-visible:outline-brand-blue focus-visible:outline-2';
const sectionClass = 'bg-surface border-border rounded-2xl border p-5 sm:p-6';
const statusChoices = [
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

function nextCalendarDate(value: string): string {
    if (!value) return '';
    const date = new Date(`${value}T12:00:00.000Z`);
    date.setUTCDate(date.getUTCDate() + 1);
    return date.toISOString().slice(0, 10);
}

export default function StudentShow({
    tenant,
    student,
    events,
    familyGroups,
    options,
    identityCandidates,
    identityInvitation,
    identityReconciliations,
    permissions,
}: Props) {
    const [editing, setEditing] = useState(false);
    const currentPromotion = student.placements.find(
        (placement) => placement.type === 'promotion' && placement.effectiveUntil === null,
    );
    const profile = useForm({
        legal_first_name: student.legalFirstName,
        legal_middle_name: student.legalMiddleName ?? '',
        legal_last_name: student.legalLastName,
        preferred_name: student.preferredName ?? '',
        date_of_birth: student.dateOfBirth ?? '',
        student_type: student.studentType,
        home_campus_id: student.homeCampus?.id?.toString() ?? '',
        emergency_contact_name: student.emergencyContact.name ?? '',
        emergency_contact_phone: student.emergencyContact.phone ?? '',
        emergency_contact_relationship: student.emergencyContact.relationship ?? '',
    });
    const statusForm = useForm({
        status: 'active',
        effective_at: new Date().toISOString().slice(0, 10),
        reason_code: 'administrative_change',
        reason: '',
    });
    const placementForm = useForm({
        type: 'placement',
        campus_id: student.homeCampus?.id?.toString() ?? '',
        academic_year_id: '',
        academic_program_id: '',
        academic_class_id: '',
        academic_section_id: '',
        effective_from: new Date().toISOString().slice(0, 10),
        reason_code: 'administrative_placement',
        reason: '',
    });
    const correctionForm = useForm({
        campus_id: student.homeCampus?.id?.toString() ?? '',
        academic_year_id: '',
        academic_program_id: '',
        academic_class_id: '',
        academic_section_id: '',
        effective_from: nextCalendarDate(currentPromotion?.effectiveFrom ?? ''),
        reason_code: 'promotion_correction',
        reason: '',
    });
    const promotionHoldForm = useForm({
        reason_code: 'progression_review',
        reason: '',
        evidence_reference: '',
    });
    const transferForm = useForm({
        direction: 'incoming',
        external_institution_name: '',
        external_institution_code: '',
        external_country_code: '',
        requested_effective_on: new Date().toISOString().slice(0, 10),
        request_reason: '',
        evidence_document_id: '',
    });
    const guardianForm = useForm({
        guardian_name: '',
        guardian_email: '',
        guardian_phone: '',
        relationship: '',
        is_primary_contact: false,
        is_emergency_contact: false,
    });
    const familyForm = useForm({
        label: '',
        relationship_label: '',
        verification_method: 'institution_record',
    });
    const familyLinkForm = useForm({
        group_id: '',
        student_id: '',
        relationship_label: '',
        verification_method: 'institution_record',
    });
    const [familySearch, setFamilySearch] = useState('');
    const [familyCandidates, setFamilyCandidates] = useState<
        { id: number; studentNumber: string; name: string }[]
    >([]);
    const [familySearchState, setFamilySearchState] = useState<'idle' | 'loading' | 'complete' | 'error'>(
        'idle',
    );
    const identityForm = useForm({ user_id: '' });
    const identityInviteForm = useForm({ identity_email: '' });
    const identityReconciliationForm = useForm({
        proposed_user_id: '',
        reason_code: 'corrected_link',
        request_reason: '',
    });
    const guardianIdentityInviteForm = useForm({});
    const customForm = useForm({
        field_key: '',
        label: '',
        field_type: 'text',
        is_required: false,
        is_sensitive: false,
        options: '',
    });
    const documentForm = useForm({
        category: 'identity',
        replace_document_id: '',
        file: null as File | null,
    });
    const page = usePage<{
        flash: {
            sisPlacementPreview?: { token: string; type: string; effectiveFrom: string } | null;
            sisCorrectionPreview?: { token: string; placementId: number } | null;
            status?: string;
        };
    }>();
    const placementPreview = page.props.flash.sisPlacementPreview;
    const correctionPreview = page.props.flash.sisCorrectionPreview;

    useEffect(() => {
        const groupId = familyLinkForm.data.group_id;
        const query = familySearch.trim();
        if (!groupId || query.length < 2) {
            return;
        }

        const controller = new AbortController();
        const timeout = window.setTimeout(() => {
            setFamilySearchState('loading');
            fetch(
                `/t/${tenant.slug}/sis/families/${groupId}/students/search?q=${encodeURIComponent(query)}`,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
                .then(async (response) => {
                    if (!response.ok) throw new Error('Family search could not be completed.');
                    return (await response.json()) as {
                        data: { id: number; studentNumber: string; name: string }[];
                    };
                })
                .then((payload) => {
                    setFamilyCandidates(payload.data);
                    setFamilySearchState('complete');
                })
                .catch((error: unknown) => {
                    if (error instanceof DOMException && error.name === 'AbortError') return;
                    setFamilyCandidates([]);
                    setFamilySearchState('error');
                });
        }, 250);

        return () => {
            window.clearTimeout(timeout);
            controller.abort();
        };
    }, [familyLinkForm.data.group_id, familySearch, tenant.slug]);

    function submit(event: FormEvent, action: () => void) {
        event.preventDefault();
        action();
    }

    return (
        <WorkspaceLayout area="tenant" title={`${student.name} · Student profile`}>
            <Head title={`${student.name} · Student profile`} />
            <div className="space-y-6">
                <header className="bg-surface border-border rounded-2xl border p-5 sm:p-7">
                    <Link
                        href={`/t/${tenant.slug}/sis/students`}
                        className="text-brand-link text-sm font-semibold"
                    >
                        ← Student registry
                    </Link>
                    <div className="mt-5 flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                        <div className="min-w-0">
                            <p className="text-brand-link text-xs font-bold tracking-[0.16em] uppercase">
                                Student 360°
                            </p>
                            <h1 className="text-text-primary mt-2 text-3xl font-semibold tracking-tight break-words">
                                {student.name}
                            </h1>
                            <p className="text-text-muted mt-2 text-sm">
                                {student.studentNumber} · Registration reference{' '}
                                {student.registrationReference}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="bg-canvas text-text-primary rounded-full px-3 py-2 text-sm font-semibold capitalize">
                                {student.status.replaceAll('_', ' ')}
                            </span>
                            <span className="text-text-muted text-sm">
                                {student.homeCampus?.name ?? 'Institution wide'}
                            </span>
                            {permissions.update && (
                                <button
                                    onClick={() => setEditing((value) => !value)}
                                    className="border-border text-text-primary min-h-11 rounded-lg border px-4 text-sm font-semibold"
                                >
                                    {editing ? 'Close profile edit' : 'Edit profile'}
                                </button>
                            )}
                        </div>
                    </div>
                    {editing && permissions.update && (
                        <form
                            onSubmit={(event) =>
                                submit(event, () =>
                                    profile.put(`/t/${tenant.slug}/sis/students/${student.id}`, {
                                        preserveScroll: true,
                                        onSuccess: () => setEditing(false),
                                    }),
                                )
                            }
                            className="border-border mt-6 grid gap-3 border-t pt-5 sm:grid-cols-2 xl:grid-cols-4"
                        >
                            <label className="text-text-primary text-sm font-medium">
                                Legal first name
                                <input
                                    className={`${inputClass} mt-1`}
                                    value={profile.data.legal_first_name}
                                    onChange={(event) =>
                                        profile.setData('legal_first_name', event.target.value)
                                    }
                                    required
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Middle name
                                <input
                                    className={`${inputClass} mt-1`}
                                    value={profile.data.legal_middle_name}
                                    onChange={(event) =>
                                        profile.setData('legal_middle_name', event.target.value)
                                    }
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Legal family name
                                <input
                                    className={`${inputClass} mt-1`}
                                    value={profile.data.legal_last_name}
                                    onChange={(event) =>
                                        profile.setData('legal_last_name', event.target.value)
                                    }
                                    required
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Preferred name
                                <input
                                    className={`${inputClass} mt-1`}
                                    value={profile.data.preferred_name}
                                    onChange={(event) =>
                                        profile.setData('preferred_name', event.target.value)
                                    }
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Date of birth
                                <input
                                    type="date"
                                    className={`${inputClass} mt-1`}
                                    value={profile.data.date_of_birth}
                                    onChange={(event) => profile.setData('date_of_birth', event.target.value)}
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Student category
                                <input
                                    className={`${inputClass} mt-1`}
                                    value={profile.data.student_type}
                                    onChange={(event) => profile.setData('student_type', event.target.value)}
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Home campus
                                <select
                                    className={`${inputClass} mt-1`}
                                    value={profile.data.home_campus_id}
                                    onChange={(event) =>
                                        profile.setData('home_campus_id', event.target.value)
                                    }
                                >
                                    <option value="">Institution wide</option>
                                    {options.campuses.map((campus) => (
                                        <option key={campus.id} value={campus.id}>
                                            {campus.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Emergency contact
                                <input
                                    className={`${inputClass} mt-1`}
                                    value={profile.data.emergency_contact_name}
                                    onChange={(event) =>
                                        profile.setData('emergency_contact_name', event.target.value)
                                    }
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Emergency phone
                                <input
                                    className={`${inputClass} mt-1`}
                                    value={profile.data.emergency_contact_phone}
                                    onChange={(event) =>
                                        profile.setData('emergency_contact_phone', event.target.value)
                                    }
                                />
                            </label>
                            <label className="text-text-primary text-sm font-medium">
                                Emergency relationship
                                <input
                                    className={`${inputClass} mt-1`}
                                    value={profile.data.emergency_contact_relationship}
                                    onChange={(event) =>
                                        profile.setData('emergency_contact_relationship', event.target.value)
                                    }
                                />
                            </label>
                            <div className="sm:col-span-2 xl:col-span-4">
                                <button
                                    disabled={profile.processing}
                                    className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                                >
                                    Save profile
                                </button>
                                {Object.values(profile.errors).length > 0 && (
                                    <p role="alert" className="mt-2 text-sm text-rose-700">
                                        {Object.values(profile.errors).join(' ')}
                                    </p>
                                )}
                            </div>
                        </form>
                    )}
                </header>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(18rem,0.85fr)]">
                    <div className="space-y-6">
                        <section className={sectionClass}>
                            <h2 className="text-text-primary text-lg font-semibold">
                                Identity and admission
                            </h2>
                            <dl className="mt-4 grid gap-4 sm:grid-cols-2">
                                <Info
                                    label="Legal name"
                                    value={[
                                        student.legalFirstName,
                                        student.legalMiddleName,
                                        student.legalLastName,
                                    ]
                                        .filter(Boolean)
                                        .join(' ')}
                                />
                                <Info label="Preferred name" value={student.preferredName} />
                                <Info label="Date of birth" value={student.dateOfBirth} />
                                <Info label="Admission date" value={student.admissionDate} />
                                <Info label="Category" value={student.studentType} />
                                <Info label="Previous institution" value={student.previousInstitution.name} />
                                <Info
                                    label="Previous reference"
                                    value={student.previousInstitution.reference}
                                />
                                <Info
                                    label="Linked account"
                                    value={
                                        student.user
                                            ? `${student.user.name} · ${student.user.email}`
                                            : 'No login account linked'
                                    }
                                />
                                <Info
                                    label="Institution login access"
                                    value={accountAccessLabel(student.accountAccessStatus)}
                                />
                                <Info
                                    label="Admission handoff"
                                    value={
                                        student.handoff
                                            ? `${student.handoff.status}${student.handoff.processed_at ? ` · ${student.handoff.processed_at}` : ''}`
                                            : 'Manual registry record'
                                    }
                                />
                                <Info
                                    label="Emergency contact"
                                    value={[
                                        student.emergencyContact.name,
                                        student.emergencyContact.relationship,
                                        student.emergencyContact.phone,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                />
                            </dl>
                            {permissions.update && !student.user && identityCandidates.length > 0 && (
                                <form
                                    onSubmit={(event) =>
                                        submit(event, () =>
                                            identityForm.post(
                                                `/t/${tenant.slug}/sis/students/${student.id}/identity`,
                                                { preserveScroll: true },
                                            ),
                                        )
                                    }
                                    className="border-border mt-5 flex flex-col gap-3 border-t pt-4 sm:flex-row"
                                >
                                    <label className="text-text-primary flex-1 text-sm font-medium">
                                        Link an existing active institution account
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={identityForm.data.user_id}
                                            onChange={(event) =>
                                                identityForm.setData('user_id', event.target.value)
                                            }
                                            required
                                        >
                                            <option value="">Select an account</option>
                                            {identityCandidates.map((candidate) => (
                                                <option key={candidate.id} value={candidate.id}>
                                                    {candidate.name} · {candidate.email}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <button
                                        disabled={identityForm.processing}
                                        className="bg-navy-900 min-h-11 self-end rounded-lg px-4 text-sm font-semibold text-white"
                                    >
                                        Link identity
                                    </button>
                                </form>
                            )}
                            {identityInvitation && (
                                <p className="text-text-muted mt-4 text-sm" role="status">
                                    A student account invitation is pending for {identityInvitation.email}{' '}
                                    until {identityInvitation.expiresAt.slice(0, 10)}.
                                </p>
                            )}
                            {permissions.update && !student.user && (
                                <form
                                    onSubmit={(event) =>
                                        submit(event, () =>
                                            identityInviteForm.post(
                                                `/t/${tenant.slug}/sis/students/${student.id}/identity/invite`,
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () => identityInviteForm.reset(),
                                                },
                                            ),
                                        )
                                    }
                                    className="border-border mt-5 space-y-3 border-t pt-4"
                                >
                                    <div>
                                        <h3 className="text-text-primary font-semibold">
                                            Invite a student login
                                        </h3>
                                        <p className="text-text-muted mt-1 text-sm">
                                            The student accepts a signed, single-use invitation with this
                                            email to create or activate their institution account.
                                        </p>
                                    </div>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Student email
                                        <input
                                            type="email"
                                            className={`${inputClass} mt-1`}
                                            value={identityInviteForm.data.identity_email}
                                            onChange={(event) =>
                                                identityInviteForm.setData(
                                                    'identity_email',
                                                    event.target.value,
                                                )
                                            }
                                            autoComplete="email"
                                            required
                                        />
                                    </label>
                                    <button
                                        disabled={identityInviteForm.processing}
                                        className="bg-navy-900 min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                                    >
                                        Send account invitation
                                    </button>
                                    {Object.values(identityInviteForm.errors).length > 0 && (
                                        <p role="alert" className="text-sm text-rose-700">
                                            {Object.values(identityInviteForm.errors).join(' ')}
                                        </p>
                                    )}
                                </form>
                            )}
                            {permissions.requestIdentityReconciliation && student.user && (
                                <form
                                    onSubmit={(event) =>
                                        submit(event, () =>
                                            identityReconciliationForm.post(
                                                `/t/${tenant.slug}/sis/students/${student.id}/identity-reconciliations`,
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () => identityReconciliationForm.reset(),
                                                },
                                            ),
                                        )
                                    }
                                    className="border-border mt-5 space-y-3 border-t pt-4"
                                >
                                    <div>
                                        <h3 className="text-text-primary font-semibold">
                                            Request a student identity correction
                                        </h3>
                                        <p className="text-text-muted mt-1 text-sm">
                                            A different authorized administrator must review this request. The
                                            selected account must already be an active member of this
                                            institution and cannot be linked to another student.
                                        </p>
                                    </div>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Proposed active institution account
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={identityReconciliationForm.data.proposed_user_id}
                                            onChange={(event) =>
                                                identityReconciliationForm.setData(
                                                    'proposed_user_id',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        >
                                            <option value="">Select an unlinked account</option>
                                            {identityCandidates.map((candidate) => (
                                                <option key={candidate.id} value={candidate.id}>
                                                    {candidate.name} · {candidate.email}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Review basis
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={identityReconciliationForm.data.reason_code}
                                            onChange={(event) =>
                                                identityReconciliationForm.setData(
                                                    'reason_code',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="corrected_link">Correct an account link</option>
                                            <option value="duplicate_record_review">
                                                Resolve a reviewed duplicate-record concern
                                            </option>
                                            <option value="verified_identity_update">
                                                Verified identity update
                                            </option>
                                            <option value="other">Other reviewed correction</option>
                                        </select>
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Factual review note
                                        <textarea
                                            className={`${inputClass} mt-1 min-h-24 py-2`}
                                            value={identityReconciliationForm.data.request_reason}
                                            onChange={(event) =>
                                                identityReconciliationForm.setData(
                                                    'request_reason',
                                                    event.target.value,
                                                )
                                            }
                                            minLength={20}
                                            maxLength={500}
                                            required
                                        />
                                        <span className="text-text-muted mt-1 block text-xs">
                                            Do not include passwords, identity numbers or unnecessary
                                            sensitive details.
                                        </span>
                                    </label>
                                    <button
                                        disabled={
                                            identityReconciliationForm.processing ||
                                            identityCandidates.length === 0
                                        }
                                        className="bg-navy-900 min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                                    >
                                        Submit for independent review
                                    </button>
                                    {Object.values(identityReconciliationForm.errors).length > 0 && (
                                        <p role="alert" className="text-sm text-rose-700">
                                            {Object.values(identityReconciliationForm.errors).join(' ')}
                                        </p>
                                    )}
                                </form>
                            )}
                            {identityReconciliations.length > 0 && (
                                <section className="border-border mt-5 space-y-3 border-t pt-4">
                                    <h3 className="text-text-primary font-semibold">
                                        Identity correction review history
                                    </h3>
                                    {identityReconciliations.map((caseRecord) => (
                                        <div
                                            key={caseRecord.id}
                                            className="border-border rounded-xl border p-4"
                                        >
                                            <p className="text-text-primary text-sm font-medium">
                                                {identityCaseStatus(caseRecord.status)} ·{' '}
                                                {caseRecord.currentIdentity?.name ?? 'Previous account'} →{' '}
                                                {caseRecord.proposedIdentity?.name ?? 'Proposed account'}
                                            </p>
                                            <p className="text-text-muted mt-1 text-sm">
                                                Requested by {caseRecord.requestedBy ?? 'institution staff'} ·{' '}
                                                {new Date(caseRecord.requestedAt).toLocaleDateString()}
                                            </p>
                                            <p className="text-text-muted mt-2 text-sm">
                                                {caseRecord.requestReason}
                                            </p>
                                            {caseRecord.reviewedBy && (
                                                <p className="text-text-muted mt-2 text-sm">
                                                    Reviewed by {caseRecord.reviewedBy}
                                                    {caseRecord.decisionReason
                                                        ? `: ${caseRecord.decisionReason}`
                                                        : ''}
                                                </p>
                                            )}
                                            {caseRecord.canReview && (
                                                <IdentityReconciliationReview
                                                    tenant={tenant.slug}
                                                    studentId={student.id}
                                                    caseId={caseRecord.id}
                                                />
                                            )}
                                        </div>
                                    ))}
                                </section>
                            )}
                        </section>

                        <section className={sectionClass}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <h2 className="text-text-primary text-lg font-semibold">
                                        Academic placements
                                    </h2>
                                    <p className="text-text-muted mt-1 text-sm">
                                        Effective-dated class and campus history.
                                    </p>
                                </div>
                            </div>
                            {student.placements.length === 0 ? (
                                <p className="text-text-muted bg-canvas mt-5 rounded-lg p-4 text-sm">
                                    No academic placement has been recorded.
                                </p>
                            ) : (
                                <ol className="mt-5 space-y-3">
                                    {student.placements.map((placement) => (
                                        <li
                                            key={placement.id}
                                            className="border-border rounded-xl border p-4"
                                        >
                                            <div className="flex flex-wrap justify-between gap-2">
                                                <strong className="text-text-primary capitalize">
                                                    {placement.type.replaceAll('_', ' ')}
                                                </strong>
                                                <span className="text-text-muted text-sm">
                                                    {placement.effectiveFrom}
                                                    {placement.effectiveUntil
                                                        ? ` — ${placement.effectiveUntil}`
                                                        : ' — current'}
                                                </span>
                                            </div>
                                            <p className="text-text-muted mt-2 text-sm">
                                                {[
                                                    placement.year,
                                                    placement.program,
                                                    placement.class,
                                                    placement.section,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ') || 'Institution or campus affiliation'}
                                            </p>
                                            {placement.reason && (
                                                <p className="text-text-muted mt-1 text-xs">
                                                    {placement.reason}
                                                </p>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}
                            {permissions.placement && (
                                <form
                                    onSubmit={(event) =>
                                        submit(event, () =>
                                            placementForm.post(
                                                `/t/${tenant.slug}/sis/students/${student.id}/placements`,
                                                { preserveScroll: true },
                                            ),
                                        )
                                    }
                                    className="border-border mt-5 grid gap-3 border-t pt-5 sm:grid-cols-2"
                                >
                                    <h3 className="text-text-primary font-semibold sm:col-span-2">
                                        Preview placement, transfer or retention
                                    </h3>
                                    <label className="text-text-primary text-sm font-medium">
                                        Action
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={placementForm.data.type}
                                            onChange={(event) =>
                                                placementForm.setData('type', event.target.value)
                                            }
                                        >
                                            <option value="placement">Initial placement</option>
                                            <option value="transfer">Same institution transfer</option>
                                            <option value="retention">Retention</option>
                                        </select>
                                    </label>
                                    <label className="text-text-primary text-sm font-medium">
                                        Effective date
                                        <input
                                            type="date"
                                            className={`${inputClass} mt-1`}
                                            value={placementForm.data.effective_from}
                                            onChange={(event) =>
                                                placementForm.setData('effective_from', event.target.value)
                                            }
                                            required
                                        />
                                    </label>
                                    <label className="text-text-primary text-sm font-medium">
                                        Campus
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={placementForm.data.campus_id}
                                            onChange={(event) =>
                                                placementForm.setData('campus_id', event.target.value)
                                            }
                                        >
                                            <option value="">Use destination class campus</option>
                                            {options.campuses.map((row) => (
                                                <option key={row.id} value={row.id}>
                                                    {row.name}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="text-text-primary text-sm font-medium">
                                        Academic year
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={placementForm.data.academic_year_id}
                                            onChange={(event) =>
                                                placementForm.setData('academic_year_id', event.target.value)
                                            }
                                        >
                                            <option value="">Not specified</option>
                                            {options.years.map((row) => (
                                                <option key={row.id} value={row.id}>
                                                    {row.name}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="text-text-primary text-sm font-medium">
                                        Program
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={placementForm.data.academic_program_id}
                                            onChange={(event) =>
                                                placementForm.setData(
                                                    'academic_program_id',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="">Not specified</option>
                                            {options.programs.map((row) => (
                                                <option key={row.id} value={row.id}>
                                                    {row.name}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="text-text-primary text-sm font-medium">
                                        Class or cohort
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={placementForm.data.academic_class_id}
                                            onChange={(event) =>
                                                placementForm.setData('academic_class_id', event.target.value)
                                            }
                                        >
                                            <option value="">Not specified</option>
                                            {options.classes.map((row) => (
                                                <option key={row.id} value={row.id}>
                                                    {row.name} · {row.code}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="text-text-primary text-sm font-medium">
                                        Section
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={placementForm.data.academic_section_id}
                                            onChange={(event) =>
                                                placementForm.setData(
                                                    'academic_section_id',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="">Not specified</option>
                                            {options.sections
                                                .filter(
                                                    (row) =>
                                                        !placementForm.data.academic_class_id ||
                                                        row.classId?.toString() ===
                                                            placementForm.data.academic_class_id,
                                                )
                                                .map((row) => (
                                                    <option key={row.id} value={row.id}>
                                                        {row.name} · {row.code}
                                                    </option>
                                                ))}
                                        </select>
                                    </label>
                                    <label className="text-text-primary text-sm font-medium">
                                        Reason code
                                        <input
                                            className={`${inputClass} mt-1`}
                                            value={placementForm.data.reason_code}
                                            onChange={(event) =>
                                                placementForm.setData('reason_code', event.target.value)
                                            }
                                            maxLength={48}
                                            required
                                        />
                                    </label>
                                    <label className="text-text-primary text-sm font-medium sm:col-span-2">
                                        Administrative note
                                        <input
                                            className={`${inputClass} mt-1`}
                                            value={placementForm.data.reason}
                                            onChange={(event) =>
                                                placementForm.setData('reason', event.target.value)
                                            }
                                            maxLength={500}
                                        />
                                    </label>
                                    <button
                                        disabled={placementForm.processing}
                                        className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white sm:col-span-2"
                                    >
                                        Preview change
                                    </button>
                                    {placementPreview && (
                                        <div className="rounded-lg border border-cyan-300 bg-cyan-50 p-3 text-sm text-cyan-950 sm:col-span-2">
                                            <p className="font-semibold">
                                                Preview ready: {placementPreview.type} effective{' '}
                                                {placementPreview.effectiveFrom}
                                            </p>
                                            <p className="mt-1">
                                                No student record has changed yet. Confirm this exact form
                                                within 10 minutes.
                                            </p>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.post(
                                                        `/t/${tenant.slug}/sis/students/${student.id}/placements`,
                                                        {
                                                            ...placementForm.data,
                                                            confirm: true,
                                                            preview_token: placementPreview.token,
                                                        },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                className="mt-3 min-h-10 rounded-lg bg-cyan-800 px-4 text-sm font-semibold text-white"
                                            >
                                                Confirm change
                                            </button>
                                        </div>
                                    )}
                                    {Object.values(placementForm.errors).length > 0 && (
                                        <p role="alert" className="text-sm text-rose-700 sm:col-span-2">
                                            {Object.values(placementForm.errors).join(' ')}
                                        </p>
                                    )}
                                </form>
                            )}
                        </section>

                        {(permissions.managePromotionHolds ||
                            (permissions.correctPromotion && currentPromotion)) && (
                            <section className={sectionClass} aria-labelledby="promotion-governance-heading">
                                <h2
                                    id="promotion-governance-heading"
                                    className="text-text-primary text-lg font-semibold"
                                >
                                    Promotion governance
                                </h2>
                                <p className="text-text-muted mt-1 text-sm">
                                    Document progression holds and make corrections through a separate,
                                    auditable process.
                                </p>
                                {student.promotionHolds.length > 0 && (
                                    <ol className="mt-4 space-y-3">
                                        {student.promotionHolds.map((hold) => (
                                            <li key={hold.id} className="border-border rounded-xl border p-4">
                                                <div className="flex flex-wrap justify-between gap-2">
                                                    <strong className="capitalize">
                                                        {hold.status} hold · {hold.reasonCode}
                                                    </strong>
                                                    <span className="text-text-muted text-xs">
                                                        {hold.createdAt
                                                            ? new Date(hold.createdAt).toLocaleString()
                                                            : ''}
                                                    </span>
                                                </div>
                                                <p className="mt-2 text-sm">{hold.reason}</p>
                                                {hold.evidenceReference && (
                                                    <p className="text-text-muted mt-1 text-xs">
                                                        Evidence reference: {hold.evidenceReference}
                                                    </p>
                                                )}
                                                {hold.releaseReason && (
                                                    <p className="text-text-muted mt-1 text-xs">
                                                        Release reason: {hold.releaseReason}
                                                    </p>
                                                )}
                                                {permissions.managePromotionHolds &&
                                                    hold.status === 'active' && (
                                                        <form
                                                            className="mt-3 flex flex-col gap-2 sm:flex-row"
                                                            onSubmit={(event) => {
                                                                event.preventDefault();
                                                                const formData = new FormData(
                                                                    event.currentTarget,
                                                                );
                                                                router.patch(
                                                                    `/t/${tenant.slug}/sis/students/${student.id}/promotion-holds/${hold.id}/release`,
                                                                    Object.fromEntries(formData.entries()),
                                                                    { preserveScroll: true },
                                                                );
                                                            }}
                                                        >
                                                            <label
                                                                className="sr-only"
                                                                htmlFor={`hold-release-${hold.id}`}
                                                            >
                                                                Reason for releasing hold
                                                            </label>
                                                            <input
                                                                id={`hold-release-${hold.id}`}
                                                                name="release_reason"
                                                                className={inputClass}
                                                                minLength={10}
                                                                maxLength={500}
                                                                placeholder="Reason for releasing this hold"
                                                                required
                                                            />
                                                            <button className="bg-brand-action min-h-11 shrink-0 rounded-lg px-4 text-sm font-semibold text-white">
                                                                Release hold
                                                            </button>
                                                        </form>
                                                    )}
                                            </li>
                                        ))}
                                    </ol>
                                )}
                                {permissions.managePromotionHolds && (
                                    <form
                                        className="border-border mt-5 grid gap-3 border-t pt-5 sm:grid-cols-2"
                                        onSubmit={(event) =>
                                            submit(event, () =>
                                                promotionHoldForm.post(
                                                    `/t/${tenant.slug}/sis/students/${student.id}/promotion-holds`,
                                                    { preserveScroll: true },
                                                ),
                                            )
                                        }
                                    >
                                        <h3 className="font-semibold sm:col-span-2">
                                            Place a progression hold
                                        </h3>
                                        <label className="text-sm font-medium">
                                            Reason code
                                            <input
                                                className={`${inputClass} mt-1`}
                                                maxLength={48}
                                                required
                                                value={promotionHoldForm.data.reason_code}
                                                onChange={(event) =>
                                                    promotionHoldForm.setData(
                                                        'reason_code',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <label className="text-sm font-medium">
                                            Evidence reference
                                            <input
                                                className={`${inputClass} mt-1`}
                                                maxLength={160}
                                                value={promotionHoldForm.data.evidence_reference}
                                                onChange={(event) =>
                                                    promotionHoldForm.setData(
                                                        'evidence_reference',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <label className="text-sm font-medium sm:col-span-2">
                                            Reason
                                            <textarea
                                                className={`${inputClass} mt-1 min-h-20 py-2`}
                                                minLength={10}
                                                maxLength={500}
                                                required
                                                value={promotionHoldForm.data.reason}
                                                onChange={(event) =>
                                                    promotionHoldForm.setData('reason', event.target.value)
                                                }
                                            />
                                        </label>
                                        <button
                                            disabled={promotionHoldForm.processing}
                                            className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white sm:col-span-2"
                                        >
                                            Record progression hold
                                        </button>
                                        {Object.values(promotionHoldForm.errors).length > 0 && (
                                            <p role="alert" className="text-sm text-rose-700 sm:col-span-2">
                                                {Object.values(promotionHoldForm.errors).join(' ')}
                                            </p>
                                        )}
                                    </form>
                                )}
                                {permissions.correctPromotion && currentPromotion && (
                                    <form
                                        className="border-border mt-5 grid gap-3 border-t pt-5 sm:grid-cols-2"
                                        onSubmit={(event) =>
                                            submit(event, () =>
                                                correctionForm.post(
                                                    `/t/${tenant.slug}/sis/students/${student.id}/placements/${currentPromotion.id}/correction`,
                                                    { preserveScroll: true },
                                                ),
                                            )
                                        }
                                    >
                                        <h3 className="font-semibold sm:col-span-2">
                                            Preview compensating correction
                                        </h3>
                                        <p className="text-text-muted text-sm sm:col-span-2">
                                            The current promotion remains in history. Corrections take effect
                                            after {currentPromotion.effectiveFrom} and obey destination
                                            capacity limits.
                                        </p>
                                        <label className="text-sm font-medium">
                                            Effective date
                                            <input
                                                type="date"
                                                min={nextCalendarDate(currentPromotion.effectiveFrom)}
                                                className={`${inputClass} mt-1`}
                                                required
                                                value={correctionForm.data.effective_from}
                                                onChange={(event) =>
                                                    correctionForm.setData(
                                                        'effective_from',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <label className="text-sm font-medium">
                                            Destination class
                                            <select
                                                className={`${inputClass} mt-1`}
                                                required
                                                value={correctionForm.data.academic_class_id}
                                                onChange={(event) =>
                                                    correctionForm.setData(
                                                        'academic_class_id',
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">Choose class</option>
                                                {options.classes.map((option) => (
                                                    <option key={option.id} value={option.id}>
                                                        {option.name} · {option.code}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                        <label className="text-sm font-medium">
                                            Academic year
                                            <select
                                                className={`${inputClass} mt-1`}
                                                value={correctionForm.data.academic_year_id}
                                                onChange={(event) =>
                                                    correctionForm.setData(
                                                        'academic_year_id',
                                                        event.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">Use class default</option>
                                                {options.years.map((option) => (
                                                    <option key={option.id} value={option.id}>
                                                        {option.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                        <label className="text-sm font-medium">
                                            Reason code
                                            <input
                                                className={`${inputClass} mt-1`}
                                                maxLength={48}
                                                required
                                                value={correctionForm.data.reason_code}
                                                onChange={(event) =>
                                                    correctionForm.setData('reason_code', event.target.value)
                                                }
                                            />
                                        </label>
                                        <label className="text-sm font-medium sm:col-span-2">
                                            Correction rationale
                                            <textarea
                                                className={`${inputClass} mt-1 min-h-20 py-2`}
                                                minLength={10}
                                                maxLength={500}
                                                required
                                                value={correctionForm.data.reason}
                                                onChange={(event) =>
                                                    correctionForm.setData('reason', event.target.value)
                                                }
                                            />
                                        </label>
                                        <button
                                            disabled={correctionForm.processing}
                                            className="bg-brand-action min-h-11 rounded-lg px-4 text-sm font-semibold text-white sm:col-span-2"
                                        >
                                            Review correction
                                        </button>
                                        {correctionPreview?.placementId === currentPromotion.id && (
                                            <div
                                                role="status"
                                                className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm sm:col-span-2"
                                            >
                                                Review destination and rationale. The existing promotion will
                                                remain in the timeline.
                                                <button
                                                    type="button"
                                                    className="bg-brand-action ml-3 min-h-10 rounded-lg px-3 font-semibold text-white"
                                                    onClick={() =>
                                                        router.post(
                                                            `/t/${tenant.slug}/sis/students/${student.id}/placements/${currentPromotion.id}/correction`,
                                                            {
                                                                ...correctionForm.data,
                                                                confirm: true,
                                                                preview_token: correctionPreview.token,
                                                            },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    Confirm correction
                                                </button>
                                            </div>
                                        )}
                                        {Object.values(correctionForm.errors).length > 0 && (
                                            <p role="alert" className="text-sm text-rose-700 sm:col-span-2">
                                                {Object.values(correctionForm.errors).join(' ')}
                                            </p>
                                        )}
                                    </form>
                                )}
                            </section>
                        )}

                        {(permissions.manageTransfers || permissions.approveTransfers) && (
                            <section className={sectionClass} aria-labelledby="external-transfers-heading">
                                <h2
                                    id="external-transfers-heading"
                                    className="text-text-primary text-lg font-semibold"
                                >
                                    External transfer history
                                </h2>
                                <p className="text-text-muted mt-1 text-sm">
                                    Incoming and outgoing institution transfers require a clean transfer
                                    certificate and an independent decision.
                                </p>
                                {student.transfers.length === 0 ? (
                                    <p className="text-text-muted bg-canvas mt-4 rounded-lg p-4 text-sm">
                                        No external transfer cases have been recorded.
                                    </p>
                                ) : (
                                    <ol className="mt-4 space-y-3">
                                        {student.transfers.map((transfer) => (
                                            <TransferCaseCard
                                                key={transfer.id}
                                                tenant={tenant.slug}
                                                transfer={transfer}
                                            />
                                        ))}
                                    </ol>
                                )}
                                {permissions.manageTransfers &&
                                    (student.transferEvidenceOptions.length === 0 ? (
                                        <p className="text-text-muted border-border mt-4 rounded-lg border p-3 text-sm">
                                            Upload a transfer certificate in Documents and wait for its
                                            malware scan to finish before submitting a request.
                                        </p>
                                    ) : (
                                        <form
                                            onSubmit={(event) => {
                                                event.preventDefault();
                                                transferForm.post(
                                                    `/t/${tenant.slug}/sis/students/${student.id}/transfers`,
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess: () =>
                                                            transferForm.reset(
                                                                'external_institution_name',
                                                                'external_institution_code',
                                                                'external_country_code',
                                                                'request_reason',
                                                                'evidence_document_id',
                                                            ),
                                                    },
                                                );
                                            }}
                                            className="border-border mt-5 grid gap-3 border-t pt-5 sm:grid-cols-2"
                                        >
                                            <h3 className="text-text-primary font-semibold sm:col-span-2">
                                                Request an external transfer
                                            </h3>
                                            <label className="text-text-primary block text-sm font-medium">
                                                Direction
                                                <select
                                                    className={`${inputClass} mt-1`}
                                                    value={transferForm.data.direction}
                                                    onChange={(event) =>
                                                        transferForm.setData('direction', event.target.value)
                                                    }
                                                >
                                                    <option value="incoming">
                                                        Incoming from another institution
                                                    </option>
                                                    <option value="outgoing">
                                                        Outgoing to another institution
                                                    </option>
                                                </select>
                                            </label>
                                            <label className="text-text-primary block text-sm font-medium">
                                                {transferForm.data.direction === 'incoming'
                                                    ? 'Previous institution'
                                                    : 'Receiving institution'}
                                                <input
                                                    className={`${inputClass} mt-1`}
                                                    value={transferForm.data.external_institution_name}
                                                    onChange={(event) =>
                                                        transferForm.setData(
                                                            'external_institution_name',
                                                            event.target.value,
                                                        )
                                                    }
                                                    required
                                                />
                                            </label>
                                            <label className="text-text-primary block text-sm font-medium">
                                                Institution reference
                                                <input
                                                    className={`${inputClass} mt-1`}
                                                    value={transferForm.data.external_institution_code}
                                                    onChange={(event) =>
                                                        transferForm.setData(
                                                            'external_institution_code',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                            <label className="text-text-primary block text-sm font-medium">
                                                Country code
                                                <input
                                                    autoCapitalize="characters"
                                                    maxLength={2}
                                                    className={`${inputClass} mt-1`}
                                                    value={transferForm.data.external_country_code}
                                                    onChange={(event) =>
                                                        transferForm.setData(
                                                            'external_country_code',
                                                            event.target.value.toUpperCase(),
                                                        )
                                                    }
                                                />
                                            </label>
                                            <label className="text-text-primary block text-sm font-medium">
                                                Effective date
                                                <input
                                                    type="date"
                                                    className={`${inputClass} mt-1`}
                                                    value={transferForm.data.requested_effective_on}
                                                    onChange={(event) =>
                                                        transferForm.setData(
                                                            'requested_effective_on',
                                                            event.target.value,
                                                        )
                                                    }
                                                    required
                                                />
                                            </label>
                                            <label className="text-text-primary block text-sm font-medium">
                                                Clean transfer certificate
                                                <select
                                                    className={`${inputClass} mt-1`}
                                                    value={transferForm.data.evidence_document_id}
                                                    onChange={(event) =>
                                                        transferForm.setData(
                                                            'evidence_document_id',
                                                            event.target.value,
                                                        )
                                                    }
                                                    required
                                                >
                                                    <option value="">Choose scanned evidence</option>
                                                    {student.transferEvidenceOptions.map((document) => (
                                                        <option key={document.id} value={document.id}>
                                                            {document.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>
                                            <label className="text-text-primary block text-sm font-medium sm:col-span-2">
                                                Reason for transfer
                                                <textarea
                                                    className={`${inputClass} mt-1 min-h-24 py-2`}
                                                    value={transferForm.data.request_reason}
                                                    onChange={(event) =>
                                                        transferForm.setData(
                                                            'request_reason',
                                                            event.target.value,
                                                        )
                                                    }
                                                    minLength={10}
                                                    maxLength={500}
                                                    required
                                                />
                                            </label>
                                            {Object.values(transferForm.errors).length > 0 && (
                                                <p
                                                    role="alert"
                                                    className="text-sm text-rose-700 sm:col-span-2"
                                                >
                                                    {Object.values(transferForm.errors).join(' ')}
                                                </p>
                                            )}
                                            <div className="sm:col-span-2">
                                                <button
                                                    disabled={transferForm.processing}
                                                    className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                                                >
                                                    {transferForm.processing
                                                        ? 'Submitting…'
                                                        : 'Submit for independent review'}
                                                </button>
                                            </div>
                                        </form>
                                    ))}
                            </section>
                        )}

                        {permissions.audit && (
                            <section className={sectionClass}>
                                <h2 className="text-text-primary text-lg font-semibold">
                                    Administrative timeline
                                </h2>
                                {events.length === 0 ? (
                                    <p className="text-text-muted mt-4 text-sm">
                                        No lifecycle events have been recorded.
                                    </p>
                                ) : (
                                    <ol className="border-border mt-5 space-y-4 border-l pl-5">
                                        {events.map((event) => (
                                            <li key={event.id} className="relative">
                                                <span className="bg-brand-blue absolute top-1 -left-[1.63rem] h-2.5 w-2.5 rounded-full" />
                                                <p className="text-text-primary text-sm font-semibold">
                                                    {event.type.replaceAll('_', ' ')}
                                                    {event.to ? ` · ${event.to.replaceAll('_', ' ')}` : ''}
                                                </p>
                                                <p className="text-text-muted mt-1 text-xs">
                                                    {event.effectiveAt} ·{' '}
                                                    {event.reasonCode ?? 'No reason code'}
                                                </p>
                                                {event.reason && (
                                                    <p className="text-text-muted mt-1 text-sm">
                                                        {event.reason}
                                                    </p>
                                                )}
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </section>
                        )}
                    </div>

                    <aside className="space-y-6">
                        {permissions.status && (
                            <section className={sectionClass}>
                                <h2 className="text-text-primary text-lg font-semibold">Lifecycle status</h2>
                                <p className="text-text-muted mt-1 text-sm">
                                    Transitions are validated by the server and preserved in history.
                                </p>
                                <form
                                    onSubmit={(event) =>
                                        submit(event, () =>
                                            statusForm.patch(
                                                `/t/${tenant.slug}/sis/students/${student.id}/status`,
                                                { preserveScroll: true },
                                            ),
                                        )
                                    }
                                    className="mt-4 space-y-3"
                                >
                                    <label className="text-text-primary block text-sm font-medium">
                                        New status
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={statusForm.data.status}
                                            onChange={(event) =>
                                                statusForm.setData('status', event.target.value)
                                            }
                                        >
                                            {statusChoices.map((value) => (
                                                <option key={value} value={value}>
                                                    {value.replaceAll('_', ' ')}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Effective date
                                        <input
                                            type="date"
                                            className={`${inputClass} mt-1`}
                                            value={statusForm.data.effective_at}
                                            onChange={(event) =>
                                                statusForm.setData('effective_at', event.target.value)
                                            }
                                            required
                                        />
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Reason code
                                        <input
                                            className={`${inputClass} mt-1`}
                                            value={statusForm.data.reason_code}
                                            onChange={(event) =>
                                                statusForm.setData('reason_code', event.target.value)
                                            }
                                            required
                                        />
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Administrative note
                                        <input
                                            className={`${inputClass} mt-1`}
                                            value={statusForm.data.reason}
                                            onChange={(event) =>
                                                statusForm.setData('reason', event.target.value)
                                            }
                                        />
                                    </label>
                                    <button
                                        disabled={statusForm.processing}
                                        className="bg-navy-900 min-h-11 w-full rounded-lg px-4 text-sm font-semibold text-white"
                                    >
                                        Record status change
                                    </button>
                                    {Object.values(statusForm.errors).length > 0 && (
                                        <p role="alert" className="text-sm text-rose-700">
                                            {Object.values(statusForm.errors).join(' ')}
                                        </p>
                                    )}
                                </form>
                            </section>
                        )}

                        <section className={sectionClass}>
                            <div className="flex items-center justify-between">
                                <h2 className="text-text-primary text-lg font-semibold">Guardians</h2>
                                <span className="text-text-muted text-sm">{student.guardians.length}</span>
                            </div>
                            <p className="text-text-muted mt-1 text-sm">
                                Verified authority is separate from profile access.
                            </p>
                            {student.guardians.length === 0 ? (
                                <p className="text-text-muted mt-4 text-sm">
                                    No guardian relationships recorded.
                                </p>
                            ) : (
                                <ul className="mt-4 space-y-3">
                                    {student.guardians.map((guardian) => (
                                        <li key={guardian.id} className="border-border rounded-xl border p-3">
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <p className="text-text-primary font-semibold">
                                                        {guardian.name}
                                                    </p>
                                                    <p className="text-text-muted mt-1 text-xs">
                                                        {guardian.relationship} · {guardian.authorityStatus}
                                                    </p>
                                                    <p className="text-text-muted mt-1 text-xs">
                                                        {guardian.email ??
                                                            guardian.phone ??
                                                            'No contact detail'}
                                                    </p>
                                                    {guardian.identityUser && (
                                                        <p className="mt-2 text-xs text-emerald-800">
                                                            Account linked: {guardian.identityUser.name} ·{' '}
                                                            {guardian.identityUser.email}. Profile access
                                                            remains disabled.
                                                        </p>
                                                    )}
                                                    {guardian.identityInvitation && (
                                                        <p
                                                            className="text-warning-text mt-2 text-xs"
                                                            role="status"
                                                        >
                                                            Account invitation pending for{' '}
                                                            {guardian.identityInvitation.email} until{' '}
                                                            {guardian.identityInvitation.expiresAt.slice(
                                                                0,
                                                                10,
                                                            )}
                                                            . Profile access remains disabled.
                                                        </p>
                                                    )}
                                                </div>
                                                {guardian.primary && (
                                                    <span className="rounded-full bg-cyan-50 px-2 py-1 text-xs text-cyan-800">
                                                        Primary
                                                    </span>
                                                )}
                                            </div>
                                            {guardian.revokedAt && (
                                                <p className="mt-2 text-xs text-rose-700">
                                                    Revoked {guardian.revokedAt}
                                                </p>
                                            )}
                                            {permissions.verifyGuardian &&
                                                guardian.authorityStatus === 'pending' && (
                                                    <VerifyGuardian
                                                        tenant={tenant.slug}
                                                        id={guardian.id}
                                                        candidates={guardian.identityCandidates}
                                                    />
                                                )}
                                            {permissions.verifyGuardian &&
                                                guardian.authorityStatus === 'verified' &&
                                                guardian.revokedAt === null &&
                                                !guardian.identityUser &&
                                                guardian.identityCandidates.length > 0 && (
                                                    <LinkGuardianIdentity
                                                        tenant={tenant.slug}
                                                        id={guardian.id}
                                                        candidates={guardian.identityCandidates}
                                                    />
                                                )}
                                            {permissions.verifyGuardian &&
                                                guardian.authorityStatus === 'verified' &&
                                                guardian.revokedAt === null &&
                                                !guardian.identityUser &&
                                                !guardian.identityInvitation &&
                                                guardian.identityCandidates.length === 0 &&
                                                guardian.email && (
                                                    <form
                                                        onSubmit={(event) => {
                                                            event.preventDefault();
                                                            guardianIdentityInviteForm.post(
                                                                `/t/${tenant.slug}/sis/guardians/${guardian.id}/identity/invite`,
                                                                { preserveScroll: true },
                                                            );
                                                        }}
                                                        className="border-border mt-3 border-t pt-3"
                                                    >
                                                        <p className="text-text-muted mb-2 text-xs">
                                                            Invite this verified contact to create an account.
                                                            The parent role has no SIS profile access.
                                                        </p>
                                                        <button
                                                            disabled={guardianIdentityInviteForm.processing}
                                                            className="bg-navy-900 min-h-10 rounded-lg px-3 text-xs font-semibold text-white"
                                                        >
                                                            Invite guardian account
                                                        </button>
                                                        {Object.values(guardianIdentityInviteForm.errors)
                                                            .length > 0 && (
                                                            <p
                                                                role="alert"
                                                                className="mt-2 text-sm text-rose-700"
                                                            >
                                                                {Object.values(
                                                                    guardianIdentityInviteForm.errors,
                                                                ).join(' ')}
                                                            </p>
                                                        )}
                                                    </form>
                                                )}
                                            {permissions.guardians &&
                                                guardian.authorityStatus !== 'revoked' && (
                                                    <RevokeGuardian tenant={tenant.slug} id={guardian.id} />
                                                )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {permissions.guardians && (
                                <form
                                    onSubmit={(event) =>
                                        submit(event, () =>
                                            guardianForm.post(
                                                `/t/${tenant.slug}/sis/students/${student.id}/guardians`,
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () => guardianForm.reset(),
                                                },
                                            ),
                                        )
                                    }
                                    className="border-border mt-5 space-y-3 border-t pt-4"
                                >
                                    <h3 className="text-text-primary font-semibold">Add relationship</h3>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Guardian name
                                        <input
                                            className={`${inputClass} mt-1`}
                                            value={guardianForm.data.guardian_name}
                                            onChange={(event) =>
                                                guardianForm.setData('guardian_name', event.target.value)
                                            }
                                            required
                                        />
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Email
                                        <input
                                            type="email"
                                            className={`${inputClass} mt-1`}
                                            value={guardianForm.data.guardian_email}
                                            onChange={(event) =>
                                                guardianForm.setData('guardian_email', event.target.value)
                                            }
                                        />
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Phone
                                        <input
                                            className={`${inputClass} mt-1`}
                                            value={guardianForm.data.guardian_phone}
                                            onChange={(event) =>
                                                guardianForm.setData('guardian_phone', event.target.value)
                                            }
                                        />
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Relationship
                                        <input
                                            className={`${inputClass} mt-1`}
                                            value={guardianForm.data.relationship}
                                            onChange={(event) =>
                                                guardianForm.setData('relationship', event.target.value)
                                            }
                                            required
                                        />
                                    </label>
                                    <label className="text-text-primary flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={guardianForm.data.is_primary_contact}
                                            onChange={(event) =>
                                                guardianForm.setData(
                                                    'is_primary_contact',
                                                    event.target.checked,
                                                )
                                            }
                                        />
                                        Primary contact
                                    </label>
                                    <label className="text-text-primary flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={guardianForm.data.is_emergency_contact}
                                            onChange={(event) =>
                                                guardianForm.setData(
                                                    'is_emergency_contact',
                                                    event.target.checked,
                                                )
                                            }
                                        />
                                        Emergency contact
                                    </label>
                                    <button className="bg-navy-900 min-h-11 w-full rounded-lg text-sm font-semibold text-white">
                                        Add pending guardian
                                    </button>
                                    {Object.values(guardianForm.errors).length > 0 && (
                                        <p role="alert" className="text-sm text-rose-700">
                                            {Object.values(guardianForm.errors).join(' ')}
                                        </p>
                                    )}
                                </form>
                            )}
                        </section>

                        {permissions.family && (
                            <section className={sectionClass}>
                                <h2 className="text-text-primary text-lg font-semibold">
                                    Institution family groups
                                </h2>
                                <p className="text-text-muted mt-1 text-sm">
                                    Group membership does not grant sibling profile access.
                                </p>
                                {familyGroups.map((group) => (
                                    <FamilyGroupCard
                                        key={group.id}
                                        group={group}
                                        tenant={tenant.slug}
                                        studentId={student.id}
                                    />
                                ))}
                                <form
                                    onSubmit={(event) =>
                                        submit(event, () =>
                                            familyForm.post(
                                                `/t/${tenant.slug}/sis/students/${student.id}/families`,
                                                { preserveScroll: true, onSuccess: () => familyForm.reset() },
                                            ),
                                        )
                                    }
                                    className="border-border mt-4 space-y-3 border-t pt-4"
                                >
                                    <label className="text-text-primary block text-sm font-medium">
                                        Family group label
                                        <input
                                            className={`${inputClass} mt-1`}
                                            value={familyForm.data.label}
                                            onChange={(event) =>
                                                familyForm.setData('label', event.target.value)
                                            }
                                        />
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Relationship to this student
                                        <input
                                            className={`${inputClass} mt-1`}
                                            value={familyForm.data.relationship_label}
                                            onChange={(event) =>
                                                familyForm.setData('relationship_label', event.target.value)
                                            }
                                        />
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Verification basis
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={familyForm.data.verification_method}
                                            onChange={(event) =>
                                                familyForm.setData('verification_method', event.target.value)
                                            }
                                            required
                                        >
                                            <option value="institution_record">Institution record</option>
                                            <option value="document_review">Document review</option>
                                            <option value="guardian_confirmation">
                                                Guardian confirmation
                                            </option>
                                        </select>
                                    </label>
                                    <button className="bg-navy-900 min-h-11 w-full rounded-lg text-sm font-semibold text-white">
                                        Create family group
                                    </button>
                                    {Object.values(familyForm.errors).length > 0 && (
                                        <p role="alert" className="text-sm text-rose-700">
                                            {Object.values(familyForm.errors).join(' ')}
                                        </p>
                                    )}
                                </form>
                                <form
                                    onSubmit={(event) =>
                                        submit(event, () =>
                                            familyLinkForm.post(
                                                `/t/${tenant.slug}/sis/families/${familyLinkForm.data.group_id}/students`,
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () => familyLinkForm.reset(),
                                                },
                                            ),
                                        )
                                    }
                                    className="mt-4 space-y-3"
                                >
                                    <label className="text-text-primary block text-sm font-medium">
                                        Add another student to group
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={familyLinkForm.data.group_id}
                                            onChange={(event) => {
                                                familyLinkForm.setData({
                                                    ...familyLinkForm.data,
                                                    group_id: event.target.value,
                                                    student_id: '',
                                                });
                                                setFamilySearch('');
                                                setFamilyCandidates([]);
                                                setFamilySearchState('idle');
                                            }}
                                            required
                                        >
                                            <option value="">Choose group</option>
                                            {familyGroups.map((group) => (
                                                <option key={group.id} value={group.id}>
                                                    {group.label ?? group.reference}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Search institution students
                                        <input
                                            type="search"
                                            className={`${inputClass} mt-1`}
                                            value={familySearch}
                                            onChange={(event) => {
                                                const value = event.target.value;
                                                setFamilySearch(value);
                                                setFamilyCandidates([]);
                                                setFamilySearchState('idle');
                                                if (value.trim().length < 2) {
                                                    familyLinkForm.setData('student_id', '');
                                                }
                                            }}
                                            minLength={2}
                                            maxLength={80}
                                            aria-describedby="sis-family-search-help"
                                        />
                                    </label>
                                    <p id="sis-family-search-help" className="text-text-muted text-xs">
                                        Search by student name or institutional number. Results are limited to
                                        students you are authorized to manage.
                                    </p>
                                    {familySearchState === 'loading' && (
                                        <p role="status" className="text-text-muted text-sm">
                                            Searching institution records…
                                        </p>
                                    )}
                                    {familySearchState === 'error' && (
                                        <p role="alert" className="text-sm text-rose-700">
                                            Family search is temporarily unavailable.
                                        </p>
                                    )}
                                    {familyCandidates.length > 0 && (
                                        <fieldset className="border-border rounded-xl border p-3">
                                            <legend className="text-text-primary px-1 text-sm font-medium">
                                                Matching students
                                            </legend>
                                            <div className="max-h-48 space-y-2 overflow-y-auto">
                                                {familyCandidates.map((candidate) => (
                                                    <label
                                                        key={candidate.id}
                                                        className="text-text-primary flex min-h-10 cursor-pointer items-center gap-3 text-sm"
                                                    >
                                                        <input
                                                            type="radio"
                                                            name="family_student_id"
                                                            value={candidate.id}
                                                            checked={
                                                                familyLinkForm.data.student_id ===
                                                                String(candidate.id)
                                                            }
                                                            onChange={() =>
                                                                familyLinkForm.setData(
                                                                    'student_id',
                                                                    String(candidate.id),
                                                                )
                                                            }
                                                        />
                                                        <span>
                                                            {candidate.name} · {candidate.studentNumber}
                                                        </span>
                                                    </label>
                                                ))}
                                            </div>
                                        </fieldset>
                                    )}
                                    {familySearchState === 'complete' && familyCandidates.length === 0 && (
                                        <p className="text-text-muted text-sm">
                                            No eligible students matched this search.
                                        </p>
                                    )}
                                    <label className="text-text-primary block text-sm font-medium">
                                        Relationship to this family
                                        <input
                                            className={`${inputClass} mt-1`}
                                            value={familyLinkForm.data.relationship_label}
                                            onChange={(event) =>
                                                familyLinkForm.setData(
                                                    'relationship_label',
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={48}
                                            required
                                        />
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Verification basis
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={familyLinkForm.data.verification_method}
                                            onChange={(event) =>
                                                familyLinkForm.setData(
                                                    'verification_method',
                                                    event.target.value,
                                                )
                                            }
                                            required
                                        >
                                            <option value="institution_record">Institution record</option>
                                            <option value="document_review">Document review</option>
                                            <option value="guardian_confirmation">
                                                Guardian confirmation
                                            </option>
                                        </select>
                                    </label>
                                    <button
                                        disabled={
                                            familyGroups.length === 0 ||
                                            familyLinkForm.processing ||
                                            !familyLinkForm.data.student_id
                                        }
                                        className="border-border text-text-primary min-h-11 w-full rounded-lg border text-sm font-semibold disabled:opacity-50"
                                    >
                                        Verify and link student
                                    </button>
                                    {Object.values(familyLinkForm.errors).length > 0 && (
                                        <p role="alert" className="text-sm text-rose-700">
                                            {Object.values(familyLinkForm.errors).join(' ')}
                                        </p>
                                    )}
                                </form>
                            </section>
                        )}

                        <section className={sectionClass}>
                            <h2 className="text-text-primary text-lg font-semibold">Documents</h2>
                            <p className="text-text-muted mt-1 text-sm">
                                Files stay in private quarantine until the current version passes malware
                                scanning.
                            </p>
                            {permissions.manageDocuments && (
                                <form
                                    onSubmit={(event) =>
                                        submit(event, () =>
                                            documentForm.post(
                                                `/t/${tenant.slug}/sis/students/${student.id}/private-documents`,
                                                {
                                                    forceFormData: true,
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        documentForm.reset('file', 'replace_document_id'),
                                                },
                                            ),
                                        )
                                    }
                                    className="border-border mt-4 grid gap-3 rounded-xl border p-4 sm:grid-cols-2"
                                >
                                    <label className="text-text-primary block text-sm font-medium">
                                        Document category
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={documentForm.data.category}
                                            onChange={(event) =>
                                                documentForm.setData('category', event.target.value)
                                            }
                                        >
                                            <option value="identity">Identity record</option>
                                            <option value="previous_academic_record">
                                                Previous academic record
                                            </option>
                                            <option value="transfer_certificate">Transfer certificate</option>
                                            <option value="guardian_consent">Guardian consent</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Replace existing file
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={documentForm.data.replace_document_id}
                                            onChange={(event) =>
                                                documentForm.setData(
                                                    'replace_document_id',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="">Upload as a new document</option>
                                            {student.documents
                                                .filter((document) => document.source === 'sis')
                                                .map((document) => (
                                                    <option key={document.id} value={document.id}>
                                                        {document.name} (version {document.version ?? 1})
                                                    </option>
                                                ))}
                                        </select>
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium sm:col-span-2">
                                        PDF, JPEG or PNG · maximum 10 MB
                                        <input
                                            required
                                            type="file"
                                            accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                            className={`${inputClass} mt-1 py-2`}
                                            onChange={(event) =>
                                                documentForm.setData(
                                                    'file',
                                                    event.currentTarget.files?.[0] ?? null,
                                                )
                                            }
                                        />
                                    </label>
                                    {Object.keys(documentForm.errors).length > 0 && (
                                        <ul className="text-sm text-red-700 sm:col-span-2" role="alert">
                                            {Object.entries(documentForm.errors).map(([key, message]) => (
                                                <li key={key}>{message}</li>
                                            ))}
                                        </ul>
                                    )}
                                    <div className="sm:col-span-2">
                                        <button
                                            disabled={documentForm.processing}
                                            className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                                        >
                                            {documentForm.processing ? 'Uploading…' : 'Upload to quarantine'}
                                        </button>
                                    </div>
                                </form>
                            )}
                            {!permissions.documents || student.documents.length === 0 ? (
                                <p className="text-text-muted mt-4 text-sm">
                                    No student documents are available.
                                </p>
                            ) : (
                                <ul className="mt-4 space-y-2">
                                    {student.documents.map((document) => (
                                        <li
                                            key={`${document.source}:${document.id}`}
                                            className="border-border flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3"
                                        >
                                            <span>
                                                <span className="text-text-primary block text-sm font-semibold">
                                                    {document.name}
                                                </span>
                                                <span className="text-text-muted text-xs">
                                                    {document.category} ·{' '}
                                                    {document.source === 'sis'
                                                        ? `version ${document.version ?? 1} · `
                                                        : ''}
                                                    scan {document.scanStatus}
                                                    {document.expiresAt && (
                                                        <span className="block">
                                                            Expires {document.expiresAt.slice(0, 10)}
                                                            {document.expired ? ' · expired' : ''}
                                                        </span>
                                                    )}
                                                </span>
                                            </span>
                                            {document.downloadUrl && (
                                                <a
                                                    href={document.downloadUrl}
                                                    className="text-brand-link text-sm font-semibold underline"
                                                >
                                                    Download
                                                </a>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        {permissions.update && student.customFieldDefinitions.length > 0 && (
                            <section className={sectionClass}>
                                <h2 className="text-text-primary text-lg font-semibold">
                                    Student attributes
                                </h2>
                                <div className="mt-4 space-y-4">
                                    {student.customFieldDefinitions.map((field) => (
                                        <CustomValueEditor
                                            key={field.id}
                                            tenant={tenant.slug}
                                            studentId={student.id}
                                            field={field}
                                            currentValue={
                                                student.customFields.find(
                                                    (value) => value.key === field.field_key,
                                                )?.value
                                            }
                                        />
                                    ))}
                                </div>
                            </section>
                        )}
                        {permissions.customFields && (
                            <section className={sectionClass}>
                                <h2 className="text-text-primary text-lg font-semibold">
                                    Institution field definitions
                                </h2>
                                {student.customFields.map((field) => (
                                    <p key={field.key} className="text-text-muted mt-2 text-sm">
                                        <strong className="text-text-primary">{field.label}:</strong>{' '}
                                        {String(field.value ?? '—')}
                                        {field.sensitive ? ' · encrypted' : ''}
                                    </p>
                                ))}
                                {student.customFieldDefinitions.map((field) => (
                                    <p key={field.id} className="text-text-muted mt-2 text-sm">
                                        {field.label}{' '}
                                        <span className="text-xs">
                                            ({field.field_type}
                                            {field.is_required ? ', required' : ''}
                                            {field.is_sensitive ? ', encrypted' : ''})
                                        </span>
                                    </p>
                                ))}
                                <form
                                    onSubmit={(event) =>
                                        submit(event, () => {
                                            customForm.transform((data) => ({
                                                ...data,
                                                options: data.options
                                                    .split(',')
                                                    .map((value: string) => value.trim())
                                                    .filter(Boolean),
                                            }));
                                            customForm.post(`/t/${tenant.slug}/sis/custom-fields`, {
                                                preserveScroll: true,
                                                onSuccess: () => customForm.reset(),
                                            });
                                        })
                                    }
                                    className="border-border mt-4 space-y-3 border-t pt-4"
                                >
                                    <h3 className="text-text-primary font-semibold">Add a custom field</h3>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Field key
                                        <input
                                            className={`${inputClass} mt-1`}
                                            value={customForm.data.field_key}
                                            onChange={(event) =>
                                                customForm.setData('field_key', event.target.value)
                                            }
                                            required
                                        />
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Label
                                        <input
                                            className={`${inputClass} mt-1`}
                                            value={customForm.data.label}
                                            onChange={(event) =>
                                                customForm.setData('label', event.target.value)
                                            }
                                            required
                                        />
                                    </label>
                                    <label className="text-text-primary block text-sm font-medium">
                                        Data type
                                        <select
                                            className={`${inputClass} mt-1`}
                                            value={customForm.data.field_type}
                                            onChange={(event) =>
                                                customForm.setData('field_type', event.target.value)
                                            }
                                        >
                                            {[
                                                'text',
                                                'number',
                                                'date',
                                                'select',
                                                'multiselect',
                                                'boolean',
                                            ].map((type) => (
                                                <option key={type} value={type}>
                                                    {type}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    {['select', 'multiselect'].includes(customForm.data.field_type) && (
                                        <label className="text-text-primary block text-sm font-medium">
                                            Allowed options, comma separated
                                            <input
                                                className={`${inputClass} mt-1`}
                                                value={customForm.data.options}
                                                onChange={(event) =>
                                                    customForm.setData('options', event.target.value)
                                                }
                                            />
                                        </label>
                                    )}
                                    <label className="text-text-primary flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={customForm.data.is_required}
                                            onChange={(event) =>
                                                customForm.setData('is_required', event.target.checked)
                                            }
                                        />
                                        Required field
                                    </label>
                                    {permissions.sensitiveCustomFields && (
                                        <label className="text-text-primary flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={customForm.data.is_sensitive}
                                                onChange={(event) =>
                                                    customForm.setData('is_sensitive', event.target.checked)
                                                }
                                            />
                                            Encrypt field value
                                        </label>
                                    )}
                                    <button className="bg-navy-900 min-h-11 w-full rounded-lg text-sm font-semibold text-white">
                                        Create custom field
                                    </button>
                                </form>
                            </section>
                        )}
                    </aside>
                </div>
            </div>
        </WorkspaceLayout>
    );
}

function FamilyGroupCard({
    group,
    tenant,
    studentId,
}: {
    group: Props['familyGroups'][number];
    tenant: string;
    studentId: number;
}) {
    const form = useForm({
        label: group.label ?? '',
        relationship_label: group.currentRelationshipLabel ?? '',
        verification_method: 'institution_record',
    });

    return (
        <div className="border-border mt-3 rounded-xl border p-3">
            <p className="text-text-muted text-xs">{group.reference}</p>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(`/t/${tenant}/sis/students/${studentId}/families/${group.id}`, {
                        preserveScroll: true,
                    });
                }}
                className="mt-2 space-y-3"
            >
                <label className="text-text-primary block text-sm font-medium">
                    Family group label
                    <input
                        className={`${inputClass} mt-1`}
                        value={form.data.label}
                        maxLength={120}
                        onChange={(event) => form.setData('label', event.target.value)}
                    />
                </label>
                <label className="text-text-primary block text-sm font-medium">
                    This student’s family relationship
                    <input
                        className={`${inputClass} mt-1`}
                        value={form.data.relationship_label}
                        maxLength={48}
                        required
                        onChange={(event) => form.setData('relationship_label', event.target.value)}
                    />
                </label>
                <label className="text-text-primary block text-sm font-medium">
                    Verification basis
                    <select
                        className={`${inputClass} mt-1`}
                        value={form.data.verification_method}
                        onChange={(event) => form.setData('verification_method', event.target.value)}
                    >
                        <option value="institution_record">Institution record</option>
                        <option value="document_review">Document review</option>
                        <option value="guardian_confirmation">Guardian confirmation</option>
                    </select>
                </label>
                <button
                    disabled={form.processing}
                    className="border-border text-text-primary min-h-10 rounded-lg border px-3 text-sm font-semibold disabled:opacity-50"
                >
                    {form.processing ? 'Saving…' : 'Save and verify relationship'}
                </button>
                {Object.values(form.errors).length > 0 && (
                    <p role="alert" className="text-sm text-rose-700">
                        {Object.values(form.errors).join(' ')}
                    </p>
                )}
            </form>
            <p className="text-text-muted mt-3 text-xs">
                Verified by an authorized administrator. Family membership does not grant sibling record
                access.
            </p>
            <ul className="text-text-muted mt-2 space-y-1 text-sm">
                {group.students.map((member) => (
                    <li key={member.id}>
                        {member.name} · {member.number}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function IdentityReconciliationReview({
    tenant,
    studentId,
    caseId,
}: {
    tenant: string;
    studentId: number;
    caseId: number;
}) {
    const form = useForm({ decision: 'approve', decision_reason: '' });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(
                    `/t/${tenant}/sis/students/${studentId}/identity-reconciliations/${caseId}/decision`,
                    { preserveScroll: true },
                );
            }}
            className="border-border mt-4 grid gap-3 border-t pt-3 sm:grid-cols-2"
        >
            <label className="text-text-primary block text-sm font-medium">
                Independent decision
                <select
                    className={`${inputClass} mt-1`}
                    value={form.data.decision}
                    onChange={(event) => form.setData('decision', event.target.value)}
                >
                    <option value="approve">Approve and apply correction</option>
                    <option value="reject">Reject request</option>
                </select>
            </label>
            <label className="text-text-primary block text-sm font-medium sm:col-span-2">
                Decision rationale
                <textarea
                    className={`${inputClass} mt-1 min-h-20 py-2`}
                    value={form.data.decision_reason}
                    onChange={(event) => form.setData('decision_reason', event.target.value)}
                    minLength={10}
                    maxLength={500}
                    required
                />
            </label>
            <button
                disabled={form.processing}
                className="bg-navy-900 min-h-11 justify-self-start rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
            >
                Record independent decision
            </button>
            {Object.values(form.errors).length > 0 && (
                <p role="alert" className="text-sm text-rose-700 sm:col-span-2">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
        </form>
    );
}

function identityCaseStatus(status: string): string {
    return (
        {
            pending: 'Pending independent review',
            approved: 'Approved and applied',
            rejected: 'Rejected',
            stale: 'Stale and not applied',
        }[status] ?? 'Review status unavailable'
    );
}

function accountAccessLabel(status: string): string {
    return (
        {
            active: 'Active institution membership',
            suspended: 'Suspended institution membership',
            suspended_by_student_lifecycle: 'Suspended by student status policy',
            revoked: 'Revoked institution membership',
            no_institution_membership: 'No institution membership',
            not_linked: 'No login identity linked',
        }[status] ?? 'Membership status unavailable'
    );
}

function Info({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-text-muted text-xs font-medium">{label}</dt>
            <dd className="text-text-primary mt-1 text-sm break-words">{value || '—'}</dd>
        </div>
    );
}

function LinkGuardianIdentity({
    tenant,
    id,
    candidates,
}: {
    tenant: string;
    id: number;
    candidates: { id: number; name: string | null; email: string | null }[];
}) {
    const form = useForm({ guardian_user_id: '' });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/t/${tenant}/sis/guardians/${id}/identity`, { preserveScroll: true });
            }}
            className="border-border mt-3 space-y-2 border-t pt-3"
        >
            <label className="text-text-primary block text-xs font-medium">
                Link a verified account matching this guardian email
                <select
                    className={`${inputClass} mt-1`}
                    value={form.data.guardian_user_id}
                    onChange={(event) => form.setData('guardian_user_id', event.target.value)}
                    required
                >
                    <option value="">Select account</option>
                    {candidates.map((candidate) => (
                        <option key={candidate.id} value={candidate.id}>
                            {candidate.name} · {candidate.email}
                        </option>
                    ))}
                </select>
            </label>
            <button
                disabled={form.processing}
                className="min-h-10 rounded-lg bg-emerald-700 px-3 text-xs font-semibold text-white"
            >
                Link verified guardian account
            </button>
            {Object.values(form.errors).length > 0 && (
                <p role="alert" className="text-sm text-rose-700">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
        </form>
    );
}

function VerifyGuardian({
    tenant,
    id,
    candidates,
}: {
    tenant: string;
    id: number;
    candidates: { id: number; name: string | null; email: string | null }[];
}) {
    const form = useForm({ verification_method: 'document_review', guardian_user_id: '' });
    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/t/${tenant}/sis/guardians/${id}/verify`, { preserveScroll: true });
            }}
            className="border-border mt-3 space-y-2 border-t pt-3"
        >
            <label className="text-text-primary block text-xs font-medium">
                Verification method
                <select
                    className={`${inputClass} mt-1`}
                    value={form.data.verification_method}
                    onChange={(event) => form.setData('verification_method', event.target.value)}
                >
                    <option value="document_review">Document review</option>
                    <option value="in_person">In person</option>
                    <option value="institution_record">Institution record</option>
                </select>
            </label>
            {candidates.length > 0 && (
                <label className="text-text-primary block text-xs font-medium">
                    Link a verified institution account matching the guardian email
                    <select
                        className={`${inputClass} mt-1`}
                        value={form.data.guardian_user_id}
                        onChange={(event) => form.setData('guardian_user_id', event.target.value)}
                    >
                        <option value="">Do not link an account</option>
                        {candidates.map((candidate) => (
                            <option key={candidate.id} value={candidate.id}>
                                {candidate.name} · {candidate.email}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            <button
                disabled={form.processing}
                className="min-h-10 w-full rounded-lg bg-emerald-700 px-3 text-xs font-semibold text-white"
            >
                Verify relationship
            </button>
            {Object.values(form.errors).length > 0 && (
                <p role="alert" className="text-sm text-rose-700">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
        </form>
    );
}

function RevokeGuardian({ tenant, id }: { tenant: string; id: number }) {
    const form = useForm({ reason: '' });
    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/t/${tenant}/sis/guardians/${id}/revoke`, { preserveScroll: true });
            }}
            className="border-border mt-3 flex gap-2 border-t pt-3"
        >
            <label className="sr-only" htmlFor={`guardian-reason-${id}`}>
                Revocation reason
            </label>
            <input
                id={`guardian-reason-${id}`}
                className={inputClass}
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
                placeholder="Reason for revocation"
                required
            />
            <button
                disabled={form.processing}
                className="min-h-11 shrink-0 rounded-lg bg-rose-700 px-3 text-xs font-semibold text-white"
            >
                Revoke access
            </button>
        </form>
    );
}

function TransferCaseCard({ tenant, transfer }: { tenant: string; transfer: TransferRecord }) {
    const reviewForm = useForm({ decision: 'approved', review_note: '' });
    const completeForm = useForm({ completion_note: '' });

    return (
        <li className="border-border rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 className="text-text-primary text-sm font-semibold">
                        {transfer.direction === 'incoming' ? 'Incoming from' : 'Outgoing to'}{' '}
                        {transfer.externalInstitutionName}
                    </h3>
                    <p className="text-text-muted mt-1 text-xs">
                        {transfer.externalInstitutionCode ? `${transfer.externalInstitutionCode} · ` : ''}
                        {transfer.externalCountryCode ?? 'Country not specified'} · Effective{' '}
                        {transfer.effectiveOn}
                    </p>
                </div>
                <span className="bg-canvas text-text-secondary rounded-full px-2.5 py-1 text-xs font-medium capitalize">
                    {transfer.status.replaceAll('_', ' ')}
                </span>
            </div>
            <p className="text-text-secondary mt-3 text-sm">{transfer.reason}</p>
            {transfer.reviewNote && (
                <p className="text-text-muted mt-2 text-sm">Review: {transfer.reviewNote}</p>
            )}
            {transfer.completionNote && (
                <p className="text-text-muted mt-1 text-sm">Completion: {transfer.completionNote}</p>
            )}
            {transfer.canReview && transfer.status === 'pending_review' && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        reviewForm.post(`/t/${tenant}/sis/transfers/${transfer.id}/review`, {
                            preserveScroll: true,
                        });
                    }}
                    className="border-border mt-4 grid gap-3 border-t pt-4 sm:grid-cols-[12rem_minmax(0,1fr)_auto]"
                >
                    <label className="text-text-primary block text-xs font-medium">
                        Independent decision
                        <select
                            className={`${inputClass} mt-1`}
                            value={reviewForm.data.decision}
                            onChange={(event) => reviewForm.setData('decision', event.target.value)}
                        >
                            <option value="approved">Approve</option>
                            <option value="rejected">Reject</option>
                        </select>
                    </label>
                    <label className="text-text-primary block text-xs font-medium">
                        Review note
                        <textarea
                            className={`${inputClass} mt-1 min-h-11 py-2`}
                            value={reviewForm.data.review_note}
                            onChange={(event) => reviewForm.setData('review_note', event.target.value)}
                            minLength={10}
                            maxLength={500}
                            required
                        />
                    </label>
                    <button
                        disabled={reviewForm.processing}
                        className="min-h-11 self-end rounded-lg bg-emerald-800 px-4 text-xs font-semibold text-white disabled:opacity-60"
                    >
                        Record decision
                    </button>
                    {Object.values(reviewForm.errors).length > 0 && (
                        <p role="alert" className="text-sm text-rose-700 sm:col-span-3">
                            {Object.values(reviewForm.errors).join(' ')}
                        </p>
                    )}
                </form>
            )}
            {transfer.canComplete && transfer.status === 'approved' && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        completeForm.post(`/t/${tenant}/sis/transfers/${transfer.id}/complete`, {
                            preserveScroll: true,
                        });
                    }}
                    className="border-border mt-4 space-y-3 border-t pt-4"
                >
                    <label className="text-text-primary block text-xs font-medium">
                        Completion record
                        <textarea
                            className={`${inputClass} mt-1 min-h-11 py-2`}
                            value={completeForm.data.completion_note}
                            onChange={(event) => completeForm.setData('completion_note', event.target.value)}
                            minLength={10}
                            maxLength={500}
                            required
                        />
                    </label>
                    <button
                        disabled={completeForm.processing}
                        className="bg-navy-900 min-h-11 rounded-lg px-4 text-xs font-semibold text-white disabled:opacity-60"
                    >
                        Record transfer completion
                    </button>
                    {Object.values(completeForm.errors).length > 0 && (
                        <p role="alert" className="text-sm text-rose-700">
                            {Object.values(completeForm.errors).join(' ')}
                        </p>
                    )}
                </form>
            )}
        </li>
    );
}

function CustomValueEditor({
    tenant,
    studentId,
    field,
    currentValue,
}: {
    tenant: string;
    studentId: number;
    field: Props['student']['customFieldDefinitions'][number];
    currentValue: unknown;
}) {
    const initialValue: string | boolean | string[] =
        typeof currentValue === 'boolean'
            ? currentValue
            : Array.isArray(currentValue)
              ? currentValue.map(String)
              : currentValue == null
                ? ''
                : String(currentValue);
    const form = useForm<{ value: string | boolean | string[] }>({ value: initialValue });
    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.put(`/t/${tenant}/sis/students/${studentId}/custom-fields/${field.id}`, {
                    preserveScroll: true,
                });
            }}
            className="border-border rounded-xl border p-3"
        >
            <label className="text-text-primary block text-sm font-medium">
                {field.label}
                {field.is_required ? ' *' : ''}
                {field.is_sensitive && <span className="text-text-muted ml-1 text-xs">encrypted</span>}
                {field.field_type === 'boolean' ? (
                    <span className="mt-2 flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={form.data.value === true}
                            onChange={(event) => form.setData('value', event.target.checked)}
                        />
                        Enabled
                    </span>
                ) : field.field_type === 'select' ? (
                    <select
                        className={`${inputClass} mt-1`}
                        value={String(form.data.value ?? '')}
                        onChange={(event) => form.setData('value', event.target.value)}
                    >
                        <option value="">Choose an option</option>
                        {(field.options ?? []).map((option) => (
                            <option key={option} value={option}>
                                {option}
                            </option>
                        ))}
                    </select>
                ) : field.field_type === 'multiselect' ? (
                    <span className="mt-2 grid gap-2 sm:grid-cols-2">
                        {(field.options ?? []).map((option) => (
                            <span key={option} className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={
                                        Array.isArray(form.data.value) && form.data.value.includes(option)
                                    }
                                    onChange={(event) => {
                                        const existing = Array.isArray(form.data.value)
                                            ? form.data.value
                                            : [];
                                        form.setData(
                                            'value',
                                            event.target.checked
                                                ? [...existing, option]
                                                : existing.filter((item) => item !== option),
                                        );
                                    }}
                                />
                                {option}
                            </span>
                        ))}
                    </span>
                ) : (
                    <input
                        type={
                            field.field_type === 'date' || field.field_type === 'number'
                                ? field.field_type
                                : 'text'
                        }
                        className={`${inputClass} mt-1`}
                        value={typeof form.data.value === 'string' ? form.data.value : ''}
                        onChange={(event) => form.setData('value', event.target.value)}
                        required={field.is_required}
                    />
                )}
            </label>
            <button className="text-brand-link mt-2 min-h-9 text-xs font-semibold underline">
                Save attribute
            </button>
            {Object.values(form.errors).length > 0 && (
                <p role="alert" className="text-xs text-rose-700">
                    {Object.values(form.errors).join(' ')}
                </p>
            )}
        </form>
    );
}
