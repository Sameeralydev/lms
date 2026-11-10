# Accessibility report

## 2026-10-23 browser verification update

The complete local Playwright Chromium suite now passes **20/20** tests (the earlier 18/18 count is historical). It includes the guardian authority/revocation and admissions decision/offer/handoff journeys, responsive viewport checks, keyboard navigation, and axe smoke coverage. This remains sampled coverage, not a WCAG conformance assessment; screen-reader review, RTL, reduced-motion, and all admissions-page keyboard/axe combinations are not claimed.

The recovery Playwright suite passed 18/18 Chromium tests. Coverage includes the shared shell keyboard/narrow-width path, the six existing institution themes, and a new public admissions intake journey at a 390×844 viewport with an axe scan. Audit history pagination was added and keyboard-reachable.

The admissions journey tests administration, form publication, and public intake detail only. The full applicant wizard, guardian path, upload/review states, offer response, and staff decision screens still lack dedicated keyboard-only and axe coverage. Screen-reader review, RTL and reduced-motion coverage, and WCAG conformance are not claimed. See `TEST_REPORT.md` for the exact browser command and results.
