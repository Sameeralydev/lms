# Technology stack

Versions below are the installed direct versions captured in `composer.lock` and `package-lock.json`. The lock files are authoritative for transitive dependencies.

| Area | Selected technology | Installed version |
| --- | --- | --- |
| Framework | Laravel | 13.38.0 |
| PHP runtime | PHP CLI | 8.3.35 |
| Composer | Composer | 2.10.3 |
| Server database target | MySQL Community Server | 8.4.11 greeting verified on 127.0.0.1:3306; app login/database identity unverified |
| Production cache / queue | Redis with PhpRedis driver | Redis service and PHP extension not installed locally |
| Frontend framework | React | 19.3.1 |
| Server-driven SPA bridge | Inertia React adapter | 3.9.1 |
| Laravel Inertia adapter | inertiajs/inertia-laravel | 3.5.1 |
| Language | TypeScript | 6.0.3 |
| CSS | Tailwind CSS | 4.3.3 |
| Bundler | Vite | 8.3.5 |
| Vite plugins | Laravel Vite plugin / React plugin / Tailwind Vite plugin | 3.2.0 / 6.1.2 / 4.3.3 |
| PHP tests | PHPUnit | 12.5.40 |
| PHP static analysis | Larastan / PHPStan | 3.13.0 / 2.3.1 |
| PHP formatting | Laravel Pint | 1.32.1 |
| PHP test support | Faker / Mockery / Collision | 1.24.1 / 1.6.15 / 8.9.5 |
| Laravel CLI tools | Pail / Tinker | 1.2.7 / 3.0.2 |
| Frontend unit tests | Vitest | 5.0.5 |
| Browser tests | Playwright | 1.64.1 |
| Accessibility smoke test | axe-core Playwright integration | 4.14.0 |
| Frontend lint | ESLint / @eslint/js / typescript-eslint | 10.13.0 / 10.0.1 / 8.72.0 |
| Formatting | Prettier | 3.9.10 |
| Frontend test support | Testing Library React / user-event / jest-dom / jsdom | 16.3.3 / 14.6.8 / 7.0.1 / 30.1.3 |
| Type definitions | React / React DOM / Node | 19.3.0 / 19.3.0 / 24.19.2 |
| Other build tooling | ESLint React hooks / ESLint React refresh / globals / Tailwind Prettier plugin | 7.1.1 / 0.5.7 / 17.13.0 / 0.8.1 |
| Local JavaScript runtime | Node.js / npm | 24.21.0 / 11.19.0 |

Laravel 13 requires PHP 8.3 or later. The official React starter stack uses React 19, TypeScript, Inertia 3, Tailwind 4, and Vite. The selected packages resolved against those peer requirements before installation. Composer and npm audit results are captured in the Phase 01 reports.

Laravel 13's minimum PHP requirement was checked against the official release documentation. The XAMPP bundled PHP CLI is 8.2.12 and is below that minimum. Apache loads the XAMPP PHP module from `C:/xampp/php`, so the current Apache path has not passed the Laravel boot check. Use the verified PHP 8.3 runtime for Artisan and configure any Apache/FastCGI deployment to use PHP 8.3+. No machine-wide PHP configuration was changed. XAMPP also contains a separate MariaDB 10.4.32 binary; the listener on port 3306 was confirmed as MySQL 8.4.11, but target schema identity could not be authenticated.

Registry freshness was checked on 2026-10-21. `composer outdated --direct` initially identified Inertia Laravel 3.5.1 as a compatible patch, which was applied and locked. `npm outdated --depth=0` reported only TypeScript 7 and Node type definitions 26, both newer major lines than the selected TypeScript 6 / Node 24 toolchain; they were held pending a compatibility review. Dependency security results are in the phase test report. Registry freshness and audit checks do not replace ongoing maintainer/release monitoring.
