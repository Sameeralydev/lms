# Phase 22 — Standards and Integrations

## Requirements source and implementation boundary

The workspace did not contain a Phase 22 acceptance matrix or provider list. The available approved standards mapping (`docs/phase-reports/phase-03/EDUCATION_STANDARDS_MAPPING.md`) names 1EdTech CASE 1.1 and explicitly records that CASE exchange endpoints are missing. The implementation therefore delivers the smallest concrete integration supported by that traceable requirement: a tenant-scoped, read-only CASE Service 1.1 provider for published competency frameworks. No LTI, OneRoster, SCORM, xAPI, QTI, SSO, messaging provider, payment provider, or webhook behavior is claimed or fabricated.

## Implemented scope

- Read-only tenant integration credentials; only a SHA-256 token hash is stored. The secret is revealed once, carries the `case.read` scope, can expire, and can be revoked.
- API routes under `/api/tenants/{tenant}/ims/case/v1p1` for `GET /CFDocuments`, `GET /CFDocuments/{sourcedId}`, `GET /CFItems/{sourcedId}`, and `GET /CFPackages/{sourcedId}`.
- CASE objects map only from published tenant-owned competency frameworks and active child competencies. Draft frameworks, other tenants, and unrelated academic or learner records are not returned.
- Stable tenant-scoped CASE UUID mappings are stored in `integration_external_identifiers`.
- API authentication binds the token to the route tenant, checks revocation/expiry/scope, sets an explicit tenant context, applies the Phase 21 `integrations` entitlement, and rate limits requests. Calls update last-use time and write a credential-attributed, non-secret audit event.
- CASE responses include no-store cache headers and CASE status payloads for authorization and unknown-object errors.
- Tenant integration administration page supports credential creation and revocation, displays only token prefix and metadata, and reveals a newly generated credential once.

## Standards scope

The implementation uses CASE 1.1's `/ims/case/v1p1` REST/JSON path and a read-only provider subset. It is not a full CASE implementation or 1EdTech conformance claim. Import/consumer workflows, associations, other CASE resource types, and formal certification are outside the evidence available in the repository and remain unimplemented pending an approved contract.

## Database and configuration

Migration `2026_11_17_000004_create_standards_integration_access.php` adds credential and external-identifier tables plus tenant integration permissions. The migration has not been applied: the active local `.env` has not been verified as an authorized isolated Developer B schema. It is additive and does not drop or rewrite prior tables. Tenants with configured plans require the `integrations` entitlement; plans without subscription assignments retain Phase 21 legacy behavior.

## Development verification

- PHP syntax checks: passed for all changed PHP files.
- TypeScript: `npm run typecheck` passed.
- Frontend: `npm run build` passed.
- Database migration, Laravel route registration, authenticated API requests, token lifecycle, CASE JSON schema validation, tenant adversarial verification, and official conformance checks were not run. See `docs/qa/DEFERRED_PRODUCT_QA_BACKLOG.md` (all QA PENDING).

**Development implementation added; QA PENDING.** The repository did not supply a detailed Phase 22 provider/standard acceptance list, so this report does not infer functionality beyond the CASE 1.1 gap recorded by the existing standards map.
