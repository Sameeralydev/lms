# Phase 06 curriculum governance

## Implemented lifecycle

Framework lifecycle is draft → active → retired. Draft identity, terminology, effective dates, required learning areas and required outcome configuration are version-locked and audited; framework history is scoped to the current tenant and institution.

Curriculum version lifecycle is draft → in review → approved, changes requested or rejected → published. Submission assigns an eligible active institution-wide reviewer with the review permission; the author cannot review their own version. The assigned reviewer records a decision and note. Publishing requires an approved version and an actor different from its reviewer. Transitions use transactions, row locks and optimistic version checks; published content cannot be changed through the workflow. A revision clones the published syllabus and mappings into a new draft.

The reviewer queue and per-curriculum review history are functional and permission-gated. Comparison reports added, removed and changed hierarchy, workload, outcomes, competencies, prerequisites and version metadata. Audit events record stable identifiers and safe status/metadata without raw request payloads or credentials.

## Verification evidence

- CurriculumManagementTest covers framework transitions/history, lifecycle, required outcomes, independent reviewer assignment, changes requested, resubmission, approval, separate publication, immutable snapshots, revisions, tenant denial and stale locks.
- MySQL 8.4 full suite: 190 passed / 2,055 assertions. The multi-process race test verified edits, approval and publication serialize correctly.
- Playwright verified the review queue, changes-requested workflow, approval, publishing, immutable published state and review-history page. Axe passed on the queue and history pages.

## Policy boundaries

The workflow safety policy is strict: reviewer and publisher separation is enforced. Reviewer assignment is selected at submission among active authorized users. Tenant-configurable multi-stage policy graphs, delegated framework administrator rosters, and user-authored QA rubric/review-checklist templates are not present. The quality analyzer reports system-defined design findings; it is not a reviewer checklist or institution-wide ISO 21001 quality-management system. These are mandatory product gaps in the original Phase 06 requirements; no compliance certification is claimed.
