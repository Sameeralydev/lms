# Phase 10 Security Audit

## Scope

Local security review aligned with relevant OWASP ASVS 5.0 topics; this is not a certification or a complete formal assessment. Reviewed attendance authorization, tenant scoping, evidence quarantine and release, scanner handling, notification payload scope, audit behavior, and the guarded MySQL lifecycle.

Evidence includes the guarded MySQL run (8 tests / 196 assertions), all eight independent-session concurrency probes, SQLite regression (264 passed / 3,455 assertions / 8 skipped), scanner-enabled Chromium suite (28 passed / 0 skipped), PHPStan, dependency audits, and production build.

## Verified controls

- Attendance routes require authentication, tenant workspace context and specific permissions. Evidence download requires `attendance.justification.review`; report and CSV routes require `attendance.report.view`.
- Absence evidence is stored under a tenant-scoped private quarantine path. The scanner job validates path shape, content digest and scanner result. Only clean evidence is downloadable; rejected, unavailable, tampered, and unscanned files fail closed.
- The real ClamAV workflow verified quarantine before scan, clean classification and authorized download, plus EICAR rejection and denial of both download and justification approval.
- Evidence download rechecks file integrity and emits private/no-store and nosniff headers. Audit events record successful downloads without retaining evidence contents.
- Notification delivery resolves active tenant recipients and emits safe payloads. Independent concurrent generation produced one logical event.
- MySQL runs require guarded target identity, allowlisted schema grants, exclusive local and advisory locks, ownership fingerprint checks, and postflight verification.
- Composer and npm audits reported no known advisories/vulnerabilities.

## Findings

- **No unresolved critical or high-severity finding identified in the reviewed Phase 10 paths.** All eight requested MySQL race probes passed with persisted-state and audit/outbox checks.
- **Operational configuration note — scanner timezone.** On this Windows host, `clamscan --version` reports a local-time timestamp while `sigtool` includes an explicit UTC offset. A UTC parser setting caused a metadata mismatch and correctly failed closed. The successful run set `ADMISSIONS_CLAMAV_TIMEZONE=Asia/Karachi` in the process environment. Each deployment must set this value to the scanner process timezone; do not disable signature freshness or metadata matching.
- **Low — manual accessibility review not performed.** Automated Axe, keyboard and responsive checks passed; assistive-technology testing remains separate.
- **Informational — hosted CI/production deployment were not exercised.** No hosted CI pass or production security certification is claimed.

No credentials, tokens, or private key material are recorded in this report.
