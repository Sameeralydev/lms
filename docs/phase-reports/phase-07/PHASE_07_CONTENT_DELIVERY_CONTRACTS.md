# Phase 07 content delivery contracts

## Current internal contract

Tenant-scoped Inertia routes under /t/{tenant}/learning provide workspace listing, creation, authoring, assignment, review, publication, learner snapshot access, progress writes, media upload and authorized private media delivery. CourseDeliveryWorkflow owns learning lifecycle and authorization decisions; CourseDeliveryMediaWorkflow owns quarantine, scanner and clean asset delivery decisions. Controllers validate request shape; workflows revalidate domain state and tenant membership.

The learner contract returns immutable published snapshot content, current lesson revision, member-specific progress and an authorized media URL. Release policy is enforced server-side. Learner payloads do not expose private storage paths, file hashes or scanner internals. Media URLs revalidate assignment and published-snapshot inclusion at request time.

## Current content types and limits

Authoring supports constrained text/heading/callout/code/divider/external-link/reflection blocks, a release-policy block, and private scanned media metadata. External links are HTTPS-only. Supported private media MIME types are allowlisted. The current interface does not implement learner reflection responses, checklist state, generic activity state, full rich-text editing, captions as tracks, media replacement/version history or lifecycle retention.

## Not currently contracted

No stable versioned JSON API, delivery event/outbox, template/copy, live-session, analytics, mobile/offline, public discovery, webhook, LTI, SCORM, xAPI, cmi5 or Common Cartridge contract exists. Do not present internal controllers as a public integration API.

## Extension rules

Keep tenant identity and authorization server-side. Future contracts must declare versioning, tenant scope, idempotency, authorization, data minimization, event/retry semantics and migration policy. Standards support must not be advertised until actual import/runtime/launch behavior and tests exist.
