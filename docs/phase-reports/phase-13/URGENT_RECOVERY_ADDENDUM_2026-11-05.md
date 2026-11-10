# Phase 13 Urgent Recovery Addendum — 2026-11-05

This addendum records fresh ClamAV and browser evidence for the 2026-11-05 urgent recovery. It supersedes only the earlier stale-signature and incomplete full-Chromium-run findings. It does not close remaining Phase 13 functional, exhaustive coverage, or owner-approval gates.

## ClamAV signature freshness

- FreshClam successfully applied the authentic signed daily delta from the official ClamAV distribution. The installed engine remains ClamAV 1.5.4; the active daily database is version 28174.
- `clamscan.exe --database=<user-scoped-database> --version` reported `ClamAV 1.5.4/28174/Thu Nov 5 11:26:24 2026`.
- `sigtool.exe --info <user-scoped-database>/daily.cld` reported build time `05 Nov 2026 06:26 +0000`, version 28174, 355,841 signatures, and `Verification OK.`
- At 2026-11-05 09:39 UTC, the signed daily database was 3.22 hours old, within the existing 48-hour application policy. The signed main database (version 63) and bytecode database (version 339) were also previously verified. The application compares the daily signed metadata with the scanner-reported daily version and build time before scanning.
- No timestamps were altered, no signature-age threshold was raised, and quarantine behavior was not bypassed. No system service, Apache configuration, or machine-wide setting was changed.

## Browser failure diagnosis and repair

The earlier full scanner-enabled run stopped at 27 passed / 1 failed / 14 not run. Its only reported failure occurred in the long course-delivery workflow after the test's six-minute timeout: the workflow had already performed real media scans and reached asynchronous theme changes, while the theme assertion did not wait for the rendered theme state. The browser was closed by the test timeout, so the missing theme locator was not evidence of a missing theme. A focused command selecting only this case also failed because the test correctly depends on the preceding serial platform-provisioning case; this was a fixture-order limitation, not an application defect.

The browser test now gives this bounded scanner-heavy workflow a 15-minute ceiling and waits for the actual `[data-theme]` element to carry the selected theme. The application scanner, freshness policy, and quarantine logic were not changed.

Evidence:

| Command | Result | Evidence |
|---|---|---|
| `npm.cmd exec -- playwright test --project=chromium --trace=off --grep "platform administrator signs in and provisions an institution|institution owner creates and authors a curriculum-linked course delivery workspace"` | PASS | 2 passed; 6.9 minutes. The serial fixture prerequisite and repaired scanner/theme workflow both completed. |
| `npm.cmd exec -- playwright test --project=chromium --trace=off` with `E2E_CLAMAV_ENABLED=true` and the configured local scanner/database | PASS | 42 passed, 0 failed, 0 skipped; 24.2 minutes. All 42 cases completed in one invocation. |

The full run exercised authenticated incident evidence quarantine with clean and EICAR test-signature files, examination script scanning, course-media upload and replacement, transfer-document scanning, examination journeys, and six-theme checks present in the relevant screens. EICAR was rejected/quarantined; clean files were released only through their authorized workflow after a successful scan. Automated axe results are limited to the screens and checks actually present in the suite; they are not manual assistive-technology verification.

## Additional fresh checks

| Command | Result |
|---|---|
| `npm.cmd run typecheck` | PASS |
| `npm.cmd run lint` | PASS |
| `npm.cmd run format:check` | PASS after formatting the changed Playwright spec |
| `npm.cmd run test:unit` | PASS: 24 tests in 3 files |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | PASS |
| `vendor/bin/pint --test` | PASS |
| `composer validate --strict` | PASS |
| `composer audit --locked` | PASS: no Composer advisories |
| `npm.cmd audit --audit-level=high` | PASS at the high threshold; output still reports 10 moderate development-tooling advisories |
| `npm.cmd audit --audit-level=moderate` | FAIL / exit 1: 10 moderate advisories in the ESLint/TypeScript-ESLint `minimatch` dependency chain. npm's suggested forced fix changes ESLint to 9.39.5 and is breaking; no forced update was made. Owner risk disposition remains pending. |
| `npm.cmd run build` | PASS: Vite transformed 685 modules |
| `git diff --check` | PASS before report updates; rerun after report updates |

The project's latest existing database evidence remains the guarded Phase 13 MySQL result recorded in the dated test report (25 tests / 591 assertions; eight independent-session probes; 251 objects / 67 migrations / zero reviewed migrations pending; ownership fingerprint unchanged) and SQLite result (327 passed / 4,810 assertions / 10 skipped). This recovery did not run migrations or access a database. Those database results are cited as prior verified evidence, not as commands rerun in this recovery.

## Current gate and handoff classification

- **A — Mandatory local functionality incomplete:** the full appeal-specific escalation/remedy lifecycle and the remaining separate examination operational reports are still partial. Existing result recheck/version workflows remain the controlled path; finalized results are not silently overwritten.
- **B — Verification coverage incomplete:** the complete applicable role-by-screen authorization and six-theme matrix is not established by the 42-case full browser suite. The suite provides fresh representative evidence, including controller, student, invigilator, examiner/practical, administrator, and learning workflows. This addendum does not claim every role/screen/theme cell passed.
- **C — Scanner environment:** the immediate stale-signature blocker is resolved locally and the scanner-enabled browser suite passes. Production operations still need monitored signature freshness and approved updater/network configuration.
- **D — Manual/external evidence pending:** human assistive-technology testing was not performed; the original roadmap/R&D source documents were not found; hosted CI and production deployment checks remain outside this local run.
- **E — Dependency risk:** 10 moderate development-tooling npm advisories remain, with no self-approved exception. Owner review or a compatible remediation is required according to project policy.

**Verdict: PHASE 13 PARTIAL — CLIENT HANDOFF POSSIBLE ONLY WITH DISCLOSED LIMITATIONS.** A scoped demonstration is suitable for the freshly verified workflows, including current local scanner-backed upload flows. Do not represent Phase 13 as fully accepted or production-ready until the functional breadth, complete role matrix, and required owner disposition are closed. Phase 14 was not started.
