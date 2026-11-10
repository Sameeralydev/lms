# Admissions RBAC matrix

| Capability | Permission | Intended scope | Current implementation |
|---|---|---|---|
| View admissions workspace | `admissions.view` | Active tenant membership | Route middleware |
| Manage settings/intakes/forms | `admissions.settings.manage`, `admissions.intakes.manage`, `admissions.forms.manage` | Tenant and campus scope | Implemented routes; UI incomplete |
| Review applications/documents | `admissions.applications.review`, `admissions.documents.review` | Assigned application or owner/admin | Server-side access helper and routes |
| Recommend a decision | `admissions.decisions.recommend` | Assigned reviewer or owner/admin | Separate pending recommendation |
| Approve a decision | `admissions.decisions.approve` | Owner/admin; not assigned reviewer; not recommender | Independent approval enforced in controller/workflow |
| Manage offers/fees/handoff | `admissions.offers.manage`, `admissions.fees.manage`, `admissions.handoff.manage` | Tenant/campus-scoped operations | Implemented action routes; incomplete surrounding workspaces |

The admissions officer template receives operational admissions permissions, including recommendation, while excluding final approval, settings, and audit-view grants. Tenant owner/admin templates include approval. SQLite tests verify role grants, reviewer recommendation, distinct-owner approval, and offer gating. The complete requested role, workload, campus/program, and privacy matrix has not been independently exercised on MySQL.
