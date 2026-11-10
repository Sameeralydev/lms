# Security baseline

## Verification target

Use OWASP ASVS v5.0.0 Level 2 as the baseline target for the eventual platform because it will handle student and financial information. Apply selected Level 3-strength controls to identity, tenant isolation, privileged actions, audit history, and financial/academic records. This is a target profile, not a certification or a claim that future controls are complete.

## Phase 01 controls implemented

- `.env` is ignored; `.env.example` contains blank credentials and no application key. A local key was generated without printing it.
- `APP_DEBUG=false` is the safe example and local default. Test configuration also disables debug output.
- Laravel web middleware remains responsible for CSRF protection on future state-changing routes. No state-changing product route exists yet.
- Blade templates escape interpolated output by default; React renders text as text. Avoid `dangerouslySetInnerHTML` and raw Blade output unless content is sanitized for a documented use case.
- Use Laravel validation and allowlists at request boundaries. Use Eloquent/query builder bindings; do not build SQL from user input.
- Session defaults use JSON serialization, encrypted file-backed local sessions, HTTP-only cookies, SameSite Lax, and secure cookies by default when `APP_ENV=production` (unless explicitly overridden).
- Named route limits provide 60 requests/minute for the public shell/liveness surface and 10 requests/minute for diagnostics, keyed by source IP. Authentication-specific limits remain future work.
- Global response headers include `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, and `Cross-Origin-Opener-Policy`. Production responses add a restrictive same-origin CSP; HSTS is emitted only for a secure request.
- Public liveness returns only a generic status. Readiness requires a configured 32+ character bearer token, is rate-limited, does not log exception detail, and returns only component states.
- Private local files live under `storage/app/private`; no public storage symlink or upload route is created. The repository root denies Apache access; the configured web document root must be `public/`.
- Composer and npm lockfiles are committed; dependency audits are part of CI.
- Laravel's cache prefix and Redis connection defaults are application-scoped. Tenant-specific cache, session, job, and storage isolation is not implemented.
- Inertia DevTools is explicitly disabled in the environment example. Laravel's local disk serving is disabled, so framework-level file read/upload routes are not registered. Add a reviewed, authorization-aware file workflow before enabling either capability.

## Audit-event design convention

Future security and academic/financial audit events should record an immutable event identifier, UTC timestamp, actor and effective principal, institution/tenant scope, action, target type and identifier, outcome, request correlation identifier, and a minimal reason code where applicable. Write the event in the same transaction as the protected change when feasible; otherwise use a durable outbox. Restrict audit reads and exports, define retention and tamper-evidence controls, and test that event history cannot be changed through normal application paths. Never include passwords, tokens, session identifiers, full request bodies, payment credentials, or unnecessary student data in the event payload.

## Operational controls still required

Use a secret manager for `APP_KEY`, database and Redis credentials, and `HEALTHCHECK_TOKEN`. Keep production debug disabled. Enforce HTTPS at the proxy/load balancer; configure trusted proxy addresses explicitly before relying on forwarded scheme or IP headers. Restrict `storage/` and `bootstrap/cache` to the application service account. Keep the project root outside the document root, and expose only `public/`. Require reviewed dependency updates and audit findings before release.

No authentication, role/permission system, tenant authorization, audit log, backup encryption, upload pipeline, integration egress policy, or financial/academic controls are claimed complete in this phase. See `THREAT_MODEL.md` and the audit reports.
