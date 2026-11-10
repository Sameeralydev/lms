# Phase 12 Implementation Report

## This continuation

- Replaced the raw question-revision JSON editor with the structured question definition editor. Revision submissions now preserve the question type, content, answer key and scoring rules through the nested Inertia payload.
- Added structured type-specific authoring inputs and scoring controls for the supported formats, with explanatory fail-closed messaging for file-response media.
- The assessment controller now includes answer keys and scoring rules only for memberships authorized to manage or review the question bank.
- Added a feature test that creates and persists all 14 supported question formats as versioned drafts, plus an assertion for authorized instructor answer-key projection.
- Added a Playwright instructor authoring journey for a structured true/false question and component tests for question-definition editors.
- Set Playwright to one worker because the suite shares database and browser fixtures; this resolved parallel shared-state interference but does not establish a green full suite.
- Earlier continuation fixes retained: numeric negative answer scoring, serialized autosave/client lock-version handling, authenticated learner submission/grading/result release and institution-owner analytics.

## Verification

The focused Phase 12 and regression evidence is in `PHASE_12_TEST_REPORT.md`. Guarded MySQL passed 4 tests / 193 assertions with its 210-object, 53-migration ownership fingerprint unchanged. SQLite passed 298 / 4,088 with 9 skips. The complete Chromium run did not pass (28 passed, 2 failed, 5 not run).

## Scope still incomplete

Eight independent MySQL concurrency probes, quiz-specific private scanned media, complete pool and regrade workflows, complete notifications, all-role browser workflows, all-screen six-theme accessibility and full browser regression remain outstanding. No Phase 13 implementation was started.
