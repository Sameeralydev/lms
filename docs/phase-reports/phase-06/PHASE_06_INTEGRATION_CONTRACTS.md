# Phase 06 integration contracts

## Reused internal contracts

- Tenant/institution context: TenantContext, tenant.workspace, academic membership/scope middleware and AcademicRecord tenant scope.
- Academic source of truth: Phase 03 programs, levels, years/periods, catalog courses/course versions and learning outcomes. Curriculum planning references these records rather than duplicating them.
- Workflow boundary: App\Domain\Academic\Curriculum\CurriculumWorkflow and focused import/comparison/quality services enforce domain invariants; controllers validate requests and delegate operations.
- Authorization: academic.curriculum.* permissions gate view/manage/review/publish/template-sharing/audit operations; frontend visibility does not replace server checks.
- Audit: existing AuditRecorder, curriculum event names and minimal safe metadata.
- Publication contract: downstream modules should reference an immutable published curriculum version ID. A new plan revision is a separate draft version.
- Template contract: only explicitly shared, published curricula are discoverable; adoption creates a local independent draft and resolves references by institution-owned codes/IDs.
- Import contract: techmentors.curriculum.v1 structured JSON and bounded CSV outline import; preview is non-mutating, confirmation is authorization- and version-lock-protected. Published JSON export includes only tenant-scoped curriculum data.
- Notifications: review and lifecycle activity is represented by persisted review/audit events. A separately guaranteed external delivery/outbox SLA is not claimed.

## Future extension boundaries

Phase 07 course delivery may attach content to published curriculum version/node identifiers without mutating the source snapshot. Phase 08 enrollment, later assessment/result phases, student attainment, transcripts and standards interoperability remain outside this implementation. Do not use a curriculum ID as authorization. External/mobile API authentication, LTI/SCORM/Common Cartridge and cross-system standards exchange are not implemented.
