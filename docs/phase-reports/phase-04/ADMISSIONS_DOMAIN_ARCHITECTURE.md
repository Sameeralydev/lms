# Admissions domain architecture

Admissions is a Laravel modular-monolith domain under `App\Domain\Admissions`, with tenant-owned Eloquent records under `App\Models` and HTTP adapters in the public applicant and tenant workspace controllers.

The domain currently separates form normalization (`AdmissionFormDefinition`), admission workflow operations (`AdmissionApplicationWorkflow`), access scoping (`AdmissionsAccess`), and initial settings (`AdmissionsDefaults`). Tenant context is selected by existing Phase 02 middleware; records use tenant scopes and composite tenant foreign keys where modeled. The admissions feature gate is off by default.

The application is an applicant record, not a student enrollment. `AdmissionSisHandoff` stores a correlation ID and snapshot; it does not create a student. External communication, payments, malware scanning, and student creation are not claimed.

Current dependency direction is conventional Laravel: controllers validate HTTP input and call domain workflows; workflows write models, lifecycle/outbox records, and audit events. Some controllers still contain validation and persistence logic, and the full brief's typed request/action/policy separation is incomplete.

Recommended next boundary work: extract dedicated intake, form publication, eligibility, merit, document review, offer, and handoff actions as the feature set expands. Do not introduce microservices for these operations.
