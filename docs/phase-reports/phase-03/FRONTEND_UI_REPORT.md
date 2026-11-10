# Frontend UI Report

## Delivered pages

Seven Inertia academic pages are backed by tenant-scoped Laravel routes: overview, calendar, organization/programs, subjects/courses, jurisdiction policy profiles, quality/documents, and audit. The overview uses database counts and the current year; it does not show fabricated students, revenue, attendance, or progress. Directories use server pagination and page data includes related labels/counts instead of per-row frontend API calls.

The structure page now shows a nested accessible academic-unit list for the current result page and a table for parent names and editing. The calendar workflow includes search/status filtering, transitions, current designation, and year copy. Catalog workflows include draft version creation/publication and retirement. All forms post to Laravel and display Inertia validation/flash states.

## Design system

The existing six Phase 02 themes and tenant branding tokens are preserved: Executive Navy, Academic Emerald, Modern Indigo, Warm Ivory, Graphite Dark, and Midnight Violet. No new image/logo was fabricated. `npm run build` produced a Vite production manifest and code-split academic pages.

## Browser evidence

Playwright Chromium: 17/17 passed. Coverage includes academic year/period and linked structure/catalog/credit/outcome/quality creation, tenant authorization, mobile layout, axe checks on bounded pages, and switching all six themes. See [TEST_REPORT.md](TEST_REPORT.md).

## Configuration behavior

Academic feature flags now control server-side routes and capability-sensitive page content. Institution settings remain available to authorized institution-wide administrators when calendar capability is off; the overview and academic audit also remain available to authorized viewers. Tenant navigation hides catalog, policy, and quality destinations when those capabilities are disabled. Credit-bearing program/course writes are rejected by the backend while credit capability is off.

Configured terminology drives core organization/catalog headings and selected form labels. Detailed controls and explanatory copy are not fully substituted from the terminology map.

## Limits

The implementation does not provide a general-purpose data grid with arbitrary sorting. Configured terminology does not yet replace every detailed label and explanatory string. Some specialized actions (policy-version creation and confirmation) still use browser prompts. These are known design follow-ups, not hidden functional APIs.
