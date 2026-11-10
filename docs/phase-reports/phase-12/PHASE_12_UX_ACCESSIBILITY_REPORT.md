# Phase 12 UX and Accessibility Report

Updated: 2026-11-03. Results are automated local browser/component evidence only; no manual assistive-technology evaluation or formal accessibility certification is claimed.

## Authenticated functional journeys

The final scanner-enabled full Chromium run completed **35 passed / 0 failed / 0 skipped**. Phase 12 journeys use authenticated instructor, learner and institution-owner accounts with real Laravel/Inertia routes and persisted data:

- Author and independently review all 14 supported question formats.
- Configure and publish a randomized pool with approved candidates.
- Render all formats in the learner player, submit answers/files, and verify server save state after reload.
- Submit an assessment, obtain manual grading and feedback, release results, request/review a regrade, and verify learner results and notifications.
- Open scoped analytics and verify unauthorized grading/cross-tenant navigation is denied.
- Submit an invalid opening/closing schedule and verify the server error is exposed through an accessible alert.
- Dismiss then accept the native submission confirmation; dismissal leaves the saved attempt available.

The full Phase 12 MySQL suite additionally invoked real ClamAV scanning for quiz question assets and learner file responses, including the harmless standard EICAR test signature.

## Six-theme automated matrix

Registered themes checked:

1. `executive-navy`
2. `academic-emerald`
3. `modern-indigo`
4. `warm-ivory`
5. `graphite-dark`
6. `midnight-violet`

Screens/states checked across all six themes: question editor; quiz builder and pool configuration; quiz schedule validation error; all-format learner player; manual grading queue; independent regrade queue; institution assessment oversight/analytics.

For each matrix screen, Playwright checked Axe rules tagged WCAG 2.0/2.1/2.2 A/AA, distinct text design tokens, reduced-motion emulation, persisted selected theme after reload, mobile reflow at 390×844, keyboard navigation and a visible focus indicator. The quiz player also checks theme-specific foreground/surface values and contrast samples. A Vitest component test verifies timer status announcements at minute thresholds and expiry.

The quiz player uses the browser's native `window.confirm` for final submission. Browser tests verify its prompt text and both dismissal and acceptance. Native dialog rendering/focus is owned by Chromium/OS; there is no custom DOM dialog to Axe-audit in this workflow.

## Historical regressions

An earlier full serial Chromium run recorded 28 passed / 2 failed / 5 not run; initial failures concerned timetable feedback and a transfer-certificate workflow. The transfer journey now provisions its own tenant, owner, student and reviewer fixtures and passed independently (1/1), as well as in the final full suite. The final current-code full suite passed 35/35. The previous result remains documented in `PHASE_12_TEST_REPORT.md`.

## Not performed

- Manual screen-reader testing (NVDA, JAWS, VoiceOver or equivalent): NOT RUN.
- Hosted CI browser execution: NOT RUN.
- Formal WCAG conformance or accessibility certification: NOT CLAIMED.

Playwright screenshots and traces remain local test artifacts under ignored `test-results/`; no public landing-page image is treated as evidence for an authenticated workspace.
