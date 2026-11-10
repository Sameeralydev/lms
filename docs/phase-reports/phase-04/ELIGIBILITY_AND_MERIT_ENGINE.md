# Eligibility, assessment, and merit

Eligibility rules are stored on intakes and evaluations record a version, result, rule results, reviewer note, actor, and time. Assessment rows support a limited set of assessment types, scheduling timestamps, scores, and private notes. Merit calculation writes versioned lists and entries from configured component weights.

These are initial foundations, not a complete policy engine: prerequisites/equivalencies, human exception approval, score locking/correction, applicant appointment views, robust tie-breaking, reproducible formula versioning, and a staff merit board are absent or limited. No protected-trait scoring is implemented or intended.

SQLite behavior is covered indirectly by feature tests and migration-backed code paths; no MySQL integration, representative large-dataset benchmark, or comprehensive adversarial merit test was run. Do not use these calculations as a production admission policy without policy-owner review.
