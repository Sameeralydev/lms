# Applicant document security

## Final scanner integration verification (2026-10-23)

The local private Phase 04 testing environment is configured to use ClamAV. The scanner integration was verified with ClamAV 1.5.4 and `sigtool --info` verification of main database 63, daily database 28162, and bytecode database 339. The daily signature version/timestamp matched CLI metadata and met the configured maximum age. The scanner passes the configured signature directory to `clamscan`; signature verification is performed before classifying files.

Real workflow evidence: clean PDF upload to private storage/quarantine, real scan, clean approval, authorized download, and audit event passed. The standard harmless EICAR signature was detected and rejected; it remained quarantined and unavailable for download. Cross-tenant retrieval was denied. Failure paths for missing executable/definitions, unsupported files, process failure, timeout, stale signatures and signature/CLI mismatch remain fail-closed and are covered by tests. The focused MySQL test passed 1 / 30 assertions; the full MySQL suite passed 122 / 934.

Production scanner service deployment, signature refresh scheduling, monitoring and alerting remain unverified. Keep production document uploads disabled until the production scanner and operational controls are installed and verified. No production scan or certification is claimed. The local executable path, signature path and all credentials are intentionally omitted from this tracked document.

## 2026-10-23 verification update

> Historical pre-install scanner status. The scanner-unavailable statements in this section and below describe the earlier inspection and are superseded by the final integration verification above.

The guardian workflow is now implemented and exercised separately; the stale statements below that guardian authority is not implemented are superseded. The local flow uses a verified guardian account plus an independent institution relationship-verification action, grants scoped read-only access, and supports audited revocation. It does not establish government identity or an external identity-provider verification.

The scanner is still not installed/configured: `clamscan`, `clamdscan`, `freshclam`, and `sigtool` were not available, so no real scan was performed. The adapter now fails closed unless `clamscan --version` returns a parseable engine version, signature database version, and build timestamp no older than the configured maximum (48 hours by default), with no more than five minutes of future clock skew. The queue event records the scanner/database identifier when metadata is available. Configure `ADMISSIONS_DOCUMENT_SCANNER=clamav`, the absolute `ADMISSIONS_CLAMAV_BINARY`, and `ADMISSIONS_CLAMAV_MAX_SIGNATURE_AGE_HOURS` privately after installing an approved ClamAV build with current definitions. Unit tests cover parsing and age policy; the real executable, its output, signature freshness, clean/infected outcomes, timeout, and queue path remain unverified. Documents remain quarantined and inaccessible until the current version has a clean result from a functioning scanner.

Admissions requirements and versioned file metadata are tenant scoped. Uploads use random private-disk paths outside the public document root. The upload request validates extension/MIME, size, and PDF/JPEG/PNG signatures and stores only a sanitized original basename for download presentation.

Recovery adds a version scan event and an explicit scan state (`pending`, `clean`, `rejected`, or `unavailable`) to the document. A queue job invokes the configured scanner adapter; the optional ClamAV adapter uses a process argument array and does not retain scanner output. The unavailable default records an unavailable result. Applicant/staff downloads and staff review actions are denied unless the current version is marked clean. A clean status must come from a functioning configured scanner; it is not inferred from MIME or staff review.

Local inspection did not find `clamscan` or `clamdscan`. The real scanner path was therefore not executed. Quarantined uploads are not available for review or download in this environment. No claim is made that uploaded files are malware free.

Still not implemented or verified: external identity-provider proof, external storage ACLs/isolation, retention/erasure/export policy, capacity monitoring, scanner deployment and retry operations under a real queue worker, and backup handling without exposing applicant files. MySQL schema behavior remains unverified because the Phase 04 test schema has an incomplete migration ledger and a guardian table that differs from its local migration definition; the schema was preserved.

**Operational gate:** keep applicant document uploads disabled in production until a real scanner is configured, run through the queue, and verified against clean and rejected fixtures. Keep guardian access limited to the implemented verified relationship workflow; this is not external identity proof.
