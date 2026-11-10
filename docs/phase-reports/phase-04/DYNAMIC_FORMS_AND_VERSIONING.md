# Dynamic forms and versioning

`AdmissionFormDefinition` validates a structured field/section definition and rejects duplicate keys and invalid conditional references. The workflow stores a form-version ID with each application, validates saved answers against that version, and creates revision snapshots on draft save and submission. The public application page supports save-before-submit and resume for the stored version.

The foundation is not the complete requested form builder: accessible drag-and-drop ordering, full field-type coverage, preview/history UI, retirement controls, and comprehensive version-immutability/browser tests are not delivered. Arbitrary executable conditions are not supported.

Evidence: `AdmissionsFoundationTest::test_form_definition_rejects_duplicate_keys_and_unknown_conditional_references` and draft/submission tests pass on SQLite. MySQL verification remains blocked because the authenticated dedicated Phase 04 test account has schema-scoped `ALL PRIVILEGES`, which fails the exact 11-privilege preflight. The dedicated schema itself was read-only verified empty; no migration ran.
