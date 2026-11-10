import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Evidence = {
    id: number;
    source_title: string;
    source_authority: string;
    source_url: string | null;
    source_published_on: string | null;
    retrieved_on: string;
    private_file_path: string | null;
};
type Mapping = {
    id: number;
    requirement_reference: string | null;
    policy_area: string;
    requirement_summary: string;
    software_capability: string | null;
    mapping_status: string;
    evidence_id: number | null;
};
type Gap = {
    id: number;
    gap_key: string;
    severity: string;
    description: string;
    status: string;
    due_on: string | null;
};
type Version = {
    id: number;
    version_number: number;
    academic_framework: string | null;
    authority_name: string | null;
    authority_reference: string | null;
    review_status: string;
    effective_from: string | null;
    effective_until: string | null;
    evidence_count: number;
    mappings_count: number;
    gaps_count: number;
    evidence: Evidence[];
    mappings: Mapping[];
    gaps: Gap[];
};
type Profile = {
    id: number;
    code: string;
    name: string;
    country: string | null;
    region: string | null;
    institution_type: string | null;
    status: string;
    versions: Version[];
};
type Props = {
    tenant: { slug: string };
    profiles: { data: Profile[]; links: { url: string | null; label: string; active: boolean }[] };
    activeVersionId: number | null;
};

