import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const screenshotDirectory = resolve(process.cwd(), 'docs/phase-reports/frontend-audit/screenshots');
const tenantSlug = process.env.E2E_REGISTRATION_TENANT_SLUG;

async function signIn(page: Page, email: string | undefined, password: string | undefined): Promise<void> {
    if (!email || !password)
        throw new Error('The isolated E2E setup did not provide a required role identity.');

    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(?:platform\/dashboard|workspaces)$/);
}

async function captureRole(
    browser: Browser,
    options: {
        email: string | undefined;
        password: string | undefined;
        path: string;
        heading: string;
        screenshot: string;
        expectedNavigation?: string[];
        forbiddenNavigation?: string[];
        expectedNonzeroMetrics?: string[];
    },
): Promise<void> {
    const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
    const page = await context.newPage();
    const pageErrors: string[] = [];
    page.on('pageerror', (error) => pageErrors.push(`${error.name}: ${error.message}`));

    try {
        await signIn(page, options.email, options.password);
        await page.goto(options.path);
        await expect(page.getByRole('heading', { level: 1, name: options.heading })).toBeVisible();
        for (const label of options.expectedNonzeroMetrics ?? []) {
            const value = page.getByText(label, { exact: true }).locator('xpath=..').locator('p').nth(1);
            await expect(value, `${options.heading} live metric: ${label}`).toHaveText(/[1-9]\d*/);
        }

        if (options.path.startsWith('/t/')) {
            const navigation = page.getByRole('navigation', { name: 'Workspace' });
            await expect(navigation.getByRole('link', { name: 'Overview', exact: true })).toBeVisible();
            for (const label of options.expectedNavigation ?? []) {
                await expect(navigation.getByRole('link', { name: label, exact: true })).toBeVisible();
            }
            for (const label of options.forbiddenNavigation ?? []) {
                await expect(navigation.getByRole('link', { name: label, exact: true })).toHaveCount(0);
            }
        } else if (options.path.startsWith('/platform/')) {
            const navigation = page.getByRole('navigation', { name: 'Workspace' });
            for (const label of options.expectedNavigation ?? []) {
                await expect(navigation.getByRole('link', { name: label, exact: true })).toBeVisible();
            }
        }

        mkdirSync(screenshotDirectory, { recursive: true });
        await page.screenshot({ path: resolve(screenshotDirectory, options.screenshot), fullPage: true });
        expect(pageErrors, `${options.heading} browser errors`).toEqual([]);
    } finally {
        await context.close();
    }
}

test('role dashboards render real workspace entry points with permission-aware navigation', async ({
    browser,
}) => {
    test.setTimeout(120_000);
    if (!tenantSlug) throw new Error('The E2E setup did not provision its isolated institution.');

    await captureRole(browser, {
        email: process.env.E2E_ADMIN_EMAIL,
        password: process.env.E2E_ADMIN_PASSWORD,
        path: '/platform/dashboard',
        heading: 'Platform overview',
        screenshot: 'platform-administration.png',
        expectedNavigation: ['Institutions', 'Operators', 'Audit history'],
    });
    await captureRole(browser, {
        email: process.env.E2E_REGISTRATION_OWNER_EMAIL,
        password: process.env.E2E_REGISTRATION_OWNER_PASSWORD,
        path: `/t/${tenantSlug}/dashboard`,
        heading: 'Institution administration',
        screenshot: 'institution-dashboard.png',
        expectedNavigation: ['Institution profile', 'Campuses', 'Members', 'Academics', 'Student registry'],
        expectedNonzeroMetrics: ['Campuses in scope', 'Memberships in scope'],
    });
    await captureRole(browser, {
        email: process.env.E2E_CAMPUS_ADMIN_EMAIL,
        password: process.env.E2E_CAMPUS_ADMIN_PASSWORD,
        path: `/t/${tenantSlug}/dashboard`,
        heading: 'Campus administration',
        screenshot: 'campus-dashboard.png',
        expectedNavigation: ['Institution profile', 'Campuses'],
        forbiddenNavigation: ['Members', 'Admissions', 'Student registry', 'Audit history'],
        expectedNonzeroMetrics: ['Campuses in scope'],
    });
    await captureRole(browser, {
        email: process.env.E2E_SCHEDULING_REVIEWER_EMAIL,
        password: process.env.E2E_SCHEDULING_REVIEWER_PASSWORD,
        path: `/t/${tenantSlug}/dashboard`,
        heading: 'Academic administration',
        screenshot: 'academic-administration.png',
        expectedNavigation: ['Academics', 'Curriculum', 'Timetables', 'Enrollment administration'],
        forbiddenNavigation: ['Institution profile', 'Members'],
        expectedNonzeroMetrics: ['Active courses'],
    });
    await captureRole(browser, {
        email: process.env.E2E_ADMISSIONS_OFFICER_EMAIL,
        password: process.env.E2E_ADMISSIONS_OFFICER_PASSWORD,
        path: `/t/${tenantSlug}/dashboard`,
        heading: 'Admissions workspace',
        screenshot: 'admissions-dashboard.png',
        expectedNavigation: ['Admissions'],
        forbiddenNavigation: ['Institution profile', 'Members', 'Student registry', 'Academics'],
    });
    await captureRole(browser, {
        email: process.env.E2E_REGISTRAR_EMAIL,
        password: process.env.E2E_REGISTRAR_PASSWORD,
        path: `/t/${tenantSlug}/dashboard`,
        heading: 'Registrar workspace',
        screenshot: 'registrar-dashboard.png',
        expectedNavigation: ['Student registry', 'Enrollment administration', 'Attendance operations'],
        forbiddenNavigation: ['Institution profile', 'Members', 'Admissions', 'Academics'],
        expectedNonzeroMetrics: ['Open course offerings'],
    });
    await captureRole(browser, {
        email: process.env.E2E_REGISTRATION_INSTRUCTOR_EMAIL,
        password: process.env.E2E_REGISTRATION_INSTRUCTOR_PASSWORD,
        path: `/t/${tenantSlug}/dashboard`,
        heading: 'Instructor workspace',
        screenshot: 'instructor-dashboard.png',
        expectedNavigation: [
            'Instructor schedule',
            'Course delivery',
            'Assignment management',
            'Attendance operations',
        ],
        forbiddenNavigation: ['Institution profile', 'Members', 'Admissions', 'Student registry'],
        expectedNonzeroMetrics: ['Assigned sections'],
    });
    await captureRole(browser, {
        email: process.env.E2E_REGISTRATION_STUDENT_EMAIL,
        password: process.env.E2E_REGISTRATION_STUDENT_PASSWORD,
        path: `/t/${tenantSlug}/dashboard`,
        heading: 'Learner workspace',
        screenshot: 'student-dashboard.png',
        expectedNavigation: ['Course registration', 'My assignments', 'My attendance'],
        forbiddenNavigation: [
            'Institution profile',
            'Members',
            'Admissions',
            'Student registry',
            'Assignment management',
        ],
        expectedNonzeroMetrics: ['Confirmed registrations'],
    });
    await captureRole(browser, {
        email: process.env.E2E_GUARDIAN_EMAIL,
        password: process.env.E2E_GUARDIAN_PASSWORD,
        path: `/t/${tenantSlug}/dashboard`,
        heading: 'Family attendance',
        screenshot: 'guardian-dashboard.png',
        expectedNavigation: ['Family attendance'],
        forbiddenNavigation: [
            'Institution profile',
            'Members',
            'Admissions',
            'Student registry',
            'My attendance',
        ],
        expectedNonzeroMetrics: ['Linked learners'],
    });
});

