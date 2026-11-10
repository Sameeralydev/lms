# Architecture decisions

## ADR-001: Modular monolith

**Decision:** Start with one Laravel deployment and explicit module ownership.
**Reason:** The domain and operating model are not yet proven; this avoids distributed transactions and premature infrastructure.
**Consequence:** Module boundaries and ownership rules must be maintained in code review.

## ADR-002: Inertia, React, TypeScript, Tailwind, and Vite

**Decision:** Use Laravel's server-side routing with Inertia React pages and Vite assets.
**Reason:** This matches the required stack and keeps page authorization/routing in Laravel while supporting a reusable React UI.
**Consequence:** Future navigation and page props must not become a second authorization layer.

## ADR-003: MySQL for application data, isolated SQLite for tests

**Decision:** MySQL 8.4 is the development/production target; PHPUnit uses in-memory SQLite.
**Reason:** MySQL is the requested production database, while isolated in-memory tests are safe and deterministic.
**Consequence:** MySQL-specific migrations and query behavior must also be exercised against MySQL in CI. Local target verification is blocked until credentials are available.

## ADR-004: No business schema before domain decisions

**Decision:** Keep only Laravel's standard users, cache, and jobs foundation migrations.
**Reason:** Identity/operations plumbing is useful, but speculative student, course, tenant, finance, HR, or academic schemas would harden assumptions prematurely.
**Consequence:** The existing `User` model/table does not mean login, roles, permissions, or tenant isolation are implemented.

## ADR-005: Safe local fallbacks, Redis production configuration

**Decision:** Local defaults use file sessions/cache and sync queue. Production can use Redis for sessions/cache/queues with PhpRedis.
**Reason:** Redis is absent in this workstation, while the local shell should run without installing a service.
**Consequence:** Redis behavior and tenant isolation are not verified locally; production setup must install/configure Redis and PhpRedis.

## ADR-006: Semantic design tokens, no Phase 01 theme engine

**Decision:** Components use semantic tokens and a temporary text wordmark.
**Reason:** Future themes can remap tokens without coupling current components to theme-specific palettes; no final logo was supplied.
**Consequence:** Theme switching and six complete themes remain Phase 05 work.