function PolicyVersion({
    version,
    slug,
    activeVersionId,
}: {
    version: Version;
    slug: string;
    activeVersionId: number | null;
}) {
    const root = `/t/${slug}/academics`;
    const evidenceForm = useForm({
        source_title: '',
        source_authority: '',
        source_url: '',
        source_published_on: '',
        retrieved_on: new Date().toISOString().slice(0, 10),
        mapped_claim: '',
        file: null as File | null,
    });
    const mappingForm = useForm({
        evidence_id: '',
        requirement_reference: '',
        policy_area: '',
        requirement_summary: '',
        software_capability: '',
        mapping_status: 'unmapped',
        gap_note: '',
    });
    const gapForm = useForm({
        gap_key: '',
        severity: 'medium',
        description: '',
        owner_user_id: '',
        due_on: '',
    });
    return (
        <details className="border-border mt-4 border-t pt-4">
            <summary className="focus-ring cursor-pointer rounded text-sm font-semibold">
                Version {version.version_number} · {version.review_status}
                {activeVersionId === version.id ? ' · Selected for academic configuration' : ''}
            </summary>
            <p className="text-text-secondary mt-3 text-sm">
                {version.academic_framework ?? 'Framework not specified'} · Authority:{' '}
                {version.authority_name ?? 'Not specified'}
                {version.authority_reference ? ` · ${version.authority_reference}` : ''}
            </p>
            <div className="mt-3 grid gap-3 sm:grid-cols-3">
                <div className="bg-canvas rounded-lg p-3 text-sm">
                    {version.evidence_count} evidence records
                </div>
                <div className="bg-canvas rounded-lg p-3 text-sm">
                    {version.mappings_count} requirement mappings
                </div>
                <div className="bg-canvas rounded-lg p-3 text-sm">{version.gaps_count} tracked gaps</div>
            </div>
            <div className="mt-4 grid gap-4 xl:grid-cols-2">
                <section className="border-border rounded-xl border p-4">
                    <h3 className="font-semibold">Authoritative evidence</h3>
                    <p className="text-text-secondary mt-1 text-xs">
                        Sources are retained privately. Recording evidence does not determine legal
                        applicability.
                    </p>
                    <form
                        className="mt-3 grid gap-3 sm:grid-cols-2"
                        onSubmit={(event: FormEvent) => {
                            event.preventDefault();
                            evidenceForm.post(`${root}/policy-versions/${version.id}/evidence`, {
                                forceFormData: true,
                                onSuccess: () =>
                                    evidenceForm.reset(
                                        'source_title',
                                        'source_authority',
                                        'source_url',
                                        'source_published_on',
                                        'mapped_claim',
                                        'file',
                                    ),
                            });
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            Source title
                            <input
                                required
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                value={evidenceForm.data.source_title}
                                onChange={(e) => evidenceForm.setData('source_title', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Issuing authority
                            <input
                                required
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                value={evidenceForm.data.source_authority}
                                onChange={(e) =>
                                    evidenceForm.setData('source_authority', e.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm sm:col-span-2">
                            Secure source URL (HTTPS)
                            <input
                                type="url"
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                value={evidenceForm.data.source_url}
                                onChange={(e) => evidenceForm.setData('source_url', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Published date
                            <input
                                type="date"
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                value={evidenceForm.data.source_published_on}
                                onChange={(e) =>
                                    evidenceForm.setData('source_published_on', e.currentTarget.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Retrieved date
                            <input
                                required
                                type="date"
                                className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                value={evidenceForm.data.retrieved_on}
                                onChange={(e) => evidenceForm.setData('retrieved_on', e.currentTarget.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm sm:col-span-2">
                            Evidence file
                            <input
                                type="file"
                                accept=".pdf,.docx,.xlsx,.png,.jpg,.jpeg"
                                className="border-border bg-surface min-h-10 rounded-lg border px-3 py-2"
                                onChange={(e) =>
                                    evidenceForm.setData('file', e.currentTarget.files?.[0] ?? null)
                                }
                            />
                            <span className="text-text-muted text-xs">
                                PDF, DOCX, XLSX, PNG, or JPEG · maximum 20 MB
                            </span>
                        </label>
                        <label className="grid gap-1 text-sm sm:col-span-2">
                            Relevant claim
                            <textarea
                                className="border-border bg-surface min-h-16 rounded-lg border px-3 py-2"
                                value={evidenceForm.data.mapped_claim}
                                onChange={(e) => evidenceForm.setData('mapped_claim', e.currentTarget.value)}
                            />
                        </label>
                        <div className="sm:col-span-2">
                            <button
                                disabled={evidenceForm.processing}
                                className="bg-brand-action focus-ring min-h-10 rounded-lg px-4 text-sm font-semibold text-white"
                            >
                                Record evidence
                            </button>
                        </div>
                        {Object.keys(evidenceForm.errors).length > 0 && (
                            <div className="sm:col-span-2">
                                <FormErrors />
                            </div>
                        )}
                    </form>
                    <ul className="mt-4 grid gap-2">
                        {version.evidence.map((item) => (
                            <li key={item.id} className="bg-canvas rounded-lg p-3 text-sm">
                                <strong>{item.source_title}</strong>
                                <p className="text-text-secondary mt-1">
                                    {item.source_authority} · retrieved {item.retrieved_on}
                                </p>
                                {item.private_file_path && (
                                    <a
                                        className="text-brand-link mt-2 inline-block font-semibold"
                                        href={`${root}/policy-evidence/${item.id}/download`}
                                    >
                                        Download private evidence
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
                <section className="border-border rounded-xl border p-4">
                    <h3 className="font-semibold">Requirement mapping</h3>
                    <form
                        className="mt-3 grid gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            mappingForm.post(`${root}/policy-versions/${version.id}/mappings`, {
                                onSuccess: () => mappingForm.reset(),
                            });
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            Requirement summary
                            <textarea
                                required
                                className="border-border bg-surface min-h-16 rounded-lg border px-3 py-2"
                                value={mappingForm.data.requirement_summary}
                                onChange={(e) =>
                                    mappingForm.setData('requirement_summary', e.currentTarget.value)
                                }
                            />
                        </label>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="grid gap-1 text-sm">
                                Policy area
                                <input
                                    required
                                    className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                    value={mappingForm.data.policy_area}
                                    onChange={(e) =>
                                        mappingForm.setData('policy_area', e.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Reference
                                <input
                                    className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                    value={mappingForm.data.requirement_reference}
                                    onChange={(e) =>
                                        mappingForm.setData('requirement_reference', e.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Capability
                                <input
                                    className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                    value={mappingForm.data.software_capability}
                                    onChange={(e) =>
                                        mappingForm.setData('software_capability', e.currentTarget.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Mapping status
                                <select
                                    className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                    value={mappingForm.data.mapping_status}
                                    onChange={(e) =>
                                        mappingForm.setData('mapping_status', e.currentTarget.value)
                                    }
                                >
                                    {['unmapped', 'partial', 'mapped', 'gap'].map((status) => (
                                        <option key={status}>{status}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm sm:col-span-2">
                                Supporting evidence
                                <select
                                    className="border-border bg-surface min-h-10 rounded-lg border px-3"
                                    value={mappingForm.data.evidence_id}
                                    onChange={(e) =>
                                        mappingForm.setData('evidence_id', e.currentTarget.value)
                                    }
                                >
                                    <option value="">Not linked</option>
                                    {version.evidence.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.source_title}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                        <button
                            disabled={mappingForm.processing}
                            className="bg-brand-action focus-ring min-h-10 justify-self-start rounded-lg px-4 text-sm font-semibold text-white"
                        >
                            Add mapping
                        </button>
                        {Object.keys(mappingForm.errors).length > 0 && <FormErrors />}
                    </form>
                    <ul className="mt-4 grid gap-2">
                        {version.mappings.map((mapping) => (
                            <li key={mapping.id} className="bg-canvas rounded-lg p-3 text-sm">
                                <strong>{mapping.policy_area}</strong> ·{' '}
                                <span className="capitalize">{mapping.mapping_status}</span>
                                <p className="text-text-secondary mt-1">{mapping.requirement_summary}</p>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
            <section className="border-border mt-4 rounded-xl border p-4">
                <h3 className="font-semibold">Identified gaps</h3>
                <form
                    className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        gapForm.post(`${root}/policy-versions/${version.id}/gaps`, {
                            onSuccess: () => gapForm.reset(),
                        });
                    }}
                >
                    <label className="grid gap-1 text-sm">
                        Gap key
                        <input
                            required
                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                            value={gapForm.data.gap_key}
                            onChange={(e) => gapForm.setData('gap_key', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Severity
                        <select
                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                            value={gapForm.data.severity}
                            onChange={(e) => gapForm.setData('severity', e.currentTarget.value)}
                        >
                            {['informational', 'low', 'medium', 'high', 'critical'].map((item) => (
                                <option key={item}>{item}</option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">
                        Due date
                        <input
                            type="date"
                            className="border-border bg-surface min-h-10 rounded-lg border px-3"
                            value={gapForm.data.due_on}
                            onChange={(e) => gapForm.setData('due_on', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm sm:col-span-2 xl:col-span-4">
                        Description
                        <textarea
                            required
                            className="border-border bg-surface min-h-16 rounded-lg border px-3 py-2"
                            value={gapForm.data.description}
                            onChange={(e) => gapForm.setData('description', e.currentTarget.value)}
                        />
                    </label>
                    <button className="bg-brand-action focus-ring min-h-10 justify-self-start rounded-lg px-4 text-sm font-semibold text-white">
                        Record gap
                    </button>
                    {Object.keys(gapForm.errors).length > 0 && (
                        <div className="sm:col-span-2 xl:col-span-4">
                            <FormErrors />
                        </div>
                    )}
                </form>
                <ul className="mt-4 grid gap-2 sm:grid-cols-2">
                    {version.gaps.map((gap) => (
                        <li key={gap.id} className="bg-canvas rounded-lg p-3 text-sm">
                            <strong>{gap.gap_key}</strong> ·{' '}
                            <span className="capitalize">{gap.severity}</span> · {gap.status}
                            <p className="text-text-secondary mt-1">{gap.description}</p>
                            {gap.status === 'open' && (
                                <form
                                    className="mt-2 flex flex-wrap gap-2"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        const form = new FormData(event.currentTarget);
                                        router.post(`${root}/policy-gaps/${gap.id}/resolve`, {
                                            resolution_note: String(form.get('resolution_note') ?? ''),
                                        });
                                    }}
                                >
                                    <label className="sr-only" htmlFor={`resolve-${gap.id}`}>
                                        Resolution note
                                    </label>
                                    <input
                                        id={`resolve-${gap.id}`}
                                        name="resolution_note"
                                        required
                                        className="border-border bg-surface min-h-9 min-w-48 flex-1 rounded-lg border px-3"
                                        placeholder="Resolution evidence"
                                    />
                                    <button className="border-border min-h-9 rounded-lg border px-3">
                                        Resolve
                                    </button>
                                </form>
                            )}
                        </li>
                    ))}
                </ul>
            </section>
            {version.review_status === 'draft' && (
                <button
                    type="button"
                    onClick={() => router.post(`${root}/policy-versions/${version.id}/verify`)}
                    className="border-border mt-4 min-h-10 rounded-lg border px-4 text-sm font-semibold"
                >
                    Review dated evidence and select version
                </button>
            )}
            <p className="text-text-muted mt-3 text-xs">
                A reviewed profile is a local evidence and configuration aid. It does not establish
                jurisdictional compliance, accreditation, or authority approval.
            </p>
        </details>
    );
}

export default function AcademicPoliciesPage({ tenant, profiles, activeVersionId }: Props) {
    const root = `/t/${tenant.slug}/academics`;
    const profileForm = useForm({
        code: '',
        name: '',
        country: '',
        region: '',
        institution_type: '',
        academic_framework: '',
        authority_name: '',
        authority_reference: '',
        calendar_model: '',
        grading_terminology: '',
        qualification_terminology: '',
        credit_hours_applicable: false,
        effective_from: '',
        effective_until: '',
    });
    return (
        <WorkspaceLayout area="tenant" title="Policy profiles">
            <PageHeader
                title="Jurisdiction policy profiles"
                description="Record opt-in jurisdiction references, source evidence, requirement mappings, and known gaps for institution review."
            />
            <div className="mt-5 flex gap-3 text-sm">
                <a href={root} className="text-brand-link font-semibold">
                    Overview
                </a>
                <a href={`${root}/catalog`} className="text-brand-link font-semibold">
                    Subjects & courses
                </a>
            </div>
            <section className="border-border bg-surface mt-6 rounded-2xl border p-5 sm:p-7">
                <h2 className="text-lg font-semibold">Create a policy profile</h2>
                <p className="text-text-secondary mt-1 text-sm">
                    A profile is institution-configured and versioned. It does not automatically assert
                    regulatory requirements.
                </p>
                <form
                    className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        profileForm.post(`${root}/policies`, { onSuccess: () => profileForm.reset() });
                    }}
                >
                    <label className="grid gap-1 text-sm">
                        Profile name
                        <input
                            required
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={profileForm.data.name}
                            onChange={(e) => profileForm.setData('name', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Code
                        <input
                            required
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={profileForm.data.code}
                            onChange={(e) => profileForm.setData('code', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Country (ISO alpha-2)
                        <input
                            maxLength={2}
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={profileForm.data.country}
                            onChange={(e) =>
                                profileForm.setData('country', e.currentTarget.value.toUpperCase())
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Region/state
                        <input
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={profileForm.data.region}
                            onChange={(e) => profileForm.setData('region', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Institution type
                        <select
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={profileForm.data.institution_type}
                            onChange={(e) => profileForm.setData('institution_type', e.currentTarget.value)}
                        >
                            <option value="">Any</option>
                            {[
                                'school',
                                'college',
                                'university',
                                'academy',
                                'corporate',
                                'online',
                                'other',
                            ].map((item) => (
                                <option key={item}>{item}</option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">
                        Education framework
                        <input
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={profileForm.data.academic_framework}
                            onChange={(e) => profileForm.setData('academic_framework', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Authority
                        <input
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={profileForm.data.authority_name}
                            onChange={(e) => profileForm.setData('authority_name', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Authority reference
                        <input
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={profileForm.data.authority_reference}
                            onChange={(e) =>
                                profileForm.setData('authority_reference', e.currentTarget.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Calendar model
                        <input
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={profileForm.data.calendar_model}
                            onChange={(e) => profileForm.setData('calendar_model', e.currentTarget.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Grading terminology
                        <input
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={profileForm.data.grading_terminology}
                            onChange={(e) =>
                                profileForm.setData('grading_terminology', e.currentTarget.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Qualification terminology
                        <input
                            className="border-border bg-surface min-h-11 rounded-lg border px-3"
                            value={profileForm.data.qualification_terminology}
                            onChange={(e) =>
                                profileForm.setData('qualification_terminology', e.currentTarget.value)
                            }
                        />
                    </label>
                    <label className="flex min-h-11 items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={profileForm.data.credit_hours_applicable}
                            onChange={(e) =>
                                profileForm.setData('credit_hours_applicable', e.currentTarget.checked)
                            }
                        />
                        Credit hours applicable
                    </label>
                    <div className="sm:col-span-2 xl:col-span-4">
                        <button
                            disabled={profileForm.processing}
                            className="bg-brand-action focus-ring min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                        >
                            Create draft profile
                        </button>
                    </div>
                    {Object.keys(profileForm.errors).length > 0 && (
                        <div className="sm:col-span-2 xl:col-span-4">
                            <FormErrors />
                        </div>
                    )}
                </form>
            </section>
            <section className="mt-7 grid gap-4">
                {profiles.data.map((profile) => (
                    <article
                        key={profile.id}
                        className="border-border bg-surface rounded-2xl border p-5 sm:p-7"
                    >
                        <h2 className="text-lg font-semibold">
                            {profile.name}
                            <span className="text-text-muted ml-2 text-sm">{profile.code}</span>
                        </h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            {[profile.country, profile.region, profile.institution_type, profile.status]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                        {profile.versions.map((version) => (
                            <PolicyVersion
                                key={version.id}
                                version={version}
                                slug={tenant.slug}
                                activeVersionId={activeVersionId}
                            />
                        ))}
                        <button
                            type="button"
                            className="border-border mt-4 rounded-lg border px-4 py-2 text-sm font-semibold"
                            onClick={() => {
                                const framework = window.prompt('Academic framework (optional)');
                                const authority = window.prompt('Issuing authority (optional)');
                                router.post(`${root}/policies/${profile.id}/versions`, {
                                    academic_framework: framework || '',
                                    authority_name: authority || '',
                                    credit_hours_applicable: false,
                                });
                            }}
                        >
                            Create new policy version
                        </button>
                    </article>
                ))}
                {profiles.data.length === 0 && (
                    <p className="border-border bg-surface text-text-secondary rounded-2xl border p-6">
                        No jurisdiction policy profile has been added. This is optional and
                        institution-specific.
                    </p>
                )}
            </section>
        </WorkspaceLayout>
    );
}
