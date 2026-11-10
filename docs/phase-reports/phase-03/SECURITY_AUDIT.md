# Phase 03 Security Audit

**Review basis:** Local code review, guarded MySQL/SQLite feature suites, Playwright Chromium, dependency audits, PHPStan, and OWASP ASVS 5.0.0 as a verification reference. This is not certification.

## Findings

| Severity | Finding | Evidence/disposition |
|---|---|---|
| Critical | None identified in the reviewed Phase 03 scope | No unresolved critical finding in local review/tests |
| High | None identified in the reviewed Phase 03 scope | Tenant/campus adversarial tests and RBAC routes pass |
| Medium | Campus-scoped user access must remain fail-closed as academic routes expand | Middleware allowlist plus campus tests; future routes require explicit review |
| Medium | Jurisdiction profiles are institution-entered evidence, not authoritative law | UI/report boundary states no compliance inference; no preverified national pack |
| Low | Legacy academic backfill uses `corporate`/`hybrid` model labels not present in the current selector | Fixed through read-time normalization to `corporate_learning`/`custom`, regression test added; no existing rows rewritten |
| Informational | Local/hosted CI execution and production deployment controls are outside this workstation verification | Workflow reviewed; hosted run not performed |

## Controls reviewed

- Tenant-owned academic models inherit a tenant global scope and fail closed without tenant context. Writes acquire `tenant_id` from server context.
- Composite tenant-aware foreign keys and scoped unique keys protect institution/campus/academic references. Tests attempt cross-tenant IDs and campus assignments.
- Route middleware requires explicit `academic.*` permissions. Course/policy/quality approvals have a distinct permission. Academic audit listing is tenant-scoped and returns no audit metadata payload.
- `RequireAcademicFeature` fails closed for unmapped academic routes and gates implemented route families by the institution's normalized capability flags. Credit-bearing program/course writes and publication also validate the server-side credits flag; adversarial feature tests confirm disabled catalog/policy/quality routes reject access and do not persist writes.
- CSRF/session behavior is inherited from Laravel and Phase 02; regression tests cover session security. Inputs use Laravel validation and output uses React text rendering/Inertia encoding.
- Quality/document/policy evidence files use private storage; no public private-storage serving route is registered. No executable upload format is accepted by the academic forms.
- Error pages and health endpoints are covered for generic output; no credentials were printed into reports.
- `composer.bat audit` and `npm.cmd audit --audit-level=low` found no advisories at execution time.

## Residual security work

Conduct a deployment-specific threat review, review migration/runtime database privilege separation, configure real secrets and email delivery, define audit retention/immutability, review file scanning/retention/backup encryption, and execute hosted CI before release. Do not interpret this report as ASVS verification completion.
