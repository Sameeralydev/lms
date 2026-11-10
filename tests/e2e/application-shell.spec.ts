import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const screenshotDirectory = resolve(process.cwd(), 'docs/phase-reports/frontend-audit/screenshots');

test('the public shell renders through Laravel, Inertia, and built Vite assets', async ({ page }) => {
    const browserErrors: string[] = [];
    page.on('console', (message) => {
        if (message.type() === 'error') {
            browserErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => browserErrors.push(`${error.name}: ${error.message}`));

    const response = await page.goto('/');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveTitle('Institution operations and learning · TechMentors.PK');
    await expect(
        page.getByRole('heading', { name: 'Bring institution operations and learning into one workspace.' }),
    ).toBeVisible();
    await expect(page.getByRole('link', { name: 'Sign in to TechMentors' })).toHaveAttribute(
        'href',
        '/login',
    );
    await expect(page.getByRole('link', { name: 'Open an institution workspace' })).toHaveAttribute(
        'href',
        '/workspaces',
    );
    await expect(page.getByText(/Platform foundation|Current scope|02 \/ 24|Phase 01/i)).toHaveCount(0);
    await expect(page.locator('link[rel="stylesheet"][href*="/build/assets/"]')).toHaveCount(1);
    await expect(page.locator('script[type="module"][src*="/build/assets/"]')).toHaveCount(1);
    expect(browserErrors).toEqual([]);
    mkdirSync(screenshotDirectory, { recursive: true });
    await page.screenshot({ path: resolve(screenshotDirectory, 'public-homepage.png'), fullPage: true });
});

test('public liveness stays generic and readiness stays protected over HTTP', async ({ request }) => {
    const liveness = await request.get('/health/live');
    expect(liveness.status()).toBe(200);
    expect(await liveness.json()).toEqual({ status: 'ok' });

    const readiness = await request.get('/internal/health/ready');
    expect(readiness.status()).toBe(404);
});

test('navigation remains keyboard accessible and adapts to a narrow viewport', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/');
    await expect(
        page.getByRole('heading', { name: 'Bring institution operations and learning into one workspace.' }),
    ).toBeVisible();
    await page.bringToFront();
    await page.evaluate(() => document.body.focus());

    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();

    const menuButton = page.getByRole('button', { name: 'Open navigation' });
    await expect(menuButton).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Primary' })).toBeHidden();
    await menuButton.click();
    await expect(page.getByRole('button', { name: 'Close navigation' })).toHaveAttribute(
        'aria-expanded',
        'true',
    );
    await expect(
        page.getByRole('navigation', { name: 'Primary' }).getByRole('link', { name: 'Capabilities' }),
    ).toBeVisible();
    mkdirSync(screenshotDirectory, { recursive: true });
    await page.screenshot({ path: resolve(screenshotDirectory, 'mobile-navigation.png'), fullPage: true });

    const widths = await page.evaluate(() => ({
        document: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(widths.document).toBeLessThanOrEqual(widths.viewport);
});

test('desktop layout shows the primary navigation without horizontal overflow', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/');

    await expect(page.getByRole('navigation', { name: 'Primary' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Open navigation' })).toBeHidden();

    const widths = await page.evaluate(() => ({
        document: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(widths.document).toBeLessThanOrEqual(widths.viewport);
});

test('the public shell has no axe accessibility violations', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);
    await expect(page).toHaveTitle('Institution operations and learning · TechMentors.PK');
    await expect(page.getByRole('main')).toBeVisible();

    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations).toEqual([]);
});

test('untrusted host headers are rejected over HTTP', async ({ request }) => {
    const response = await request.get('/', { headers: { Host: 'attacker.example' } });
    expect(response.status()).toBe(400);
    expect(await response.text()).not.toContain('attacker.example');
});

test('sensitive project files are outside the public document root', async ({ request }) => {
    const envFile = await request.get('/.env');
    const composerFile = await request.get('/composer.json');

    expect(envFile.status()).toBe(404);
    expect(composerFile.status()).toBe(404);
});

test('private storage and Inertia DevTools endpoints are disabled', async ({ request }) => {
    expect((await request.get('/storage/private/phase-01-canary.txt')).status()).toBe(404);
    expect((await request.put('/storage/private/phase-01-canary.txt')).status()).toBe(404);
    expect((await request.get('/_inertia/devtools/entries')).status()).toBe(404);
});
