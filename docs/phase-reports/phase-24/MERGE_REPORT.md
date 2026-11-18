# Phase 24 — Merge Report

Date: 2026-11-19

## Source and destination

- Destination branch: `integration/lms-complete`
- Merge commit: `14c9fc081ccdc157ffdff1865a194e35143b65fb`
- Developer A source head: `4c00120e42cb7020b22cbb89fa4bdb55db30ec14`
- Developer B source head: `ef9568cabef06d8b96b4cded11107c5adf6a64e0`
- Merge parents are A first and B second. Both source branches were fetched before integration.
- The original `C:\xampp\htdocs\lms` worktree remained on `developer-a/integrated` and clean at A's source head. All integration work and local database state are in the separate `C:\xampp\htdocs\lms-phase24` worktree.

## Conflict resolutions

Git reported conflicts in five files; all were resolved and the merge commit was created:

- `app/Domain/Identity/PermissionCatalog.php`: retained both product permission catalogs and their role grants.
- `app/Http/Controllers/Workspace/DashboardController.php`: retained role-specific landing behavior and integrated the merged module dashboard selection.
- `routes/web.php`: retained routes from both branches and resolved route middleware/ordering conflicts.
- `routes/console.php`: retained scheduled commands from both branches.
- `docs/qa/DEFERRED_PRODUCT_QA_BACKLOG.md`: merged the existing deferred QA entries.

The resulting route list contains 670 application/vendor-excluded routes with zero duplicate route names.

## Post-merge integration corrections

- Allowed finance-report users through the analytics route group while keeping dataset-specific authorization in the controller and report builder.
- Added/backfilled the report export and drilldown permissions for eligible finance roles.
- Fixed analytics validation for empty optional dimensions and filters, normalized empty filters, and retained allowlisted filter validation.
- Fixed mobile overflow in the analytics report form.
- Made billing notifications respect an explicit per-recipient opt-out and made preference lookup work in queued delivery without ambient tenant context.
- Restored the numeric constraint on scheduled report-run download IDs.

The additive permission migrations are `2026_11_18_000002_backfill_academic_analytics_drilldown_permission.php` and `2026_11_18_000003_backfill_financial_report_permissions.php`.

## Preservation and Git state

- A pre-merge local branch `integration/lms-complete-premerge-806e49c` and stash `Phase 24 pre-merge checkpoint 806e49c` were retained. No stash was dropped.
- No force push, history rewrite, production operation, or persistent database migration was performed.
- The new integration branch did not exist on `origin` at last fetch. A normal push is intended after final staged review; the resulting push status and commit will be recorded here after verification.
