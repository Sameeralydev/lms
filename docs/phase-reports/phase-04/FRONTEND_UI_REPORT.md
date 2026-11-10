# Frontend UI report

Admissions pages present in `resources/js`:

- Public: admissions landing, intake detail, registration, application wizard, applicant dashboard.
- Staff: admissions overview and application detail.

The pages use Inertia and React, communicate with Laravel routes, and share existing workspace/public layouts and design tokens. The staff application view now exposes decision recommendation and independent approval actions according to permissions.

Not present as complete workflows: inquiry directory/detail, intake wizard, settings page, form builder/version manager, applicant directory, review workload, dedicated decision/merit/offer/waitlist/fees/communications/handoff/audit/report centers. Loading, filtering, pagination, six-theme compatibility, and all requested mobile journeys are not comprehensively implemented or verified. Production Vite build passed with 614 modules; details are in `PERFORMANCE_REPORT.md`.