test('guardian dashboard connects to verified learner attendance and reflows on mobile', async ({
    browser,
}) => {
    test.setTimeout(60_000);
    if (!tenantSlug) throw new Error('The E2E setup did not provision its isolated institution.');
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();

    try {
        await signIn(page, process.env.E2E_GUARDIAN_EMAIL, process.env.E2E_GUARDIAN_PASSWORD);
        await page.goto(`/t/${tenantSlug}/attendance/guardian`);
        await expect(page.getByRole('heading', { level: 1, name: 'Family attendance' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Attendance Learner' })).toBeVisible();
        await page.screenshot({
            path: resolve(screenshotDirectory, 'guardian-linked-learners.png'),
            fullPage: true,
        });
        await page
            .getByRole('article')
            .filter({ hasText: 'Attendance Learner' })
            .getByRole('link', { name: 'View attendance' })
            .click();
        await expect(page.getByRole('heading', { name: 'Attendance for Attendance Learner' })).toBeVisible();
        await expect(page.getByRole('main').getByRole('heading', { name: 'Attendance rate' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Institution profile' })).toHaveCount(0);

        const axe = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(axe.violations).toEqual([]);
        const dimensions = await page.evaluate(() => ({
            content: document.documentElement.scrollWidth,
            viewport: document.documentElement.clientWidth,
        }));
        expect(dimensions.content).toBeLessThanOrEqual(dimensions.viewport);
        mkdirSync(screenshotDirectory, { recursive: true });
        await page.screenshot({
            path: resolve(screenshotDirectory, 'guardian-attendance-mobile.png'),
            fullPage: true,
        });
    } finally {
        await context.close();
    }
});

test('dashboard theme selection persists and remains accessible across six themes', async ({ browser }) => {
    test.setTimeout(90_000);
    if (!tenantSlug) throw new Error('The E2E setup did not provision its isolated institution.');
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    const themes = [
        'executive-navy',
        'academic-emerald',
        'modern-indigo',
        'warm-ivory',
        'graphite-dark',
        'midnight-violet',
    ];
    const canvasColors = new Set<string>();

    try {
        await signIn(
            page,
            process.env.E2E_REGISTRATION_OWNER_EMAIL,
            process.env.E2E_REGISTRATION_OWNER_PASSWORD,
        );
        await page.goto(`/t/${tenantSlug}/dashboard`);
        await expect(page.getByRole('heading', { name: 'Institution administration' })).toBeVisible();
        const themePicker = page.getByLabel('Choose theme');

        for (const theme of themes) {
            await themePicker.selectOption(theme);
            await expect(page.locator('[data-theme]').first()).toHaveAttribute('data-theme', theme);
            const canvas = await page
                .locator('[data-theme]')
                .first()
                .evaluate((element) => getComputedStyle(element).getPropertyValue('--color-canvas').trim());
            expect(canvas, `${theme} canvas token`).not.toBe('');
            canvasColors.add(canvas);

            const axe = await new AxeBuilder({ page })
                .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
                .analyze();
            expect(axe.violations, `${theme} dashboard accessibility`).toEqual([]);
            const dimensions = await page.evaluate(() => ({
                content: document.documentElement.scrollWidth,
                viewport: document.documentElement.clientWidth,
            }));
            expect(dimensions.content, `${theme} mobile reflow`).toBeLessThanOrEqual(dimensions.viewport);
            await page.reload();
            await expect(page.locator('[data-theme]').first()).toHaveAttribute('data-theme', theme);
        }

        expect(canvasColors.size).toBe(6);
        await page.screenshot({ path: resolve(screenshotDirectory, 'theme-switcher.png'), fullPage: true });
    } finally {
        await context.close();
    }
});
