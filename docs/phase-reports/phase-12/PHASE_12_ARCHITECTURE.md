# Phase 12 Architecture

Phase 12 adds quiz-level formative assessment to the Laravel modular monolith. It reuses the approved one-institution-per-tenant design, tenant context, membership authorization, course/enrollment foundations, and outbox/audit patterns. It does not replace the official gradebook; formal examinations remain Phase 13 and official grades/transcripts Phase 14.

Observed boundaries: Workspace AssessmentController; QuizAssessmentWorkflow for lifecycle/attempts; QuizAssessmentQueries for read models; QuizAutoScorer; QuestionDefinition; QuizOutboxProcessor; React assessment index, attempt, grading, regrades, analytics and notification pages.

Verified browser vertical slice: learner submits; instructor grades and releases; learner views released result; institution owner filters assessment analytics. Autosave now advances the client lock version only after server confirmation and serializes saves per question.

MySQL transactional isolation/races are unverified because the guarded database preflight did not connect. This is not full assessment acceptance.
