# Phase 15 architecture

## Delivered slice

The analytics vertical slice is a Laravel modular-domain service (`app/Domain/Analytics`) called by a tenant workspace controller. The registry owns metric definitions; the service owns SQL aggregation and tenant/institution/campus scoping; the controller validates filters, enforces analytics permissions, renders Inertia, and exports audited aggregate CSV; React renders KPI summaries, enrollment activity and filter controls.

No competing student/enrollment source of truth, analytics schema, migrations, charting dependency, or Phase 16 functionality was introduced. The trend is a CSS bar visualization backed by grouped enrollment rows and has a screen-reader table alternative.

## Isolation/cache

Every source query binds tenant and institution IDs. Campus filters are verified against tenant and institution; campus-scoped memberships are fixed to their assigned campus and receive only that campus in filter options. The active-student metric resolves an effective active placement first and uses home campus only when no active placement exists. Cache keys hash tenant, institution, membership, user, campus, normalized filters and role permissions with a 60-second TTL.

## Trade-offs and remaining design

The current page is an initial academic report, not a complete analytics platform. Membership-private saved configurations now persist validated campus/date/metric filters with per-membership uniqueness; they do not snapshot underlying source data. Snapshot persistence, queue processing, saved report sharing/scheduling, multi-domain adapters, PDF generation, metric-specific drill-down authorization, and the eight MySQL race probes are not implemented. MySQL lifecycle is not improvised; `.env.phase15.testing` is absent, so there is no verified dedicated Phase 15 connection locally.
