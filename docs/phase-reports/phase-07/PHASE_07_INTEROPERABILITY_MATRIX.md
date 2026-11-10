# Phase 07 interoperability matrix

| Capability | Current evidence | Status |
|---|---|---|
| Internal Laravel/Inertia authoring and delivery | Tenant-scoped routes and CourseDeliveryWorkflow; full guarded MySQL suite passed | Implemented for the current internal slice only. |
| Course/curriculum provenance | Delivery foreign keys to existing published versions; MySQL migrations and feature suite passed | Implemented for workspace creation. |
| Versioned REST API | No stable versioned API resources | Not implemented. |
| LTI 1.3 / LTI Advantage | No registration, OIDC launch, claims, deep linking or deployment lifecycle | Not implemented. |
| SCORM 1.2/2004 | No package import, runtime, attempt state or sequencing implementation | Not implemented. |
| xAPI | No statements, actor mapping, LRS adapter or delivery | Not implemented. |
| cmi5 | No package/session/runtime implementation | Not implemented. |
| Common Cartridge | No import/export or content mapping | Not implemented. |
| Learning media | Private scanned PDF/image/audio/video/text route and learner rendering exist; no standards-based player contract | Partial internal support only. |
| Mobile/offline | No mobile API or offline synchronization semantics | Not implemented. |
| Webhooks/outbox | No content delivery outbox or signed webhook delivery | Not implemented. |

No external standard compatibility or conformance claim is made. Future interfaces must declare versioning, tenant scope, auth/signing, idempotency, data minimization and retry behavior, and require independent tests.

## Closure status

No external interoperability capability was added by the learner-activity completion slice. Public versioned APIs, SCORM, xAPI, cmi5, Common Cartridge, LTI, mobile/offline interfaces and webhooks remain outside the verified implementation. Existing internal contracts remain the current boundary; no conformance claim is made.
