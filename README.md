# TechMentors.PK

Phase 02 adds a working platform control plane, institution workspaces, tenant isolation, memberships, role-based access control, domain verification, audit events, and white-label themes on the Phase 01 Laravel and React/Inertia foundation. Student, academic, learning, assessment, finance, HR, and payroll workflows remain in later phases.

## Local setup

Prerequisites: PHP 8.3+, Composer 2, Node.js 24, npm, and MySQL 8.4 for the development and isolated test databases.

1. Install PHP dependencies with composer install.
2. Copy .env.example to .env and configure the confirmed `techmentors_lms_dev` database and dedicated development account. Keep credentials local.
3. Generate the local application key with php artisan key:generate.
4. Install frontend dependencies with npm ci and build with npm run build.
5. Start Laravel using PHP 8.3+ with php artisan serve --host=127.0.0.1 --port=8000.
6. In a second terminal, run `npm run dev` while changing frontend code. The production build is sufficient for the Laravel server without the Vite dev server.

In this workspace, the supported PHP executable is `C:\php83\php.exe` (8.3.35). Confirm that `php --version` is at least 8.3 before starting Artisan. XAMPP Apache currently uses PHP 8.2.12 and must not serve this app; the local Laravel server uses PHP 8.3 CLI.

`composer setup` installs dependencies, prepares `.env`, generates a local key, and builds assets. It does not run migrations. The Phase 02 migrations are applied to the confirmed local database. On a new checkout, inspect database identity and migration status before running `php artisan migrate`. Create the first platform administrator with `php artisan platform:bootstrap-admin operator@example.org`; the command requires explicit confirmation and reads a password interactively rather than using a default credential.

## Automated tests

`composer test` uses the isolated MySQL test configuration in ignored `.env.testing`. Run `composer test -- --sqlite` to execute the same suite against in-memory SQLite. The guarded runner refuses the development database, production targets, unrelated databases, root, the app account, `DB_URL` overrides, remote MySQL, and file-backed SQLite. Never run migration tests against `techmentors_lms_dev`.

The dedicated `techmentors_lms_test` schema and `techmentors_test` account are verified. Keep `.env.testing` private and ignored. See the Phase 02 test and database reports for current evidence.

## Quality commands

- composer validate --strict
- composer check-platform-reqs
- composer audit --locked
- composer format:check
- composer analyse
- composer test
- `npm.cmd audit --audit-level=low` on Windows
- npm run format:check
- npm run lint
- npm run typecheck
- npm run test:unit
- npm run build
- npx playwright install chromium (first run)
- npm run test:e2e

Architecture, database, security, UI, testing, performance, deployment, and phase reports are in docs/.
