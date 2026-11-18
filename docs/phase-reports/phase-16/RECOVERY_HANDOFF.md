# Phase 16 Recovery Handoff

## Source and preservation

- Recovered from `stash@{0}` (`68b3b8c3643f54537a2820c3809ae9071d0d527e`), based on `02497d2136e4e8ddcaf7847b15cd3a78621507ce`.
- The stash was applied, not popped, in the isolated `developer-b/phase-16-recovery` worktree. It remains available as a backup.
- Recovery applied without conflicts. The branch does not contain Phase 19–23 commits.

## Implemented functionality in the recovered patch

- Tenant-scoped announcements with tenant role targeting and optional campus targeting.
- Immediate publication, editing, paginated visibility, and per-user read receipts.
- Minimal audit events for publish and edit actions; no announcement body or title is copied into audit metadata.
- Announcement view/manage permission catalog entries and grants for selected tenant role templates.
- Workspace routes, dashboard card, navigation entry, and responsive Inertia announcement form/list.
- Tenant-aware announcement and receipt models, relationships, and an additive migration with tenant/campus constraints and lookup indexes.

Review corrections in this recovery add campus validation/persistence to edits and align the edit control with server-side author/manager and campus authorization.

## Scope limitations

- The repository has no authoritative Phase 16 acceptance matrix; the recovered discovery note records this.
- Direct messaging/threads, recipient targeting beyond roles/campus, notification-inbox delivery, delivery preferences, external email/SMS/WhatsApp/push, draft/scheduled publication, and archive/unpublish controls are not implemented in this recovered patch. They require reconciliation against the approved Phase 16 contract before being claimed complete.
- Existing role-specific portals and module notifications are reused; this patch does not create replacement portal or notification systems.
- The migration has not been executed. No database identity or authorization was verified.

## Integration notes

The recovery is based on the pre-Phase-19 baseline. Its changes overlap later branches in `PermissionCatalog`, `Tenant`, `DashboardController`, `routes/web.php`, and the centralized QA backlog. Integrate with Phases 19–23 using a reviewed merge/cherry-pick and resolve those shared-file changes deliberately; do not overwrite the later phase code. The communication controller depends on the existing `TenantContext`, `TenantMembership`, role permissions, and campus-scoped models.

## Verification

- PHP 8.4 syntax checks: passed for changed PHP files and migration.
- Focused route check: four announcement routes registered with auth, tenant workspace, view, and required manage middleware.
- Pint on changed PHP files/routes: passed after formatting.
- TypeScript check: passed.
- Focused ESLint on the announcement page and workspace layout: passed.
- Vite production build: passed.
- Database-backed authorization, tenant/campus adversarial tests, authenticated UI workflows, migration execution, and full product regression remain **QA PENDING** in `docs/qa/DEFERRED_PRODUCT_QA_BACKLOG.md`.
