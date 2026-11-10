# Phase 03 Test Report

All destructive test setup was run through the project’s guarded testing runner against isolated `techmentors_lms_test` or SQLite `:memory:`. Development database resets were not run.

| Check | Command | Result |
|---|---|---|
| MySQL backend suite | `composer.bat test` | 70 tests passed; 548 assertions; MySQL 8.4 isolated test schema |
| SQLite backend suite | `composer.bat test -- --sqlite` | 70 tests passed; 548 assertions; in-memory |
| Frontend unit tests | `npm.cmd run test:unit` | 6 tests passed |
| Browser suite | `npm.cmd run test:e2e -- --reporter=line` | 17/17 Chromium tests passed |
| Static analysis | `composer analyse` | PHPStan: no errors |
| PHP syntax | PHP lint loop across app/bootstrap/config/database/routes/tests | 137 files checked; no syntax errors |
| TypeScript | `npm.cmd run typecheck` | Passed (`tsc --noEmit`) |
| ESLint | `npm.cmd run lint` | Passed with zero warnings |
| PHP formatting | `composer format:check` | Pint passed after import-order correction |
| JS/CSS formatting | `npm run format:check` | Prettier passed |
| Production build | `npm.cmd run build` | Vite success; 605 modules transformed; app JS 352.77 kB (110.48 kB gzip), CSS 33.18 kB (7.40 kB gzip) |
| Composer validation/platform | `composer.bat validate --strict`; `composer.bat check-platform-reqs` | Both passed on PHP 8.3.35 |
| Composer audit | `composer.bat audit` | No security advisories |
| npm audit | `npm.cmd audit --audit-level=low` | 0 vulnerabilities |
| Routes | `php artisan route:list --path=academics --json` | 59 routes registered |
| Development migrations | `php artisan migrate:status --database=mysql` | 10/10 migrations `Ran`; Phase 03 batch 3 |

The complete browser workflow provisions a test tenant, creates/edit/activates a calendar, builds linked unit/level/program/class/section/subject/course/policy/version/outcome records, creates a quality objective, and continues Phase 02 invitation/RBAC/branding/theme/audit/isolation flows. The shell checks include liveness/readiness exposure, static asset delivery, keyboard navigation, responsive layout, host-header rejection, and private-file exposure.

The prior Phase 03 checkpoint recorded 69 backend tests / 516 assertions, 5 Vitest tests, and 17 Playwright tests. This repair adds a server-side academic capability-gating test (+1 test, +32 assertions) and a terminology-helper unit test (+1 frontend test). The final suite is 70 / 548 on both MySQL and SQLite, 6 Vitest tests, and 17 Playwright Chromium tests. The browser flow now enables credits and quality through the real settings form before creating records that require those capabilities.

The first full MySQL run after enforcing flags had three failures because legacy integration cases had relied on academy defaults enabling credit and quality operations implicitly. The test fixtures were updated to opt into those capabilities; the final MySQL and SQLite reruns both passed. No gate was suppressed or skipped.

A redundant final `npm.cmd audit --audit-level=low` invocation remained pending without output for about 50 seconds and was interrupted. The earlier invocation in this verification completed with 0 vulnerabilities; package manifests and lockfiles were unchanged afterward, so that completed audit remains the dependency-audit evidence.

Hosted GitHub Actions was not run from this workspace. Its configuration is reviewed separately in the final gate.
