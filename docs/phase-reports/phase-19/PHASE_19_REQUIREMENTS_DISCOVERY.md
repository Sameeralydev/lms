# Phase 19 requirements discovery and implementation assumptions

## Repository findings

- The checkout baseline is `02497d2`, the latest commit on `origin/Projects` and the default branch.
- Remote metadata contains no Developer A Phase 15, Phase 17 or Phase 18 branch and no Phase 19 specification.
- `docs/MODULE_BOUNDARIES.md` identifies HR and Operations as a separate, privacy-sensitive workforce context.
- The repository already has tenant memberships, accounts, tenant roles, campuses, academic units, audit events, and instructor/academic user assignments. It has no employee, HR, payroll, recruitment or staff leave tables.
- The preceding staged Phase 16 changes were stashed intact before switching branches. They are not present in this Phase 19 baseline; Phase 19 does not depend on them.

## Minimum coherent Phase 19 contract used

Because no approved Phase 19 acceptance contract is present, this change implements the workforce master-record foundation only:

| Capability | State | Implementation |
|---|---|---|
| Staff directory and profile | Missing | Added tenant-scoped employee records linked to existing active staff memberships |
| Staff identity/account linkage | Missing | Uses an existing membership; does not create accounts or credentials |
| Department and campus assignment | Partial infrastructure existed | Reuses existing campuses and academic units, validating institution and campus scope |
| Reporting manager | Missing | Adds same-tenant manager relationships and prevents reporting cycles |
| Employment lifecycle | Missing | Create, update, suspend, inactivate, separate and reactivate with effective-dated history and audit records |
| Sensitive contact data | Missing | HR-only fields use Laravel encrypted casts and are permission-gated |
| Payroll, recruitment, leave, staff clocking, performance and contracts | No source requirements/policies | Not implemented pending an approved business contract; no legal/payroll rules inferred |

Employment status is separate from tenant membership status. A separation does not silently disable the account because existing memberships may authorize non-employment workflows too. Account access remains governed through the current membership/role administration until a cross-module access policy is approved.

## Integration handoff

The employee profile uses `TenantMembership`, `User`, `Campus`, and `AcademicUnit`; academic instructor assignments continue to reference the same user account. Status changes are audited but do not mutate teaching assignments or membership roles. No Phase 16 communications integration is assumed because those changes are not committed in the baseline and are preserved separately in the Phase 16 stash.
