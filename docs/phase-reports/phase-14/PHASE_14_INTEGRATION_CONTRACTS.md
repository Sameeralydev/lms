# Phase 14 integration contracts — current boundary

The implemented boundary accepts only manually authored gradebook items. `GradebookWorkflow::createItem` rejects `source_type` other than `manual` with a validation error stating that assessment synchronization remains unavailable until source results are verified and released.

The future assignment, quiz, and formal examination adapters must each provide tenant/institution ownership, enrolled learner identity, source identifier/version, a released or independently approved state, score numerator/denominator, amendment lineage, and idempotent re-import semantics. Sync must record the source version and reject stale/unapproved states. In particular, Phase 13 draft, unreleased, or unapproved results must never enter official publication snapshots.

No external API, queue integration, or source adapter is implemented in this slice. The owner-provided Phase 13 grading contract and original Phase 14 R&D documentation were not found in the workspace.
