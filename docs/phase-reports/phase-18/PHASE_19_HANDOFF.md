# Phase 18 Handoff Notes

Phase 18 work remains on `developer-a/phase-18`; no commit or push was made in this continuation because the worktree contains substantial pre-existing Phase 15, 17, and other phase changes that must not be swept into a broad commit.

Before Phase 19 begins, finish Phase 18 functional decisions and verification: approve/decline external payment provider scope; define bank statement reconciliation and reversal/credit policies; add the guarded Phase 18 MySQL target and execute isolated integration/concurrency tests; complete authenticated browser/accessibility coverage; rerun final focused checks; then prepare a file-scoped commit after reviewing the full Git diff.

Do not treat the internal CSV report as external bank reconciliation, manual payout references as provider-confirmed refunds, or the current unapplied balance as a separate credit ledger.
