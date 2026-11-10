# Phase 13 Final Acceptance Executor — 2026-11-06

This run supersedes older verification counts for checks rerun below. Historical records remain unchanged. Phase 14 was not started.

## Fresh verification

| Check | Result | Evidence |
|---|---:|---|
| Guarded MySQL preflight and suite | PASS — MySQL 8.4.11; 253 objects; 69 migrations; zero reviewed migrations pending | `php tests/run-phase13-mysql.php --preflight` then `php tests/run-phase13-mysql.php --run`; database/account identity matched the Phase 13 target; ownership fingerprint unchanged: `dea00d4011c05e37bbc229a803a7a5a16bc0ca82178ae5d65fc62e4e65640e7c` |
| Phase 13 MySQL tests | 35 passed; 1 scanner-gated test skipped; 3,117 assertions | Guarded `--run`, exit code 0 |
| Independent MySQL concurrency | 8 probes passed, 16 worker sessions | Each pair used distinct MySQL connection IDs and bounded barrier synchronization; candidate registration, seat allocation, paper publication, exam start, autosave/finalization, script marking, result approval/amendment, and result notification idempotency |
| SQLite regression with configured ClamAV | 337 passed; 4 skipped; 7,142 assertions | `php tests/run-tests.php --sqlite`, exit code 0; MySQL-only concurrency/schema cases explain the skips |
| Full scanner-enabled Chromium | 43 passed; 0 failed; 0 skipped | `npm.cmd exec playwright -- test --project=chromium`, ClamAV environment enabled; exit code 0 |
| Focused examination results and role/theme journeys | 2 passed | Result evidence workflow and all 78 role-screen-theme combinations; fresh built assets were served |
| Theme screenshot evidence | 78 PNG files | `test-results/examination-theme-matrix-a-7adf9--and-persist-all-six-themes-chromium/` |
| Route denial sweep | 73 routes; 365 denial requests passed | Guest, unprivileged member, unauthorized role, foreign tenant, and revoked member cases; mutation sentinels unchanged; route inventory matched Laravel's live route collection |
| Vitest | 24 passed | `npm.cmd run test:unit` |
| PHPStan / Pint | PASS | `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`; `vendor/bin/pint --test` |
| PHP syntax | 561 files passed | `php -l` loop over `app`, `routes`, `tests`, and `database` |
| TypeScript / ESLint / Prettier | PASS | `npm.cmd run typecheck`, `npm.cmd run lint`, `npm.cmd run format:check` |
| Build | PASS | `npm.cmd run build`; Vite 8.3.5 transformed 685 modules |
| Composer validation / audit | PASS; no advisories | `composer validate --strict`; `composer audit` |
| npm production dependency audit | PASS; zero vulnerabilities | `npm.cmd audit --omit=dev` |
| Full npm audit | FAIL by existing development-tool findings | 10 moderate advisories in the ESLint / TypeScript-ESLint `minimatch` dependency chain; compatible remediation would require a breaking ESLint update; no forced update or self-approval was made |
| `git diff --check` | PASS | Run after source and documentation edits |
| ClamAV signature metadata | PASS at inspection | ClamAV 1.5.4; signature database version 28174; verification OK; build timestamp 2026-11-05 06:26 UTC, within the configured 48-hour maximum at the 2026-11-06 inspection |

## Authorization matrix disposition

The fixed manifest contains all 73 live Phase 13 examination/appeal routes and is checked against Laravel's current route collection. The 365-request negative sweep passed. The sweep deliberately uses synthetic missing resource identifiers for generic denial cases; it is not positive workflow proof.

The required positive route-by-route matrix is **not complete**: **0/73** rows have a manifest-bound positive execution record. Separate workflow tests do exercise valid actions, including `tenant.academics.examinations.cycles.store` and `tenant.academics.examinations.cycles.status`, but they are not an exhaustive 73-route evaluator. The latter test also proves one wrong-campus denial with no state or audit mutation. A separate paper test proves one foreign-institution question is rejected by the paper-store workflow.

Current route applicability bookkeeping:

- Campus: 1 route explicitly N/A (`tenant.academics.examinations.candidate-governance.policy`, institution-wide eligibility policy); the other 72 still require route-specific applicability and evidence assessment. One wrong-campus denial is verified on the cycle-status route. A same-campus campus-scoped positive route case is not yet verified.
- Foreign resource: 11 collection/read routes are explicitly N/A because no caller-selected resource identifier exists; server-side collection scoping still applies. Of the other 62, one paper-store foreign-question case is verified; 61 remain without route-specific foreign-owner evidence.
- Private-file, paper-confidentiality, result-release, appeal/incident state, and other action-specific checks have workflow coverage, but are not all bound to every applicable route row.

## Theme and accessibility disposition

The authenticated role/screen matrix exercised 13 cells across six persisted themes (**78/78**) for institution administrator, examination controller, examiner, invigilator, and student. It checked real page content, theme persistence after reload, axe rules, keyboard focus, mobile horizontal reflow, and captured one PNG per combination. The online delivery fixture is provisioned through the real authorized route when absent, so the matrix runs independently of Playwright file order. Automated axe checks do not replace human assistive-technology testing; no manual screen-reader test is claimed.

## Remaining gate items

1. **Engineering evidence incomplete:** build and execute the route-specific positive action evaluator for all 73 manifest rows, including expected persisted effects and audit/outbox effects for mutations.
2. **Engineering evidence incomplete:** assess each route's campus/resource applicability, add valid same-campus and foreign-campus/foreign-owner resources where applicable, and verify denial without mutation. Current evidence is one cycle-status wrong-campus case and one paper-store foreign-question case.
3. **Owner disposition pending:** the owner has not signed an option in `NPM_ADVISORY_OWNER_DECISION.md` for the ten moderate development-tooling advisories. Production dependencies remain audit-clean.
4. Hosted CI and manual assistive-technology testing were not run; these remain separate verification limitations.

**Final status: PHASE 13 — SPECIFIC REMAINING ENGINEERING FAILURES.** The route-level positive and campus/resource matrix is not satisfied. No local test was relabeled as passed to close that gap.

## Fresh verification addendum — 2026-11-06
See [PHASE13_FINAL_VERIFICATION_ADDENDUM_2026-11-06.md](PHASE13_FINAL_VERIFICATION_ADDENDUM_2026-11-06.md) for fresh guarded MySQL, SQLite, Chromium, scanner, static-check and authorization evidence. The current route authorization gate remains blocked: 73 workflow references are linked, but systematic positive route probes are 0/73; 70 campus-applicability rows and 61 applicable foreign-resource rows remain unverified. Do not infer completion from linked workflow evidence.
