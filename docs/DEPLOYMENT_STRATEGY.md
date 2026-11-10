# Deployment strategy

## Runtime layout

Deploy Laravel as one release with a web runtime and queue workers using the same release/configuration. Configure the web document root to the release public directory, never the repository root. Do not make storage or .env public and do not expose private storage through a symlink.

## Local XAMPP development

The verified local Laravel runtime is PHP 8.3.35 CLI at C:\php83\php.exe. Start it with C:\php83\php.exe artisan serve --host=127.0.0.1 --port=8000, or use php artisan serve only after confirming php --version reports 8.3 or later. Run npm run dev in a separate terminal.

XAMPP Apache 2.4.58 currently loads PHP 8.2.12 and has a document root outside Laravel public. It is not a supported runtime for this app. A project-local guard returns a generic 503 before Composer is loaded on PHP below 8.3. This guard prevents an unsupported runtime from exposing a PHP fatal trace; it does not make Apache compatible. No Apache or machine-wide PHP configuration was changed. Keep this project on Artisan server until a separate PHP 8.3+ HTTP runtime with a public-only document root is reviewed and verified.

## Required production services

- PHP 8.3+ with required extensions, Composer-installed dependencies, OPcache, and supported web SAPI.
- MySQL 8.4 with TLS, least-privilege runtime account, separate migration identity, backups, and reviewed forward migrations.
- Redis and PhpRedis only when production cache/session/queue configuration depends on them.
- TLS termination, explicit trusted-proxy configuration, centralized secret management, monitored logs/metrics, and tested backup/restore.

Do not run production with APP_DEBUG=true. Serve over HTTPS and use Secure session cookies. Keep diagnostic tokens secret and allow only trusted monitoring clients to call detailed readiness endpoints.

## Filesystem permissions

On Linux, make source read-only to the web user and grant write access only to storage and bootstrap/cache for the service account. On Windows, use ACLs that grant the web service identity minimum required permissions. No machine-wide permissions were changed in this workspace.