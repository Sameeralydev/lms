# Six-Theme Frontend Audit

Audit date: 2026-10-30. This is an implementation and local test review, not a certification or production contrast assessment.

| Theme | Registry key | CSS tokens | Authenticated dashboard exercised | Persistence checked |
|---|---|---|---|---|
| Executive Navy | `executive-navy` | Yes | Yes | Yes |
| Academic Emerald | `academic-emerald` | Yes | Yes | Yes |
| Modern Indigo | `modern-indigo` | Yes | Yes | Yes |
| Warm Ivory | `warm-ivory` | Yes | Yes | Yes |
| Graphite Dark | `graphite-dark` | Yes | Yes | Yes |
| Midnight Violet | `midnight-violet` | Yes | Yes | Yes |

## Evidence

- `resources/css/app.css` defines semantic color, canvas, surface, border, text, focus, and action tokens for all six registry keys. The two dark variants set `color-scheme: dark`; the other four use light color schemes.
- `app/Domain/Appearance/ThemeRegistry.php` is the supported theme registry. `HandleInertiaRequests` chooses an allowed user preference before the institution default. `ThemeController` validates and persists the user choice; `WorkspaceLayout` applies `data-theme` to the authenticated application shell.
- Tenant primary/accent colors are passed as CSS custom properties and do not require a per-institution frontend build. Existing platform-workspace E2E coverage saves institution branding and verifies user theme persistence.
- The new authenticated dashboard Playwright matrix selects each theme, checks the active `data-theme`, reloads to check persistence, confirms a distinct non-empty canvas token, runs axe WCAG 2.2 AA rules, and verifies no horizontal overflow at 390px. It also saves `screenshots/theme-switcher.png`.
- Existing browser coverage includes six-theme checks on attendance, timetabling, and course-delivery learning screens; the course-delivery matrix covers six screens across six themes. These are automated checks against the current rendered state.
- The final complete Chromium run passed **31 cases** and skipped **1** scanner-dependent certificate journey. The dashboard theme matrix passed for all six themes within that run. See `FRONTEND_TEST_REPORT.md` for the full command and skip reason.

## Limits

The six theme registry entries and dashboard styles are implemented. Automated axe runs do not replace manual screen-reader and assistive-technology review. This audit did not perform a manual contrast measurement for every generated state, branding logo variant, dialog, form validation state, and all 89 rendered components. The full-component theme matrix remains a manual/targeted UI review item where not covered by existing browser journeys.
