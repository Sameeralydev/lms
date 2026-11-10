# Phase 12 Test Report

Updated: 2026-11-03. This report records final actual executions from the local Windows development environment. Secrets and private environment values are omitted.

## Guarded MySQL and scanner

- `php tests/run-phase12-mysql.php --preflight`: passed read-only identity/ownership preflight. MySQL 8.4.11; database `techmentors_lms_phase12_test`; account `techmentors_phase12_test@127.0.0.1`; 211 schema objects; 54 migrations; zero reviewed migrations pending.
- `php tests/run-phase12-mysql.php --run`: **6 passed / 271 assertions**. The suite included all 14 question-format persistence/grading-path checks, quiz policy and workflow, immutable snapshots, pool exhaustion/filtering, outbox retry/recipients and revisions.
- Real ClamAV test: clean question asset and learner file response accepted after scanning; standard EICAR test signature rejected and kept unavailable. Scanner failure/stale/missing-signature/timeout/unsupported cases were verified fail-closed by automated tests.
- Postflight: 211 objects / 54 migrations; ownership fingerprint unchanged at `a5d4746219d6a72ff1da4099c5e7b2ebc4427d49e1a627ee0e3c3861dc00739d`.
- Independent MySQL concurrency: **8 probes / 16 worker sessions passed**. Attempt start idempotency; autosave optimistic concurrency; autosave vs submission; timeout vs submission; automatic grading; manual grade vs regrade; result publication; notification generation. Output confirmed one committed state/event as appropriate, no duplicate attempts/revisions/logical notifications, and audit/version invariants.

## SQLite and unit tests

- `php tests/run-tests.php --sqlite`: **300 passed / 4,152 assertions / 10 skipped**, exit 0. Skips were not counted as passes; engine-specific and real-scanner checks ran in their compatible guarded suites.
- `npm.cmd run test:unit`: **24 passed / 3 files**. Includes timer announcement boundaries/expiry and UI/question-editor tests.

## Authenticated browser tests

- `npx.cmd playwright test --project=chromium --workers=1` with ClamAV enabled from the ignored local Phase 12 test environment: **35 passed / 0 failed / 0 skipped**, 16.7 minutes.
- Covered signed-in instructor all-format authoring/review, approved pool creation, learner all-format rendering and save/reload, quiz submission, manual grade, result release, regrade decision, notification center, real-scanned file upload, oversight analytics, authorization denial, validation error, timer and six-theme behavior.
- The transfer certificate journey creates its own tenant, owner, student and reviewer fixtures. Focused execution passed **1/1**, and it also passed in the full suite. Quiz browser journeys passed current focused runs after the final test-selector correction.
- Six-theme checks run Axe on the listed screens and validation-error state for all registered themes, verify distinct design tokens, reduced-motion behavior, saved theme after reload, 390px reflow and keyboard-visible focus. The player additionally checks its theme contrast samples.

## Static analysis, audits and build

Commands and results:

- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`: PASS, no errors.
- `vendor/bin/pint --test`: PASS.
- PHP `-l` loop over `app`, `routes`, `database`, `tests`: PASS, **487 files**.
- `npm.cmd run typecheck`: PASS.
- `npm.cmd run lint`: PASS.
- `npm.cmd run format:check`: PASS.
- `composer validate --strict`: PASS.
- `composer audit --locked`: PASS, no advisories.
- `npm.cmd audit --audit-level=high`: exit 0; **12 moderate** `minimatch` advisories are reported in the ESLint/TypeScript-ESLint development dependency tree. The audit recommends a forced toolchain downgrade to `typescript-eslint@8.14.0`; it was not applied because that is a breaking change. No high/critical advisory was reported.
- `npm.cmd run build`: PASS; Vite 8.3.5, **676 modules**, app JavaScript **363.28 kB / 113.08 kB gzip**.
- `php artisan route:list --path=assessments --except-vendor`: PASS, **33 routes**.
- `git diff --check`: PASS.
- `.env.phase12.testing` remains Git-ignored; credentials are not in report output.

## Historical failures and resolution

An earlier Chromium regression recorded 28 passed / 2 failed / 5 not run. The final complete scanner-enabled runs subsequently passed 35/35. A database-backed strict snapshot comparison also initially failed because MySQL JSON object key order differed; the assertion was corrected to compare semantic values, then MySQL passed. Adding the live timer status region exposed an ambiguous browser test locator; it was scoped to the intended regrade status and the affected journey passed. These earlier failures are retained here and were not counted as passes.

## Not run

- Hosted CI: NOT RUN; no hosted execution is claimed.
- Manual screen-reader/assistive-technology evaluation: NOT RUN.
- Production load/performance certification: NOT RUN.
