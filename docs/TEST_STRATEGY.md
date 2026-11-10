# Test strategy

## Tools and responsibilities

- PHPUnit 12 for Laravel unit and feature tests.
- Composer test defaults to SQLite :memory: and loads ignored .env.testing when present for the isolated MySQL integration run.
- Vitest, jsdom, and Testing Library for React component behavior and accessible semantics.
- Playwright Chromium for Laravel/Inertia rendering, Vite assets, keyboard operation, mobile/desktop layout, sensitive-file paths, and axe accessibility smoke checks.
- Larastan/PHPStan, TypeScript, Laravel Pint, ESLint, and Prettier provide analysis and formatting gates.
- Composer and npm audits scan locked dependencies.

## Test database guard

The Composer test runner validates the effective test environment before booting Artisan. Its allowed targets are SQLite :memory: or MySQL schema techmentors_lms_test on 127.0.0.1:3306 using either the dedicated local test account or the disposable CI account. It rejects DB_URL, development/production schemas, techmentors_app, root, unlisted accounts, remote hosts, unexpected ports, file-backed SQLite, and non-testing APP_ENV. PHPUnit also runs tests/bootstrap.php for direct PHPUnit/Artisan invocation. The CI unit/feature job explicitly selects SQLite memory; its separate disposable MySQL 8.4 job uses a scoped service account.

Do not run RefreshDatabase against the development database. Do not use migrate:fresh or db:wipe against development or production.

## Local commands

Run composer test for the default in-memory SQLite suite. The ignored .env.testing selects the isolated MySQL integration target; the test identity, scoped grants, and empty schema were verified read-only before migrations. Run composer test for the MySQL suite and explicitly set SQLite memory variables when running the fallback suite. Run php artisan serve with PHP 8.3+ and npm run dev in separate terminals. Browser tests start Laravel on 127.0.0.1:8017 and require a production Vite build to be available.

## CI

The GitHub workflow defines PHP 8.3, Node 24, MySQL 8.4, dependency audits, PHP analysis, frontend checks, backend tests, build, and browser tests. The local workflow configuration and corresponding local checks were reviewed; no hosted result is claimed.

## Evidence policy

Reports preserve exact command, exit status, test counts, and infrastructure blocks. An unavailable command or database is NOT RUN or BLOCKED, never PASS. Accessibility automation is a smoke check and does not replace manual assistive-technology review.
