# Phase 12 Database Design

Migrations inspected:
- 2026_11_03_000000_create_quiz_assessment_foundation
- 2026_11_03_000001_create_quiz_learner_accommodations

Foundation tables: quiz_question_banks, quiz_questions, quiz_question_versions, quiz_question_tags, quiz_question_tag_links, quiz_question_outcomes, quizzes, quiz_sections, quiz_versions, quiz_version_questions, quiz_question_pools, quiz_pool_question_versions, quiz_attempts, quiz_attempt_responses, quiz_response_revisions, quiz_grading_reviews, quiz_regrade_requests, quiz_assessment_policies, quiz_outbox_events, quiz_notifications. Follow-up adds accommodation data and response support. Runner cutoff is the second migration above.

Command php tests/run-phase12-mysql.php --preflight exited 2: .env.phase12.testing is missing; no database was contacted. No MySQL migration, ownership update or schema mutation occurred. MySQL migrations, FK/index state, grants and ownership fingerprint are NOT VERIFIED. SQLite cannot prove MySQL behavior.

Owner action: configure the ignored private .env.phase12.testing from its example for the dedicated Phase 12 schema/account, preserve its authorization value, add credentials locally, keep DB_URL empty, then pass guarded read-only preflight before --run.
