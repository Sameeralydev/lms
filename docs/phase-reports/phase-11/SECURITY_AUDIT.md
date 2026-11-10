# Phase 11 Security Review

## Scope

Local foundation review of Phase 11 assignment workflows and the guarded MySQL runner. This is not an OWASP ASVS certification, formal audit, or production penetration test.

## Verified controls

- Assignment access, grading, extension decisions, integrity workflows, and artifact access are tenant and role scoped in backend tests.
- Group membership is snapshotted for submissions; revisions, idempotency, and version checks are tested.
- Rubric scores are calculated server-side from immutable rubric versions.
- Private-network submission URLs are rejected.
- Artifacts remain quarantined until a current successful scan; clean files become available only through authorized routes, while infected or unscanned files remain unavailable.
- Real ClamAV 1.5.4 scanning was exercised with a clean file and the harmless EICAR test signature. The clean file was accepted; EICAR was rejected and remained inaccessible. Signature metadata was verified fresh at test time.
- The scanner-enabled browser run completed all 29 tests with no skips, including its clean-scanned document workflow.
- The Phase 11 MySQL runner checks the exact target identity, dedicated account, allowed grants, manifest, migration inventory, stable schema snapshots, local lock, and MySQL advisory lock before test operations.
- Eight independent-session probes verified committed-state and audit/outbox invariants under concurrent requests.
- Composer and npm audits reported no known advisories.

## Findings

No critical or high-severity Phase 11 issue was identified in the locally exercised scope. A real concurrent draft-revision race was found and fixed: the revision allocator now uses a locking read. Its two-session MySQL regression probe passes.

## Limitations

- Hosted CI was not executed. Local results must not be represented as hosted pipeline evidence.
- Production deployment configuration, external identity/mail providers, production storage, operational monitoring, and production penetration testing were not part of this local gate.
- No compliance certification is claimed.

## Decision

The mandatory local security checks for Phase 11 passed. No unresolved critical/high-severity local finding remains.
