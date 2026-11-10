# Phase 14 final functional completion evidence — 2026-11-10

## Scope and status

This is a fresh continuation addendum. It does not erase historical failures or represent the entire Phase 14 gate as passed.

- `PHASE14_IMPLEMENTATION=PARTIAL`
- `PHASE14_ENGINEERING=BLOCKED`
- `MYSQL_ENVIRONMENT=OWNER_ACTION_REQUIRED`
- `MYSQL_TESTS=NOT_RUN`
- `EIGHT_CONCURRENCY_PROBES=NOT_RUN`
- `REPORT_CARDS=PASS` for the exercised authenticated workflow
- `TRANSCRIPTS=PASS` for the exercised issue/download workflow
- `CERTIFICATES_QR=PASS` for QR-bearing PDF, public verification, and revocation status; independent QR decoding was not performed
- `NOTIFICATIONS=INCOMPLETE` (idempotent in-app events are implemented for the tested workflows; full event/appeal matrix and complete browser notification-center coverage are outstanding)
- `REGISTRAR_WORKSPACE=INCOMPLETE` (reports and record issuance verified; complete registrar operations matrix remains outstanding)
- `GUARDIAN_WORKSPACE=INCOMPLETE` (verified-guardian records verified; released grades/standing and broad role matrix remain outstanding)
- `PHASE14_BROWSER=PARTIAL`
- `SIX_THEMES=PARTIAL`
- `OWNER_NPM_RISK=PENDING`

Phase 15 was not started.

## Implemented in this continuation

Extended the existing transactional outbox to queue idempotent in-app events for gradebook submission/review, grade correction request/decision, report-card comment submission/decision, and official record submission/decision. Existing grade publication and record issue/revocation events remain. Delivery resolves reviewers using active, non-revoked tenant memberships scoped by institution/campus and the required permission; decision events resolve the active record creator or assigned teacher. Payloads contain only scoped entity identifiers. No migration was added.

Files changed for this slice:

- `app/Domain/Assessments/QuizOutboxProcessor.php`
- `app/Domain/Grading/GradebookWorkflow.php`
- `app/Domain/Grading/OfficialAcademicRecordWorkflow.php`
- `tests/Feature/Phase14GradebookFoundationTest.php`

The focused feature test verifies workflow-created outbox events, expected reviewer/teacher/creator recipients, event delivery, and that a repeated dispatch does not create duplicate notifications.

## Fresh verification evidence

- `php artisan test --filter=Phase14GradebookFoundationTest`: **9 passed / 422 assertions**, exit 0.
- `php artisan test --compact`: **355 passed, 4 MySQL-only skipped / 7,697 assertions**, exit 0.
- `npm.cmd run test:e2e -- tests/e2e/phase14-gradebook.spec.ts --project=chromium`: **1 passed**, 4.5 minutes. The authenticated journey exercised teacher comment submission/review, report-card/transcript/certificate preparation and issuance, private PDF downloads, certificate public verification, registrar reporting/CSV, student records, and verified guardian record access.
- The browser run exercised six themes on applicable views with axe, keyboard focus, mobile reflow, and persistence assertions; **42 screenshots** are in `test-results/phase14-gradebook-theme-evidence/`. This is not the full Phase 14 role-by-screen matrix. Fixture roles were institution owner/grade manager, reviewer, student, and guardian; a separate teacher-only login and all role/screen permutations were not covered.
- PHPStan: PASS on changed domain files. Pint: PASS. PHP syntax: PASS on changed PHP source/test files. `npm.cmd run typecheck`: PASS. `npm.cmd run lint`: PASS. `npm.cmd run format:check`: PASS. `npm.cmd run test:unit`: **24 passed**. `npm.cmd run build`: PASS, Vite transformed 695 modules. Composer validation/platform checks: PASS. `git diff --check`: PASS.
- Composer audit completed once with no advisories. A later online retry could not resolve Packagist DNS and did not complete. `npm.cmd audit --omit=dev`: **0 vulnerabilities**. Full `npm.cmd audit`: **10 moderate development-tooling vulnerabilities**, including the ESLint/TypeScript-ESLint minimatch chain; no forced update was applied and owner disposition remains pending.
- Guarded `php tests/run-phase14-mysql.php --preflight`: exit 1, `Missing regular private configuration .env.phase14.testing; no database was contacted.` The file is absent and its filename is Git-ignored. `tests/Support/phase14-grade-concurrency.php` is absent; `--run` fails closed before database contact. No Phase 14 MySQL session, migration, or schema mutation occurred.

An immediately preceding full SQLite run overlapped a test expectation edit and failed on a stale expected outbox delivery count. The assertion was corrected based on actual delivery, the focused test passed, and the complete final SQLite rerun passed. No security guard or assertion was weakened.

## Remaining blockers and smallest actions

1. **MySQL owner setup:** Follow `PHASE_14_MYSQL_TEST_PROVISIONING.md`. DBA/owner must privately provision/configure the exact isolated schema/account, verify the documented schema-only grants, and authorize the database as disposable in ignored `.env.phase14.testing`. Then run the guarded preflight.
2. **Concurrency engineering:** Implement all eight genuine independent-session production workflow probes in `tests/Support/phase14-grade-concurrency.php`, preserving identity/grant/barrier/timeout/committed-state protections; only then will the guarded runner permit `--run`.
3. **Acceptance coverage:** Complete the full authenticated role-by-screen/theme matrix, notification-center/browser recipient journeys, and remaining registrar/guardian scope. The latest targeted one-test journey is not a full Phase 14 Chromium acceptance run. Earlier scanner-enabled shared Chromium was 39 passed / 5 failed; the previously failing groups passed in targeted reruns, but the full suite has not been rerun to a green result.
4. **Governance:** Owner decision remains pending for the ten moderate development-tooling npm findings. Production dependency audit is clean; this does not approve development-tool residual risk.
5. **Manual QA:** Human assistive-technology testing was not performed.

No database mutation, Phase 15 work, or security certification is claimed.