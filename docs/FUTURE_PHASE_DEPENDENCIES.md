# Future phase dependencies

Phase 01 ends at a verified foundation. These items are deferred and must be authorized and designed in their own phase. Phase numbers beyond the explicitly stated Phase 05 appearance milestone are recommendations only; confirm them against the future phase briefs.

| Recommended order | Deferred work | Dependency / gate |
| --- | --- | --- |
| Phase 02 (recommended) | Identity, authentication, MFA, authorization, audit identity | Define account lifecycle, role boundaries, recovery, lockout, and policy test matrix before protected product pages. |
| Phase 03 (recommended) | Tenancy, institution/campus setup, tenant context | Establish isolation strategy and cross-tenant adversarial tests before any institution-owned data. |
| Phase 04+ (to be assigned) | Admissions, students, guardians, academic engine, learning, assessment/results | Requires identity, tenant context, privacy classification, retention, and institution/academic policy. |
| Phase 05 (specified) | Appearance controls, CMS foundation, six themes | Build on semantic design tokens; sanitize institution-authored content and scope branding by tenant. |
| Later authorized phase | Progress intelligence, finance, HR/operations, communication, integrations | Requires tenant isolation, authorization, audit events, operational monitoring, and domain-specific controls first. |
| Cross-cutting throughout | Security, quality, reporting, backups, observability, interoperability | Extend ASVS verification and threat model with each data-bearing feature; no single final security phase can replace this. |

No Phase 02 work is started by this report.
