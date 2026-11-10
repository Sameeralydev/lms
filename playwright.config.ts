import { defineConfig, devices } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { mkdirSync } from 'node:fs';
import { basename, dirname, resolve } from 'node:path';

const baseURL = 'http://127.0.0.1:8017';
const e2eFramework = resolve(process.cwd(), 'storage/framework');
const inheritedDatabase = process.env.E2E_DATABASE;
const e2eDatabase = inheritedDatabase
    ? resolve(inheritedDatabase)
    : resolve(e2eFramework, `phase06-e2e-${randomUUID()}.sqlite`);
const e2eRunId = /^phase06-e2e-([0-9a-f-]{36})\.sqlite$/i.exec(basename(e2eDatabase))?.[1] ?? '';
if (!e2eRunId || dirname(e2eDatabase) !== e2eFramework) {
    throw new Error('Playwright requires a unique Phase 06 SQLite file inside storage/framework.');
}
process.env.E2E_RUN_ID = e2eRunId;
process.env.E2E_DATABASE = e2eDatabase;
const e2eCache = resolve(process.cwd(), 'storage/framework', `phase04-e2e-cache-${process.pid}`);
const e2eSessions = resolve(process.cwd(), 'storage/framework/e2e-sessions');
const useClamAvForE2E = process.env.E2E_CLAMAV_ENABLED === 'true';
mkdirSync(e2eSessions, { recursive: true });

export default defineConfig({
    testDir: './tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    // E2E scenarios intentionally share one isolated database fixture and
    // persistent per-user preferences. Serialize them to avoid cross-test
    // login, theme, and workflow state races.
    fullyParallel: false,
    workers: 1,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL,
        trace: 'retain-on-failure',
        ...devices['Desktop Chrome'],
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: {
        command: 'node tests/e2e/start-server.mjs',
        url: `${baseURL}/health/live`,
        // Always launch the configured PHP 8.3 server so browser tests cannot
        // silently attach to a stale server using the development database.
        reuseExistingServer: false,
        timeout: 120_000,
        env: {
            APP_ENV: 'testing',
            APP_DEBUG: 'false',
            APP_URL: baseURL,
            E2E_TEST_MODE: 'true',
            E2E_DATABASE: e2eDatabase,
            E2E_RUN_ID: e2eRunId,
            CACHE_STORE: 'file',
            CACHE_FILE_STORE_PATH: e2eCache,
            CACHE_FILE_LOCK_PATH: e2eCache,
            QUEUE_CONNECTION: 'database',
            DB_QUEUE_RETRY_AFTER: '240',
            SESSION_DRIVER: 'file',
            SESSION_FILES_PATH: e2eSessions,
            MAIL_MAILER: 'array',
            ADMISSIONS_DOCUMENT_SCANNER: useClamAvForE2E ? 'clamav' : 'unavailable',
            ADMISSIONS_CLAMAV_BINARY: useClamAvForE2E ? (process.env.E2E_CLAMAV_BINARY ?? '') : '',
            ADMISSIONS_CLAMAV_SIGNATURE_DIRECTORY: useClamAvForE2E
                ? (process.env.E2E_CLAMAV_SIGNATURE_DIRECTORY ?? '')
                : '',
            ADMISSIONS_SIGTOOL_BINARY: useClamAvForE2E ? (process.env.E2E_SIGTOOL_BINARY ?? '') : '',
            ADMISSIONS_CLAMAV_TIMEZONE: process.env.E2E_CLAMAV_TIMEZONE ?? 'Asia/Karachi',
            ADMISSIONS_CLAMAV_MAX_SIGNATURE_AGE_HOURS: '48',
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: e2eDatabase,
            DB_URL: '',
        },
    },
});
