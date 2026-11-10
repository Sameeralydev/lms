# Offers, seats, and waitlist

## Final concurrent MySQL verification (2026-10-23)

The previous sequential-only limitation is resolved. The MySQL test `test_mysql_simultaneous_offer_issuance_preserves_capacity_one` passed (1 test / 8 assertions): two competing offer calls against capacity one yielded exactly one issued offer and one capacity rejection, one reservation, and no oversubscription. The initial test exposed a repeatable-read snapshot issue; the service now performs a bounded locking current read while holding the intake lock. The full guarded MySQL suite then passed 122 / 934. Earlier verification notes below describe the pre-fix state.

## 2026-10-23 verification update

A new isolated SQLite regression verifies capacity 1 with two approved applications: one offer and reservation succeeds; the other is rejected. The 14-test / 202-assertion admissions subset passes. This is sequential capacity evidence only. MySQL 8.4 locking/concurrency remains unverified because `php tests/run-tests.php --mysql-phase04` safely exits at the grant guard (`ALL PRIVILEGES` exceeds the allowlist).

The current workflow issues uniquely numbered offers, creates one seat reservation per application, locks the intake while counting active reservations, and records applicant acceptance/decline/expiry transactionally. Acceptance/decline/expiry release or retain the reservation as appropriate. Offers now require a separately approved `admit` decision.

Limitations: no offer template/editor/PDF renderer, draft/approval/reissue/withdrawal lifecycle, payment/deposit integration, complete ordered waitlist promotion workflow, or concurrency test against MySQL. SQLite tests do not validate MySQL `FOR UPDATE` semantics.

Gate: capacity concurrency safety remains **unverified on the production database engine**. Do not claim legal digital signature or external payment processing.
