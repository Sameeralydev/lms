# Phase 12 Integration Contracts

Tenant HTTP family: t/{tenant:slug}/assessments, coordinated by Workspace AssessmentController with Laravel validation and tenant workspace context.

Domain services: QuizAssessmentWorkflow (lifecycle/attempt/grading/results), QuizAssessmentQueries (read models), QuizAutoScorer (objective answers), QuizOutboxProcessor (notifications), QuestionDefinition (question normalization). Eloquent records persist banks, versions, quizzes, responses, reviews, regrades, policies and notifications.

The implementation reuses tenant/course/enrollment foundations and does not create a competing official gradebook. Formal exams are Phase 13.

Not independently verified: all response schemas, notification event semantics, exports/pagination guarantees, MySQL transactions and outbox idempotency under concurrent workers.
