# Academic RBAC Matrix

All permissions are tenant scoped. Every academic request also requires the Phase 02 verified tenant membership/context. A platform operator does not gain institution access from a platform role. The initial tenant creator is not automatically granted tenant membership by academic provisioning.

| Permission | Capability | Default role grant |
|---|---|---|
| `academic.view` | Dashboard and view academic workspace | Owner, tenant admin, academic admin, auditor |
| `academic.settings.manage` | Operating model, timezone, locale, terminology, feature flags | Owner, tenant admin, academic admin |
| `academic.calendar.manage` | Academic-year create/edit/lifecycle/current/copy | Owner, tenant admin, academic admin |
| `academic.periods.manage` | Period create/edit/lifecycle/current | Owner, tenant admin, academic admin |
| `academic.structure.manage` | Units, levels, qualification types | Owner, tenant admin, academic admin |
| `academic.programs.manage` | Program create/edit | Owner, tenant admin, academic admin |
| `academic.classes.manage` | Class/cohort/batch and section configuration | Owner, tenant admin, academic admin |
| `academic.catalog.manage` | Subject/course definitions and draft versions | Owner, tenant admin, academic admin |
| `academic.credit.manage` | Credit policy version creation | Owner, tenant admin, academic admin |
| `academic.regional.manage` | Jurisdiction profiles, evidence, mappings, gaps | Owner, tenant admin, academic admin |
| `academic.outcomes.manage` | Outcome references and associations | Owner, tenant admin, academic admin |
| `academic.quality.manage` | Objectives, reviews, evidence, corrective actions | Owner, tenant admin, academic admin |
| `academic.documents.manage` | Controlled document records and submission | Owner, tenant admin, academic admin |
| `academic.policy.approve` | Program/course/policy/quality/document approval transitions | Owner, tenant admin, academic admin |
| `academic.audit.view` | Academic event history | Owner, tenant admin, academic admin, auditor |

The default `academic.admin` role gets the academic permission set. The auditor receives only academic view/audit access. Tenant owners/admins retain their broader Phase 02 role grants. No teacher/student/guardian permission set was introduced.

## Campus scope

`RequireAcademicScope` is fail-closed and allowlists only route families whose controllers check record/campus relationships. Calendar, unit, program, class, and section operations validate campus assignment. Institution-wide settings, levels/qualifications, catalog, policy profiles, quality, and audit are rejected for campus-scoped members. Unit/program multi-campus rows may be read where assigned; mutation requires a single-campused record. Feature tests prove cross-campus denial and own-campus success.
