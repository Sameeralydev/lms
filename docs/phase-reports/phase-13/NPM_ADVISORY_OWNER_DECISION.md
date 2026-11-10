# Phase 13 npm advisory owner decision

**Recorded:** 2026-11-05  
**Decision status:** PENDING OWNER DECISION — neither option selected or approved.

## Verified finding

`npm audit --json` reports 10 moderate findings, 0 high, 0 critical. They resolve to the single advisory [GHSA-g3hv-75wg-m249](https://github.com/advisories/GHSA-g3hv-75wg-m249), describing potential denial of service through globparts amplification in `minimatch` versions `>=7.0.0 <11.0.1` (CWE-405). The locked tree contains `minimatch@10.2.6` under `eslint@10.14.0` → `@eslint/config-array@0.23.5`, and `typescript-eslint@8.72.1` → `@typescript-eslint/typescript-estree@8.72.1`.

All affected paths are development tooling. `package.json` places ESLint and TypeScript-ESLint in `devDependencies`; the runtime dependency list contains Inertia React, React, and React DOM and does not include these packages. Fresh `npm audit --omit=dev` returned `found 0 vulnerabilities`. This establishes production dependency-tree scope, not a blanket security guarantee for development tooling.

The npm audit fix metadata proposes ESLint `9.39.5` and TypeScript-ESLint `8.36.0` as major-version changes from the currently installed major lines. Those would alter the established lint/type-check toolchain, so no forced change was made. No compatible, non-breaking fixed dependency was identified in the current dependency metadata. No `npm audit fix --force` was run and no vulnerability is reported as eliminated from the development tree.

## Mitigations and residual risk

- Keep lint globs and configuration repository-controlled; do not pass untrusted user input to ESLint/minimatch patterns.
- Use bounded CI/local command timeouts and retain the current lockfile for reproducible installs.
- Continue to verify production dependencies with `npm audit --omit=dev` and keep the full development audit visible.
- Re-evaluate when a compatible patched dependency range is published or schedule a reviewed ESLint/TypeScript-ESLint major migration with config and behavior regression checks.
- Residual risk: a crafted glob pattern could cause excessive processing in a developer/build tool invocation. No affected package is included in the production dependency tree per the checked lockfile and omit-dev audit.

## Owner decision — required

- [ ] **A — Accept** the documented residual moderate development-tooling risk for limited local/client delivery, with remediation scheduled.
- [ ] **B — Reject** acceptance and hold delivery until compatible remediation is available or an upgrade is explicitly approved.

Owner: ____________________  
Decision date: ____________________  
Scope / conditions: ____________________

This record is a proposal only. It does not approve risk, certify compliance, or close the security-governance gate. Until the owner records A or B, disposition remains pending.

## Verification update — 2026-11-06

Fresh `npm.cmd audit` still exits 1 for **10 moderate development dependency advisories**, including **GHSA-g3hv-75wg-m249** in the `minimatch` dependency chain used by ESLint/TypeScript-ESLint tooling. The audit indicates remediation would require a breaking ESLint update via `npm audit fix --force`; that command was not run. `npm.cmd audit --omit=dev` reports **0 vulnerabilities**. No owner choice has been signed and no risk has been self-approved. The engineering status is recorded in [the final acceptance addendum](FINAL_ACCEPTANCE_EXECUTOR_2026-11-06.md).
