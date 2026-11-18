# Phase 18 Focused Security Review

Updated: 2026-11-18

## Reviewed controls

- Collection, verification, allocation, receipt, refund, report, and portal routes enforce their permission middleware; domain workflows repeat sensitive permission checks.
- Queries are scoped to tenant context, institution, and campus. Student, invoice, payment, and refund relationships are checked before mutation or disclosure.
- Confirmed cash and verified bank payments use transaction boundaries; allocation locks target invoices and rejects foreign student/institution/campus/currency values and amounts exceeding outstanding balance.
- Bank transfer submitters cannot verify their own transaction. Refund requester cannot approve; requester and approver cannot execute.
- Idempotency keys are bound to payment/refund payloads, and unique constraints prevent duplicate logical records.
- Guardian access to invoices, receipts, and payment/refund notifications requires verified, non-revoked guardian authority and explicit `can_view_financial_records`; generic profile access is not sufficient.
- Receipt and reconciliation exports return private/no-store responses; CSV fields sourced from user-visible references and identities use formula-safe escaping. Export is audited.
- Audit/event metadata excludes secrets and payment credentials. No card data or payment-provider credentials are stored or logged.
- No online callback endpoint or fake provider-success path exists.

## Focused test evidence

`php tests/run.php --phase=18 --profile=sqlite --no-report` — 6 passed / 134 assertions. Covered foreign invoice allocation rejection with no partial mutation, submitter self-review denial, segregated refund actors, guardian grant/revocation, idempotent payment replay, and scoped report export.

## Findings and limitations

- No MySQL concurrency execution or broader penetration/browser authorization campaign was run; this is pending evidence, not a pass.
- External payment-provider security is not assessed because no provider is selected or implemented.
- Refund execution currently records a manual payout reference after operator action. It does not verify a bank payout independently; a product-approved payout connector/process is needed before claiming automated disbursement.
- Internal collection report does not authenticate bank statements or prove external settlement.
- The audit identified no known critical issue in the focused tested workflows. This is not a formal certification or product-wide security sign-off.

## Verification refresh — 2026-11-18

Latest focused security/workflow run: 6 passed / 140 assertions. It additionally verifies repeat refund execution does not add another reversal, the confirmation event retains its original allocation/unapplied receipt snapshot, and refund status notifications are emitted for request, approval, and execution. Focused PHPStan passed with no errors. MySQL concurrency and browser isolation remain unverified.
