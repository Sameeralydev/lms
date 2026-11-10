# Phase 11 Test Report

Verification performed on 2026-10-30 in `C:\xampp\htdocs\lms`. Runtime observed: Laravel 13.38.0, PHP 8.3.35 CLI, Composer 2.10.3, Node 24.21.0, npm 11.19.0, MySQL 8.4.11.

| Check | Command | Result |
|---|---|---|
| Guarded MySQL read-only preflight, before and after tests | `php tests/run-phase11-mysql.php --preflight` | PASS, exit 0. Dedicated Phase 11 schema/account verified; MySQL 8.4.11; 189 schema objects and 51 migrations. Postflight ownership fingerprint remained `d7c115ee2283cf245ef96b7c43c72185f08d2b9c746e3e03fc7abdd2ee22c426`. |
| Guarded MySQL integration and concurrency suite | `php tests/run-phase11-mysql.php --run` | PASS, exit 0: 15 passed, 1 scanner-environment-dependent skip, 319 assertions; all 8 independent-session probes passed. The scanner-specific feature test was separately run against the installed scanner. |
| Full SQLite backend suite | `php artisan test` | PASS, exit 0: 282 passed, 9 skipped, 3,793 assertions. Skips are database/scanner-environment-specific; this suite is not used as evidence for MySQL behavior. |
| Real ClamAV assignment integration | `php artisan test tests\Feature\AssignmentManagementTest.php --filter=real_clamav_scans_assignment_clean_and_eicar_artifacts_fail_closed` | PASS, exit 0: 1 passed, 23 assertions. A harmless file was classified clean; EICAR was classified infected and stayed blocked from download/submission. Scanner configuration was supplied to this process only. |
| Full Chromium browser suite, scanner enabled | `npm.cmd run test:e2e -- --workers=1` | PASS, exit 0: 29 passed, 0 skipped, 13.1 minutes. The scanner-enabled local workflow ran with process-local ClamAV configuration. |
| Six-theme accessibility matrix | Included in full Chromium run | PASS across 6 learning screens × 6 themes, with axe WCAG 2.2 AA checks, keyboard focus visibility, mobile reflow, and dialog focus checks. |
| Frontend unit tests | `npm.cmd run test:unit` | PASS, exit 0: 6 tests passed. |
| PHP static analysis | `vendor\bin\phpstan analyse --no-progress --memory-limit=2G` | PASS, exit 0: no errors. |
| PHP formatting | `vendor\bin\pint --test` | PASS, exit 0. |
| TypeScript | `npm.cmd run typecheck` | PASS, exit 0. |
| ESLint | `npm.cmd run lint` | PASS, exit 0, no warnings. |
| Prettier | `npm.cmd run format:check` | PASS, exit 0. |
| PHP syntax | `php -l` over `app`, `bootstrap`, `config`, `database`, `routes`, and `tests` | PASS: 465 PHP files checked. |
| Composer metadata | `composer validate --no-check-publish` | PASS, exit 0. |
| Composer advisory audit | `composer audit` | PASS, exit 0: no security advisories. |
| npm advisory audit | `npm.cmd audit --audit-level=high` | PASS, exit 0: zero vulnerabilities. |
| Production frontend build | `npm.cmd run build` | PASS, exit 0: Vite transformed 667 modules and built production assets. Main application bundle: 362.04 KB (112.79 KB gzip). |

## Independent MySQL concurrency probes

All probes used two independent MySQL sessions and synchronized execution. Final persisted invariants were checked:

1. `same_attempt_submit`: both calls completed; one receipt, one outbox event, one audit event.
2. `draft_edit`: both calls completed; two ordered revisions persisted.
3. `deadline_boundary`: submission committed at the cutoff while the assignment closed.
4. `extension_decision`: one conflicting decision and one success; one final decision, outbox event, and audit event.
5. `group_submit`: both calls completed; two eligible members were snapshotted and one receipt persisted.
6. `grade_vs_resubmission`: grading and resubmission both committed; draft grade and next attempt persisted.
7. `grade_release`: one release succeeded and the competing release conflicted; one release, outbox event, and audit event persisted.
8. `publish_outbox`: one publication succeeded and the competing publication conflicted; one published version, outbox event, and audit event persisted.

MySQL postflight remained at 189 schema objects and 51 migrations with the same ownership fingerprint. No destructive reset command was used.

## Interpretation

The MySQL suite reports one scanner-configured case as skipped because the guarded DB environment does not enable the local scanner. Real ClamAV integration was independently verified by the focused test and the scanner-enabled 29-test browser run. Hosted CI was not executed; this report records local acceptance only.
