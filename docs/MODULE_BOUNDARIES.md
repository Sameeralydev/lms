# Module boundaries

These are planned bounded contexts, not implemented modules. Phase 01 creates no empty module directories or fake services.

| Boundary | Owns | Important dependencies / rules |
| --- | --- | --- |
| Platform Control Plane | deployment registry, platform operators, global configuration | Separate platform operators from institution users; never infer tenant access from platform access. |
| Identity and Access | accounts, credentials, MFA, sessions, roles, permissions | Authentication precedes authorization; permissions are evaluated in tenant context. |
| Tenancy | tenant lifecycle, tenant context, routing, isolation policy | Must be established before tenant-owned records or background jobs are added. |
| Institution Management | institutions, campuses, academic year setup | Depends on tenancy and identity; tenant-owned keys must be constrained by tenant. |
| Students and Admissions | applicants, students, guardians, enrollment | Depends on identity, tenant, institution and privacy controls. |
| Academic Engine | programs, curricula, terms, credit rules | Depends on tenant/institution; owns academic policy and versioning. |
| Learning | courses, content, delivery, learning activity | Depends on academic context and authorization; private content needs scoped storage. |
| Assessments and Results | assessments, grading, approvals, transcripts | Depends on identity, tenant, academic policy, and immutable result history. |
| Progress Intelligence | progress summaries and activity views | Read models only; must not become the source of academic truth. |
| Finance | fees, invoices, payments, accounting records | Tenant-scoped, append-only financial events and reconciliation controls. |
| HR and Operations | workforce, payroll, library, hostel, transport, inventory | Separate privacy and authorization policies per subdomain. |
| Appearance and CMS | institution branding, content pages, theme selection | Bound public assets and content sanitization; six-theme engine is Phase 05. |
| Communication | announcements, email, SMS, notifications | Consent, delivery retries, tenant routing, and PII-minimized queue payloads. |
| Integrations | inbound/outbound connectors, webhooks, interoperability | Per-tenant credentials, signature checks, replay protection, and egress controls. |
| Security, Quality and Reporting | audit trail, controls evidence, security reporting | Append-only event model with restricted access and retention rules. |

## Directory convention when modules begin

Create `app/Modules/<ModuleName>/` only with the first real use case. Keep public contracts in a small `Contracts/` directory within the owning module; keep persistence and application workflows private to that module. Use Laravel standard migrations and test locations until scale requires module-local registration. Never use a shared `Common` folder as a dumping ground.
