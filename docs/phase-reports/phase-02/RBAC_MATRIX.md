# Phase 02 Role and Permission Matrix

Authorization is deny-by-default. Platform routes require platform-scoped permissions; institution routes require verified tenant context, active membership and tenant-scoped permissions. Platform roles do not supply tenant roles, and tenant roles do not supply platform permissions.

## Platform role templates

| Role | Granted current permissions |
|---|---|
| `platform.super_admin` | All current `platform.*` permissions; created only by the one-time `platform:bootstrap-admin` command |
| `platform.operations_admin` | `platform.dashboard.view`, `platform.tenants.view`, `platform.tenants.create`, `platform.tenants.update`, `platform.tenants.status`, `platform.audit.view` |
| `platform.support_operator` | `platform.dashboard.view`, `platform.tenants.view`, `platform.audit.view` |

`platform.operators.manage` is reserved to the super-admin. The operator page can assign only operations/support roles to an existing global account. It cannot create or assign another super-admin. Self-assignment is rejected.

## Institution role templates

| Role | Current permissions | Additional scope |
|---|---|---|
| `tenant.owner` | All currently implemented tenant permissions | Cannot self-change role; last active owner cannot be removed or demoted |
| `tenant.admin` | All current tenant permissions except `domains.manage` | Cannot assign permissions absent from the actor |
| `campus.admin` | `tenant.view`, `campus.view`, `campus.update` | Only the membership's assigned campus; no campus creation |
| `auditor` | `tenant.view`, `campus.view`, `members.view`, `roles.view`, `audit.view` | Read-only current workspace |
| `academic.admin`, `teacher`, `student`, `parent.guardian`, `finance.manager`, `hr.manager`, `admissions.officer` | No current permissions | Role templates only; future phases must define actual permissions before they grant access |

Current tenant permission keys are `tenant.view`, `domains.manage`, `institution.update`, `campus.view`, `campus.create`, `campus.update`, `members.view`, `members.invite`, `members.manage`, `roles.view`, `roles.manage`, `branding.update`, `settings.update`, and `audit.view`.

## Assignment rules

- A tenant actor may assign only an existing role whose permissions are a subset of their own; only owners may assign the owner role.
- A user cannot change their own tenant role. The last active owner is protected in a locked transaction.
- Role creation accepts only permission IDs already held by the creator. Role names and keys are tenant-scoped and uniquely constrained.
- Cross-tenant role assignment is rejected by a composite foreign key, in addition to server-side query scoping.
- Campus admin assignment requires an active campus in the same tenant; subsequent campus reads/updates enforce that assignment.
- Provisioning requires a distinct initial owner email and uses a signed invitation. The platform operator is not inserted into the tenant membership table. Account access is activated only when the chosen owner accepts.
- An invitation is stored with a digest, expires after seven days, is single-use and is tied to the invited email. Existing accounts must authenticate as that email.

## Not present

No academic, student, guardian, finance or HR permission is implemented. Their role names do not expose routes or placeholder API functionality.
