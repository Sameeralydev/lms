
# Fresh execution evidence — 2026-11-17

This addendum supersedes stale earlier statements that the Phase 15 concurrency harness or private MySQL target were missing. Older execution history remains preserved; this is the latest evidence.

## Implemented and verified in this execution

- Added `ContentBoundAdmissionDocumentScanner` and `AdmissionDocumentScanning`. The scanner handoff verifies the SHA-256 recorded for the exact quarantined bytes before scanning; a clean result becomes unavailable if the bytes change during scanning. `PrivateQuarantineUpload::stage` now verifies source and staged size/hash equality. The guarded behavior is used by admissions, SIS, assignment, attendance, examination, course media, quiz artifacts and academic curriculum private documents.
- Added regression coverage for disappearing upload metadata, missing content, changed bytes during scan and changed bytes before scan. `php artisan test tests/Feature/PrivateQuarantineUploadTest.php --compact`: **6 passed / 18 assertions**.
- Repaired brittle Chromium assertions: the Phase 15 analytics test now checks its accessible heading directly; the examination marking test uses the unique `#main-content` landmark instead of an ambiguous `main` selector.
- Added connection identifiers and open-transaction evidence to the Phase 15 concurrency output.

## Guarded Phase 15 MySQL evidence

- `php tests/run-phase15-mysql.php --preflight`: **exit 0**. MySQL 8.4.11; dedicated `techmentors_lms_phase15_test`; account `techmentors_phase15_test@127.0.0.1`; owner-pinned server identity and private data directory verified; **272 schema objects, 84 migrations, 0 pending reviewed migrations**.
- `php tests/run-phase15-mysql.php --run`: **exit 0**; **15 tests / 226 assertions**. No migration was pending. Postflight retained **272 objects / 84 migrations**, fingerprint `2d835263eb01ab344f53348fb77b9f84a0808ae3f0c2c97fd88e46e8353f8f0b`; owned fixture cleanup passed.
- All eight probes used two distinct MySQL connection IDs with two transactions open at the synchronized barrier (16 worker sessions total):
  1. Concurrent metric refresh — PASS (357, 358).
  2. Duplicate source aggregation/idempotent recalculation — PASS (359, 360).
  3. SIS campus amendment versus report calculation — PASS (361, 362).
  4. Concurrent tenant-scoped cache refresh — PASS (363, 364).
  5. Same-name saved-report generation — PASS; one success, one conflict (365, 366).
  6. Concurrent CSV export — PASS using the synchronous export route because this application has no export-job claimant (367, 368).
  7. Report quota/audit generation — PASS using the real saved-report quota boundary because no analytics notification queue exists (369, 370).
  8. Export versus SIS source correction — PASS (371, 372).
- The final read-only preflight after `--run` again reported **272 / 84 / 0 pending**. Phase 01–14 schemas and unrelated databases were not targeted.

## Fresh regression results

- `php tests/run.php --phase=all --profile=sqlite --no-report`: **exit 1; 381 passed, 6 failed, 4 skipped / 8,043 assertions**. All six failures are real ClamAV/EICAR workflows (Admissions, Assignments, Attendance, Course Media, Quiz, SIS). Windows Defender removed, intercepted or replaced the test payload before ClamAV reliably received the standard signature. Course media reported `clean` only for the different bytes that reached the scanner; it did not establish EICAR detection. Quarantined content remains inaccessible unless the persisted bytes scan clean. Do not count these as EICAR passes.
- Deterministic scanner classification remains verified: `tests/Unit/ClamAvSignatureMetadataTest.php`, including `malware exit code is deterministically rejected`, `file changed during a clean scan is never accepted`, scanner timeout, stale signatures and process failure.
- `npm.cmd run test:e2e -- --project=chromium --workers=1`: **exit 0; 47 passed**, scanner enabled. EICAR browser upload emitted `EICAR_UPLOAD=HOST_INTERCEPTED_FAIL_CLOSED`; this is safe rejection evidence, not a real malware-detection pass.
- Focused browser repairs also passed: Phase 15 analytics **2/2** and examination marking **1/1**.
- Dashboard/theme evidence: 12 roles × one dashboard × six themes = **72/72 passed**, 72 screenshots at `test-results/phase15-role-dashboard-theme-matrix/`, machine-readable `results.json`. Assertions cover authenticated page/heading/navigation, axe, theme persistence, keyboard focus and mobile reflow. This is a dashboard screen matrix, not proof that every module screen and operation is complete.
- Examination role/screen/theme evidence: **78/78 passed** across five roles and 13 applicable examination screens × six themes. The test verifies real page content, Inertia component and tenant, theme persistence, axe, mobile reflow, keyboard focus, and no page errors; 78 screenshots and an attached JSON matrix are in Playwright test results. Manual assistive-technology testing was not performed.
- Fresh frontend/static checks: `npm.cmd run test:unit` **24/24**; `npx.cmd tsc --noEmit` exit 0; `npm.cmd run lint` exit 0; `npm.cmd run format:check` exit 0; `npm.cmd run build` exit 0 (697 modules); `vendor/bin/pint --test` exit 0; `composer analyse` exit 0; PHP syntax checks exit 0; `composer audit --no-interaction` reports no advisories; `npm.cmd audit` reports 0 vulnerabilities; `git diff --check` exit 0 (only existing CRLF-normalization warnings).

## Remaining Phase 15 acceptance gaps

- Genuine ClamAV EICAR detection cannot be demonstrated on this host while Windows Defender intercepts the fixture. Required external environment: an owner-approved isolated scanner test runtime that receives the unmodified EICAR bytes without weakening host protections. Do not alter Defender or add broad exclusions.
- The current analytics workspace has 12 registered source-backed metrics, campus/date/metric filters, saved private filter configurations, aggregate CSV export, accessible trend/table presentation and print. It is still not the required general report builder: record-level drill-downs, configurable searchable/sortable/paginated result tables, session/course/status dimensions, PDF, export history UI, and complete cross-domain catalog remain incomplete.
- Twelve supported role dashboards pass the dashboard/theme smoke matrix, but the matrix verifies entry screen/navigation and selected real content; it does not prove every dashboard action or every Phase 01–14 module integration is complete. A full LMS role × every screen × theme matrix remains unverified.
- The eight concurrency probes pass for the actual implemented workflows. Probe 6/7 are documented substitutions for absent asynchronous export-job and analytics-notification workflows; those workflows are not claimed implemented.

**Current Phase 15 result: BLOCKED.** Phase 16 has not started. No historic failure has been relabeled as passing evidence.
