# Phase 20 requirements discovery

## Source review

The checkout has no approved Phase 20 specification, roadmap entry, acceptance matrix, or prior library implementation. `docs/MODULE_BOUNDARIES.md` identifies library as part of the planned HR and Operations boundary and calls for separate privacy and authorization. Phase 19's requirements discovery likewise lists library as a future operations module. The latest available upstream code remains `origin/Projects` at `02497d2`; Developer A's Phase 15, 17, and 18 work is not present. Developer B's Phase 19 staff foundation is in this branch's ancestry. Phase 16 is preserved in the local `stash@{0}` and is not available as integrated application services.

## Minimum coherent Phase 20 contract

With no approved feature-specific contract, this implementation covers the library workflows needed for a usable campus-scoped physical circulation system. It uses configurable branch policy values and does not infer institution-specific financial or licensing rules.

| Capability | State | Implementation |
|---|---|---|
| Library locations and borrowing policy | Implemented | Tenant/institution/campus-owned locations with configurable loan days, active-loan limits, renewals, and reservation limits. |
| Catalog | Implemented | Tenant-scoped title records, metadata, ISBN optionality, search, category filter, pagination, update, and archive guard. |
| Physical inventory | Implemented | Separately tracked copies with accession/barcode uniqueness, location, shelf, condition, and controlled status adjustments. |
| Membership | Implemented | A library membership links to an existing active tenant account; it can be suspended/reactivated and has optional expiry. |
| Checkout, return, renewal | Implemented | Transactional copy/member locks, configurable rules, attributed events, renewal limits, return condition and due date handling. |
| Reservation queue | Implemented | Queue order, hold allocation on copy return, 48-hour pickup expiry, cancellation, and queue promotion. |
| Member self-service | Implemented | Catalog availability, own active loans, renewals, reservations, and hold cancellation, with server-side ownership checks. |
| Admin dashboard/reporting | Implemented | Campus-scoped persisted title, loan, overdue and reservation counts plus catalog and circulation views. |
| Fine charging, digital licenses/files, acquisitions/procurement, barcode printing, email/SMS/push notifications | Not in minimum contract | No source requirement or provider/security contract exists in the repository. These workflows remain unimplemented pending approved policy and integration interfaces. |

## Security and integration boundaries

Every library entity is tenant scoped; the new foreign keys include tenant identity on relationships between library tables and institutional entities. Campus operators only access their assigned campus location. Member circulation checks the active tenant membership and library membership, member/location match, loan limit and copy status. Loan/return/renewal/hold transitions are transactional and write attributed history events. No database was inspected or changed during implementation, and the migration remains unapplied.

The module reuses `TenantMembership`, `User`, `Campus`, tenant context, the existing permission catalog, Inertia workspace layout and existing theme tokens. Phase 16's communications code is not in this branch; the current baseline only exposes assignment- and assessment-specific notification paths, so there is no generic notification contract to call. Live-provider integration and notification dispatch remain deferred until an approved communication contract is available.
