# Educational Quality Management Foundation

## Delivered records and workflow

- Objectives have institution-scoped codes, name/description, quality area, metric definition/unit, optional target, owner, review cycle, and due date.
- Active objectives accept dated review submissions with decision, measured value, summary, and evidence references.
- A review cannot be approved by its own reviewer. A separate authorized approval records actor/time/note.
- A `not_met` decision can drive follow-up review scheduling and corrective actions; actions can be assigned, updated, and closed with audit history.
- Controlled academic documents track type, code/title/summary, effective dates, private stored file reference, submission state, and independent approval decision.
- Changes use tenant-scoped audit events and do not copy complete request payloads into logs.

The feature suite exercises independent review approval, corrective action flow, private document access, and cross-tenant denial. Upload routes use private storage and do not expose a public storage-serving endpoint.

## Standards boundary

This is a small governance evidence foundation that can later receive attendance, curriculum, completion, and learner-feedback evidence. It is not a full quality-management system, independent audit, accreditation record, or ISO certification. See [EDUCATION_STANDARDS_MAPPING.md](EDUCATION_STANDARDS_MAPPING.md).
