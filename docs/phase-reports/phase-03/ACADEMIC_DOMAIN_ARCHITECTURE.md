# Phase 03 Academic Domain Architecture and Dependency Review

**Status:** Approved implementation design, recorded before Phase 03 model/migration work.
**Scope authority:** The user-provided Phase 03 brief and the approved Phase 02 handoff.
**Source gap:** No separate product blueprint or approved roadmap file exists in the current repository. Numbering beyond the supplied Phase 03 prompt is therefore treated as a dependency contract supplied by that prompt, not inferred from repository documentation.

## Existing infrastructure dependencies

Phase 03 builds on the verified Phase 02 checkpoint (`9060950`): Laravel 13 modular monolith, Inertia/React/TypeScript, global user identities, one institution profile per tenant, campuses, active tenant memberships, platform/tenant permission separation, signed invitations, request-scoped `TenantContext`, fail-closed `ScopedToTenant`, `AuditRecorder`, six design-token themes, private storage, and the guarded MySQL test runner.

Academic authorization must be granted through an active tenant membership and Phase 02 tenant role. The initial tenant creator receives no tenant membership or academic capability. Platform roles do not imply institutional access. Campus-limited roles must be checked against every campus-scoped write and read.

## Domain boundaries

Phase 03 owns configurable academic structure and governance metadata only:

- Institution academic operating model and semantic terminology.
- Academic years and teaching periods.
- Campus-aware organizational units, levels, and qualification definitions.
- Program definitions, school grade/class structures, sections, and academy cohorts.
- Subject and course-definition catalog with effective-dated versions.
- Effective-dated credit-hour policies.
- Versioned, opt-in jurisdiction/framework profiles with explicit evidence and gap state.
- Learning outcome references sufficient for later mapping/import work.
- Quality objectives, scheduled reviews, corrective-action tracking, controlled document references, and approval history.

Phase 03 does not own applicants, students, guardians as academic relationships, enrollment, curriculum maps/prerequisites, teaching delivery, timetables, attendance, assessments, grades, GPA/CGPA, transcripts, tuition/fees, or public interoperability APIs. The supplied brief assigns those to later phases.

## Operating-model design

One typed institution-level academic configuration selects an operating model (`k12`, `higher_education`, `academy`, `corporate_learning`, or `custom`) and locale/timezone defaults. It records enabled feature flags and a terminology map. Server middleware gates capability routes; structure/catalog sections, credit controls, calendar content, and navigation follow the configuration. The academic overview, settings page, and audit history remain accessible to authorized academic viewers so configuration can be reviewed and re-enabled. Core page headings use configured terms, while fine-grained label substitution remains partial. No separate applications or databases are created for each operating model.

Academic units form an optional, tenant-scoped parent/child tree. K–12 institutions can configure levels and grades without creating faculties. Programs may be linked to units, qualifications, levels, and campuses. Courses are catalog definitions; they are not enrollments or scheduled offerings. `academic_course_versions` preserve approved definitions and credit values. Credit-policy versions are explicit references, so a policy update cannot rewrite a prior course version.

Academic years and periods use institution-local date semantics. Stored dates are calendar dates, not UTC instants. Campus-specific calendars are permitted by nullable campus scope; a global institution calendar can coexist where the selected academic model permits it. Effective-dated policy records must be non-overlapping within the same scope and version family.

International profiles are records of institution-selected configuration and evidence, not representations that TechMentors has interpreted or certified jurisdictional law. A profile is marked `verified` only when an authorized reviewer attaches a dated authoritative source and records review metadata; otherwise it remains `draft` or `needs_review`. Quality objective indicators use safe typed decimal targets and due/review dates. Evidence attachments use private tenant-scoped storage and validated file types.

## Persistence and tenant ownership

Every tenant-owned academic record carries `tenant_id`; institution-owned records also carry `institution_id`. Campus scope is nullable only where an institution-wide policy is valid. Tenant-aware composite foreign keys protect institution, campus, parent unit, program, and course relationships. Scoped unique keys use `(tenant_id, institution_id, code)` or the narrower ownership scope. Code validation accepts ASCII letters/digits/dot/dash/underscore and preserves input case. MySQL’s configured collation and SQLite’s default comparison can differ for case-only duplicates; no cross-engine case-normalization guarantee is claimed yet.

