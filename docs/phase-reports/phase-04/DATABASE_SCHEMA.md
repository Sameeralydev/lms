# Admissions database schema

## 2026-10-23 MySQL verification update

The ignored `.env.phase04.testing` now authenticates, without exposing its contents. Read-only PDO identity is the intended Phase 04 test schema `techmentors_lms_phase04_test`, effective account `techmentors_phase04_test@127.0.0.1`, MySQL 8.4.11. The schema had zero objects. However, `SHOW GRANTS` reports schema-scoped `ALL PRIVILEGES` (18 privileges through `information_schema`, seven outside the documented 11-item test allowlist). The guarded runner exits 2 before ownership-manifest creation or migration. No MySQL schema was changed. Therefore Phase 04 migration DDL, foreign keys, transactions, uniqueness behavior, and seat locking remain unverified on MySQL. The older notes below about missing config/unknown identity are historical and superseded by this section; notes about no migration remain current.

Migration `2026_10_22_000007_create_admissions_foundation.php` declares 24 additive tables:

`admissions_settings`, `admission_intakes`, `admission_form_templates`, `admission_form_versions`, `admission_inquiries`, `admission_applicant_profiles`, `admission_applications`, `admission_application_revisions`, `admission_lifecycle_events`, `admission_eligibility_evaluations`, `admission_document_requirements`, `admission_documents`, `admission_document_versions`, `admission_document_reviews`, `admission_reviewer_assignments`, `admission_assessments`, `admission_decisions`, `admission_merit_lists`, `admission_merit_entries`, `admission_offers`, `admission_seat_reservations`, `admission_fee_obligations`, `admission_outbox_events`, and `admission_sis_handoffs`.

Recovery adds migration `2026_10_22_000009_add_admissions_document_quarantine.php`, adding `scan_status` to `admission_documents` and the appendable `admission_document_scan_events` table keyed by tenant, document version, and attempt. This migration is additive and has not been applied to MySQL.

Ownership uses `tenant_id`; key institution/intake/application references use composite tenant foreign keys. Scoped code/version/reference constraints and queue/list indexes are defined. The database driver remains authoritative for enforcement; SQLite does not prove MySQL collation, locking, or DDL behavior.

**Evidence:** the SQLite suite migrated the foundation and quarantine schema in memory. Read-only `php artisan migrate:status --database=mysql` used the `.env` default `techmentors_lms_dev`: 10 existing migrations are recorded, and Phase 04 migrations 000007–000009 are pending. Read-only `php tools/phase04-db-preflight.php` reported MySQL 8.4.11, schema `techmentors_lms_test`, 54 pre-existing tables, 10 recorded migrations, and populated academic/tenant fixtures. Neither MySQL schema was changed.

**Historical gate note (superseded 2026-10-23):** MySQL Phase 04 migration/integration remains BLOCKED, but the current cause is the effective account's overbroad `ALL PRIVILEGES` grant, not absent private configuration or populated test contents. DBA setup and verification steps are in `MYSQL_TEST_DATABASE_PROVISIONING.md`. Never reset either the development schema or another test schema as part of this suite.
