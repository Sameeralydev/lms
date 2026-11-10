# Phase 05 SIS handoff contract

`admission_sis_handoffs` stores a unique correlation ID, application and accepted-offer references, status, approved snapshot, actor fields, and error summary/code. `AdmissionApplicationWorkflow::createSisHandoff` returns an existing record on repeat and transitions an accepted application to `ready_for_sis_handoff`.

The record is a queueable contract only. It does not create a student record, run an outbox worker, retry external processing, or include a verified-document/guardian evidence package. Handoff processing and authoritative SIS creation belong to Phase 05.

SQLite feature coverage is limited; MySQL uniqueness/transaction behavior and downstream consumer compatibility have not been verified. No Phase 05 code was started.
