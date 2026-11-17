# Phase 21 — SaaS and White-label Management

## Requirements discovery

No Phase 21 specification, acceptance criteria, or approved pricing/limits schedule exists in the repository. The approved architecture says the application uses shared-schema multi-tenancy and defers tenant billing. This implementation preserves that boundary: it does not invent prices, billing periods, payment collection, taxes, or default quotas.

| Capability | Discovery state | Phase 21 result |
| --- | --- | --- |
| Tenant provisioning and institution owner invitation | Implemented in the platform tenancy foundation | Reused without duplication |
| Tenant activation, suspension, and archive lifecycle | Implemented with audited state transitions | Reused without duplication |
| Tenant isolation and verified-domain resolution | Implemented with request-scoped membership, verified custom domains, and exact host binding | Preserved |
| Tenant branding and theme | Implemented with private logo storage, theme registry, colors, and branded workspace header | Reused; no new brand storage introduced |
| Domain ownership verification | Implemented using a DNS TXT challenge and primary-domain controls | Reused |
| Plans and feature entitlements | Missing | Added platform plan management and tenant plan assignment |
| Enforcement of plan access | Missing | Added server-side entitlement middleware on mapped product route groups |
| Subscription/payment billing | Explicitly deferred in architecture; no approved contract available | Not introduced |
| Plan limits/quotas | No approved numeric defaults defined; campus and staff creation flows exist | Optional active-campus and active-staff limits are platform-configurable and enforced at create/reactivate boundaries; no default quota or historical deletion is introduced |
| Tenant migration/data export, custom deployment provisioning, vanity host provisioning | Not specified by a Phase 21 contract | No unsupported workflows added |

## Implemented behavior

- Platform operators with explicit plan/subscription permissions can create and edit plans and assign or pause access per tenant.
- Plans contain named feature entitlements and optional active-campus/active-staff limits; the application does not imply prices or payment status.
- A plan with tenant assignments cannot have its identity, description, features, or limits silently rewritten. Operators create a new plan version and move tenants through the assignment workflow.
- Tenant plan assignment is transactionally locked, actor-attributed, and recorded in immutable history plus platform audit events.
- Tenants without an assignment retain legacy access. Existing provisioning, custom-domain, branding, status, and archived data remain unchanged.
- Paused assignments deny mapped module routes server-side. Route permission checks continue to apply independently.
- Migrations are additive and were not executed because the configured database was not independently verified as an isolated, authorized Developer B schema.

## Integration boundary

Plan feature and supported limit keys are centralized in `config/saas.php`. Route groups use their current Laravel route names. Future modules must add explicit quota keys and write-boundary checks before additional limits are offered. No billing provider or external provisioning integration is configured.

## Development status

**Development implementation added; QA PENDING.** The project CLI PHP version is older than the repository's declared Laravel PHP requirement, so Artisan-based route and migration execution is unavailable in this environment. See the centralized deferred QA backlog before integration.
