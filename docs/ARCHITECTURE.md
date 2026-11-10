# Architecture

## Foundation

TechMentors.PK starts as a Laravel 13 modular monolith. Laravel owns routing, request validation, authorization hooks, sessions, cache, queues, logging, migrations, and exception rendering. React and TypeScript render server-routed pages through Inertia. Vite builds the browser assets.

The Phase 01 application surface is intentionally small: a public Inertia shell, a generic liveness response, and a token-gated readiness response. There is no user login flow, student dashboard, tenant context, or domain API.

## Boundary rules

- Keep request handling in Laravel routes/controllers and application middleware.
- Add a module only when it has real behavior. Do not create empty controllers, services, models, or module trees.
- Future business modules belong under `app/Modules/<ModuleName>/` when they begin; shared infrastructure remains in Laravel's standard `app/`, `database/`, and `resources/` directories.
- Keep cross-module contracts small and versioned. Do not share another module's tables or internal service classes directly.
- Keep tenant selection and authorization in explicit request/application boundaries. A database filter alone is not an authorization model.
- Keep the web and worker processes on the same deployment release and configuration. Queue payloads should carry stable identifiers, not sensitive record snapshots.

## Request flow

```text
Browser -> Laravel web middleware -> route/controller -> Inertia response
       -> React page + shared shell -> Vite assets

Worker -> queued module handler -> module-owned persistence / integrations
```

The second flow is a future deployment convention. Phase 01 has no domain jobs.

## Trade-offs

A modular monolith keeps authentication, authorization, transactions, and deployment cohesive while business boundaries are still being validated. It avoids premature service-to-service calls and distributed data consistency. Modules can be extracted later only when operational evidence justifies the cost.

The app shell uses text branding because no final logo asset was supplied. Phase 01 defines semantic CSS tokens; it does not implement theme switching.
