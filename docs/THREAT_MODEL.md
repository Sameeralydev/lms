# Threat model

## Scope and assumptions

The current attack surface is a public Laravel/Inertia shell, a liveness route, and a token-gated readiness route. There are no user records in use, tenant data, uploads, financial data, grade records, webhooks, or authentication endpoints. The eventual product is a multi-tenant platform for minors/students, guardians, staff, and institutions, including sensitive academic and financial records. The following risks remain relevant to design and phase gates even though their domain systems are not present yet.

| Threat | Current exposure / severity | Future controls | Intended phase |
| --- | --- | --- | --- |
| Unauthorized access | Low current data exposure; high future impact | MFA-capable identity, deny-by-default policies, session rotation, authorization tests for every resource/action | Identity and Access |
| Cross-tenant data leakage | Tenant records do not exist; critical future risk | Resolve tenant context from trusted routing/session, enforce policy and tenant-aware query/foreign-key constraints, adversarial cross-tenant tests, background-job scoping | Tenancy before institution/student data |
| Privilege escalation | No role system exists; high future impact | Role/permission model, separation of platform and tenant administrators, step-up authentication, approval for privileged actions, audit events | Identity and Access / Security |
| CSRF, XSS, injection | Public shell only; baseline middleware, CSP and safe output conventions exist | Keep CSRF on state changes, validation/encoding review, CSP report review, parameterized queries, SAST/DAST and security regression tests | Every feature phase |
| File upload exploitation | No upload endpoint exists | Allowlisted file types, content inspection, size limits, randomized object keys, quarantine, malware scanning, private storage, no execution permission, download authorization | Learning / Integrations as needed |
| Sensitive logging | No domain PII flow exists | Structured logging with field allowlists, redact tokens/passwords/PII, prohibit request-body logging, retention/access review | Security / observability foundation |
| Credential exposure | `.env` ignored and examples are blank | Secret manager, credential rotation, least privilege, secret scanning, no credentials in CI artifacts or logs | Deployment and Identity |
| Queue/cache/storage isolation | Local file/sync drivers; Redis service absent | Tenant-aware cache keys and queue context, dedicated prefixes/connections, private tenant storage paths, worker authorization and payload minimization | Tenancy / Operations |
| Insecure integrations | No integrations exist | Per-tenant secrets, signed webhooks, timestamp/replay checks, outbound allowlists, SSRF-safe URL validation, scoped OAuth and rotation | Integrations |
| Financial record tampering | No finance model exists; critical future integrity risk | Append-only ledger, idempotency, dual control, reconciliation, immutable audit events, protected correction workflow | Finance |
| Academic grade tampering | No grade model exists; critical future integrity risk | Role separation, approval/release state machine, immutable change history, signed exports, audit review | Assessments and Results |
| Backup exposure | No production backups exist | Encryption at rest and in transit, isolated access, immutable retention, restore drills, tenant-aware deletion and retention | Deployment / Security |
| Supply-chain compromise | Lockfiles and dependency audits configured | Review lockfile diffs, SBOM, update automation, attestations, secret scanning, SHA pinning or verified action update automation | CI / Security |

## Trust boundaries

- Browser to public web application: untrusted input, cookie/session and CSRF boundary.
- Application to MySQL/Redis/object storage: credentials and network policy boundary.
- Tenant user to platform operator: separate identity and authorization boundary.
- Web request to queue worker: serialized data crosses time and process boundaries.
- Integration/webhook to application: untrusted network origin and replay boundary.
- Public assets to private records: publication and download authorization boundary.

## Phase 01 review note

The internal readiness endpoint is a diagnostic capability. It is hidden unless a sufficiently long token is configured, requires a matching bearer token using constant-time comparison, has a low rate limit, returns no exception text, and is not enabled by the example environment. It must be stored in a secret manager in deployments.
