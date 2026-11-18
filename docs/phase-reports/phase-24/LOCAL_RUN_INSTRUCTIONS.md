# Phase 24 — Local Run Instructions

These instructions use the isolated integration worktree and do not change XAMPP, Apache, MySQL services, or other worktrees.

## Current local application

- URL: `http://127.0.0.1:8000`
- Runtime: PHP 8.3.35 CLI development server.
- Health checks observed: `/` 200, `/login` 200, `/health/live` 200 JSON.
- `/internal/health/ready` returned 404 without its required internal authorization; this was not counted as a readiness pass.
- The private local `.env` and SQLite file are ignored. Never copy credentials from local environment files into reports or source control.

## Start the backend

From `C:\xampp\htdocs\lms-phase24`:

```powershell
C:\php83\php.exe artisan serve --host=127.0.0.1 --port=8000
```

The server currently serves the compiled Vite assets. Do not start Vite in hot mode at the same time as a test expecting built assets unless the test configuration explicitly targets the Vite server; `public/hot` can redirect asset loading to that development server.

## Frontend and tests

```powershell
npm.cmd ci
npm.cmd run build
npm.cmd run test:unit
npm.cmd run test:e2e
```

For backend checks on the local isolated SQLite environment:

```powershell
C:\php83\php.exe tests\run.php --phase=all --profile=sqlite --no-report
```

Use the project's guarded MySQL runner only when its authorized disposable test database is available and preflight has confirmed identity, grants, ownership, and migration state. Never point the runner at the application development schema.