Versioned records are append-only after publication where historical consumers may reference them. Draft versions may be edited; published versions are superseded by a new version. Lifecycle state changes and approvals run through domain actions, database transactions, and Phase 02 audit events. No hard-delete interface is provided for records with published or historical references.

## Proposed bounded code organization

- Eloquent records remain in `app/Models` following the current project convention.
- Domain operations and typed policy resolvers live under `app/Domain/Academic`, divided into calendar, structure, catalog, policy, standards, and quality namespaces as real use cases are added.
- Tenant controllers live under `app/Http/Controllers/Workspace`; mutating academic endpoints use Laravel validation and focused domain operations where lifecycle/authorization logic benefits from them.
- Tenant routes use `tenant.workspace` plus explicit `tenant.permission:*` middleware and resolve the tenant from `TenantContext` rather than trusting request IDs.
- React/Inertia pages live under `resources/js/pages/workspace/academics`; shared controls, tables, status states, and empty states reuse the existing workspace design system and six themes.
- Tests live in guarded Laravel feature tests and actual Playwright workflows.

## Permission design

New permission keys use an `academic.*` namespace and are registered in the permission catalog. The owner, tenant admin, and academic admin system roles receive the current academic permissions; the auditor gets view/audit only. Other staff roles receive no permission by implication. Campus-scoped members use a fail-closed route allowlist and record-level checks. A tenant administrator cannot access another tenant's records by changing IDs or campus headers.

## Standards and evidence sources

The standards matrix uses public official summaries and version metadata only. It does not reproduce paid standards text or imply organizational certification:

- [ISO 21001:2025 official catalogue entry](https://www.iso.org/standard/21001?browse=ics), edition 2, published July 2025. This describes an educational-organization management-system standard; the application implements support records only.
- [ISO/IEC 27001:2022 official catalogue entry](https://www.iso.org/standard/27001), edition 3, published October 2022. This remains an organizational ISMS standard; this product feature is not an ISMS or certification.
- [W3C WCAG 2.2 Recommendation](https://www.w3.org/TR/WCAG22/), dated 12 December 2024. WCAG criteria are used as a UI review and test reference; no blanket conformance claim is made.
- [1EdTech CASE 1.1 specification](https://www.imsglobal.org/spec/case/v1p1/). The outcome model stores framework references and associations for future mapping; no CASE import/export service or certification is implemented in Phase 03.
- [OWASP ASVS project](https://asvs.owasp.org/), version 5.0.0, used as an application verification checklist for authorization, input handling, files, audit, and tenant isolation; not a certification.

## Dependency contracts with later phases

| Later capability | Phase 03 contract |
|---|---|
| Admissions | A program and effective academic year/period can be referenced, but applicant workflow and admission decisions remain owned by admissions. |
| SIS | No student or guardian tables are created. SIS must relate students to tenant/institution and later enrollment without treating authentication membership as enrollment. |
| Curriculum | Programs, course definitions, and outcome references are stable identifiers; curriculum sequencing, prerequisites, and outcome mapping remain outside this phase. |
| Learning delivery | A course definition/version may be referenced, but lessons, sections-as-teaching-groups, instructor delivery, and learning activity are not implemented. |
| Enrollment/scheduling/attendance | Period dates and campus scope are available; registration, capacity consumption, timetables, and attendance remain later-owned. Structural capacity never means current enrollment. |
| Assessment/results/transcripts | Course versions and academic policy versions are referenceable; grading rules, outcomes, approval/release, GPA, and transcripts remain later-owned. |
| Finance | Credit policy may describe hours/credits but does not set prices, tuition, vouchers, invoices, or account balances. |
| Entitlements/white-label/interoperability | Academic feature toggles and typed structures are local configuration only; tenant billing, cross-system APIs, and CASE protocol endpoints remain deferred. |

## Consequences and known trade-offs

The design favors one normalized shared-schema model across institution types and allows opt-in configuration instead of separate school/university schemas. It creates more configuration records and validation rules than a single-school model, but avoids forcing faculties, January-based years, country-specific grades, or a universal credit-hour definition. Course version rows and policy approval metadata add implementation overhead but protect historical meaning. Regional rule coverage must remain evidence-based and incomplete until reviewed by jurisdictional experts.
