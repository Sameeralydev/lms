# Phase 12 Security Audit

Updated: 2026-11-03. This local code and test review is not a penetration test, formal ASVS assessment, WCAG certification or compliance certification.

## Current findings

| Severity | Finding | Status / evidence |
|---|---|---|
| Critical / High | No unresolved critical or high-severity Phase 12 application issue identified in the executed local checks. | PASS for tested scope; see limits below. |
| Medium | npm reports 12 moderate `minimatch` advisories through ESLint and TypeScript-ESLint transitive dependencies. These are development dependencies, not production runtime packages. | OPEN, non-blocking for local Phase 12 acceptance. `npm audit --audit-level=high` exits 0. The suggested `--force` remediation downgrades TypeScript-ESLint and is a breaking dependency change; no forced change was made. Reassess when a compatible fix is available. |
| Low / process follow-up | Hosted CI and manual screen-reader testing were not executed. | NOT RUN; follow-up only, not represented as a local pass. |

## Verified controls

- Guarded MySQL runner independently verified the Phase 12 test database/account/grants/ownership; final state was 211 objects / 54 migrations with unchanged fingerprint `a5d4746219d6a72ff1da4099c5e7b2ebc4427d49e1a627ee0e3c3861dc00739d`.
- The learner projection does not expose question answer keys; feature coverage checks serialized learner data.
- All quiz document artifacts are private and tenant/actor scoped. Unscanned, rejected, scanner-error and stale/mismatched scan results remain unavailable. The guarded MySQL suite invoked the configured real ClamAV scanner on clean and EICAR test-signature artifacts; both question-media and learner-file-response integration paths were exercised.
- Scanner metadata unit checks verify missing binary/signature database, stale/future metadata, version mismatch, process failure, unsupported paths and timeout fail closed.
- Authorization feature/browser coverage checks tenant membership, learner ownership, role denial and cross-tenant/platform navigation denial.
- Eight two-connection MySQL probes verified transaction/version/idempotency/audit invariants for attempts, responses, grading, regrades, publication and notification delivery.
- Error responses, the schedule validation alert, public health behavior and sensitive public-file exposure checks ran in the complete browser suite. No credentials were printed or added to reports.

## Limits and follow-up

- These controls are evidence from automated local testing, not a claim that every endpoint has undergone independent penetration testing.
- Hosted CI and manual assistive-technology testing remain unverified.
- Track upstream `minimatch` remediation for the development toolchain and rerun the full npm audit after a compatible release.
- Preserve scanner freshness/signature gates in deployments; the local test signature does not substitute for continuous production signature maintenance.

## Historical findings

An earlier report listed missing race probes, missing Phase 12 scanner integration, incomplete browser coverage and two full-suite failures as open. They are closed for the scope above based on the final guarded MySQL, scanner, browser, SQLite and static-check evidence. The old 28/2/5 browser outcome remains preserved in the test report as historical evidence.
