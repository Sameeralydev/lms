# Phase 13 npm Audit Risk Disposition Proposal

## Current disposition proposal - 2026-11-05

Latest explicit thresholds: `npm.cmd audit --audit-level=moderate` exits 1 with 10 moderate GHSA-g3hv-75wg-m249 findings in the ESLint/TypeScript-ESLint development chain; `npm.cmd audit --audit-level=high` exits 0. `npm.cmd ls --omit=dev --depth=0` lists only `@inertiajs/react`, `react` and `react-dom` as direct production dependencies. The available automated fix requires a breaking ESLint major transition. No force fix or package override was applied.

**Proposal:** classify this as a disclosed, nonblocking local client-handoff risk pending owner review, provided the owner confirms no stricter project policy makes moderate development-tool advisories a release blocker. This is not accepted or self-approved. A compatible toolchain migration or explicit owner decision remains the next risk-management action. Production deployment still requires a fresh audit at release time.

The earlier proposal text below records previous dependency versions/counts and its prior blocked disposition. The current count and proposed local handoff disposition above supersede that historical content; no owner approval is represented.

## Fresh evidence - 2026-11-05

`npm.cmd audit` exits 1 with **10 moderate** advisories for GHSA-g3hv-75wg-m249 in the `minimatch` dependency chain under ESLint/TypeScript-ESLint. No high or critical advisory was reported in this run. `npm.cmd audit fix --dry-run` audited 261 packages, made no changes and reported that the available remediation requires `npm audit fix --force`, which would switch the toolchain to ESLint 9.39.5 (a breaking major transition). No forced downgrade or unreviewed override was applied. The package audit is a development-dependency risk, not a production-runtime finding based on current dependency scope.

**Disposition remains OPEN / OWNER APPROVAL REQUIRED.** The earlier 12-advisory count is historical; this fresh audit reports 10. The proposal has not been accepted or self-approved. Phase 13 remains blocked until a compatible tested migration is selected or the owner explicitly approves a time-bounded exception.

**Status: PROPOSED — OWNER APPROVAL REQUIRED. This finding is not accepted or waived.**

## Evidence

Executed `npm.cmd audit` on 2026-11-04. It exited with code 1 and reported 12 moderate advisories, zero high advisories, and zero critical advisories. The reported issue is the transitive `minimatch` glob-parts amplification denial-of-service advisory, GHSA-g3hv-75wg-m249, affecting versions below 11.0.1 through the ESLint and TypeScript-ESLint development-tool dependency graph.

`npm ls eslint @eslint/js typescript-eslint minimatch --depth=2` reported ESLint 10.14.0, `@eslint/js` 10.0.1, `typescript-eslint` 8.72.1, and `minimatch` 10.2.6. The audit's suggested forced remediation selects `typescript-eslint` 8.14.0 and `@eslint/js` 9.39.5, which is a breaking major-version change and a downgrade relative to the currently installed toolchain. No `npm audit fix --force` or unreviewed override was applied.

The affected packages are development dependencies. This is a supply-chain and developer-tooling risk; the current evidence does not show the vulnerable package in the production runtime dependency set. No formal security compliance claim is made.

## Proposed owner decision

Choose one explicitly:

1. Approve a separately tested migration to a compatible ESLint 9 / TypeScript-ESLint dependency matrix, including lockfile review and all frontend quality checks; or
2. Approve a time-bounded exception that records the development-only exposure, limits use of untrusted ESLint configuration/glob input, assigns an owner, and sets a review date.

Until an owner makes that decision, the advisories remain **OPEN / NOT APPROVED** and Phase 13 acceptance remains blocked.
