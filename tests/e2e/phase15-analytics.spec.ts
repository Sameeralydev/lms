import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

async function signIn(page: Page, email?: string, password?: string): Promise<void> {
    if (!email || !password) throw new Error('Guarded Phase 15 browser fixture credentials are missing.');
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(workspaces|t\/[^/]+\/dashboard)$/);
}

test('authorized registrar filters real academic analytics, exports aggregate metrics, and uses all themes', async ({
    browser,
}) => {
    test.setTimeout(240_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    if (!tenant) throw new Error('Guarded Phase 15 analytics tenant fixture is missing.');
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const page = await context.newPage();
    await signIn(page, process.env.E2E_REGISTRATION_OWNER_EMAIL, process.env.E2E_REGISTRATION_OWNER_PASSWORD);
    await page.goto(`/t/${tenant}/dashboard`);
    await expect(page.getByRole('heading', { name: 'Institution administration' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Gradebooks', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Assessment analytics', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Attendance reports', exact: true })).toBeVisible();
    const url = `/t/${tenant}/academics/analytics`;
    const analyticsResponse = await page.goto(url);
    expect(analyticsResponse?.status(), `Analytics route response at ${page.url()}`).toBe(200);
    await expect(page.getByRole('heading', { name: 'Academic analytics' })).toBeVisible();
    await expect(page.getByLabel('Academic KPI summary').getByText('Active students')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Confirmed enrollment activity' })).toBeVisible();
    await expect(page.getByRole('table', { name: 'Daily confirmed enrollment activity' })).toBeAttached();

    await page.getByLabel('From', { exact: true }).fill('2026-10-01');
    await page.getByLabel('To', { exact: true }).fill('2026-11-11');
    await page.getByLabel(/Metrics/).selectOption('attendance.rate');
    await page.getByRole('button', { name: 'Apply filters' }).click();
    await expect(page).toHaveURL(/from=2026-10-01/);
    await expect(page.getByLabel('Academic KPI summary').getByText('Attendance rate')).toBeVisible();
    await expect(page.getByLabel('Academic KPI summary').getByText('Active students')).toHaveCount(0);
    const exportHref = await page.getByRole('link', { name: 'Export CSV' }).getAttribute('href');
    expect(exportHref).toBeTruthy();
    const exportResponse = await page.request.get(exportHref!);
    expect(exportResponse.status()).toBe(200);
    expect(exportResponse.headers()['content-type']).toContain('text/csv');
    expect(await exportResponse.text()).toContain('attendance.rate');
    expect(await exportResponse.text()).not.toContain('students.active');

    await page.getByLabel('Data source').selectOption('enrollments');
    await page.getByLabel('Measure').selectOption('unique_learners');
    await page.getByLabel('Group by 1').selectOption('status');
    await page.getByRole('button', { name: 'Run report' }).click();
    await expect(page.getByLabel('Measure')).toHaveValue('unique_learners');
    await expect(page.getByRole('region', { name: 'Report results' })).toBeVisible();
    await expect(page.getByText('No records match this report configuration.')).toBeVisible();
    await page.getByLabel('Data source').selectOption('admissions');
    await expect(page.getByLabel('Measure')).toHaveValue('records');
    await page.getByLabel('Group by 1').selectOption('status');
    await page.getByLabel('Group by 2').selectOption('campus');
    await page.getByLabel('Search grouped values').fill('phase15-no-such-admissions-group');
    await page.getByRole('button', { name: 'Run report' }).click();
    await expect(page).toHaveURL(/dataset=admissions/);
    await expect(page.getByText('No records match this report configuration.')).toBeVisible();
    const reportExportHref = await page.getByRole('link', { name: 'Export report CSV' }).getAttribute('href');
    expect(reportExportHref).toBeTruthy();
    const reportExport = await page.request.get(reportExportHref!);
    expect(reportExport.status()).toBe(200);
    expect(await reportExport.text()).toContain('status,campus,Applications');
    expect(await reportExport.text()).not.toContain('applicant');
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Your recent report exports' })).toBeVisible();
    await expect(page.getByRole('table', { name: 'Your last ten grouped report CSV exports' })).toContainText(
        'admissions',
    );

    await page.getByLabel('Report name').fill('Phase 15 browser report');
    await page.getByRole('button', { name: 'Save current filters' }).click();
    const savedReport = page.getByRole('link', { name: 'Phase 15 browser report', exact: true });
    await expect(savedReport).toBeVisible();
    await savedReport.click();
    await expect(page).toHaveURL(/metrics%5B0%5D=attendance.rate|metrics\[0\]=attendance.rate/);
    await expect(page).toHaveURL(/dataset=admissions/);
    await expect(page.getByLabel('Academic KPI summary').getByText('Attendance rate')).toBeVisible();
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Phase 15 browser report', exact: true })).toHaveCount(0);

    const themes = [
        'executive-navy',
        'academic-emerald',
        'modern-indigo',
        'warm-ivory',
        'graphite-dark',
        'midnight-violet',
    ];
    const evidencePath = resolve(process.cwd(), 'test-results', 'phase15-analytics-theme-evidence');
    mkdirSync(evidencePath, { recursive: true });
    for (const theme of themes) {
        await page.getByLabel('Choose theme').selectOption(theme);
        await expect(page.locator('[data-theme]')).toHaveAttribute('data-theme', theme, { timeout: 30_000 });
        const axe = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(axe.violations, `Phase 15 analytics ${theme}`).toEqual([]);
        await page.screenshot({ path: resolve(evidencePath, `${theme}.png`), fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        const overflow = await page.evaluate(() => ({
            width: document.documentElement.scrollWidth,
            offenders: [...document.querySelectorAll<HTMLElement>('*')]
                .filter((element) => element.getBoundingClientRect().right > window.innerWidth + 1)
                .slice(0, 5)
                .map((element) => ({
                    tag: element.tagName,
                    className: element.className,
                    text: element.innerText.slice(0, 50),
                    right: element.getBoundingClientRect().right,
                })),
        }));
        expect(
            overflow.width,
            `${theme} mobile overflow: ${JSON.stringify(overflow.offenders)}`,
        ).toBeLessThanOrEqual(390);
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.getByRole('link', { name: 'Skip to content' }).focus();
        await page.keyboard.press('Tab');
        expect(await page.evaluate(() => document.activeElement !== document.body)).toBe(true);
        await page.reload();
        await expect(page.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
    }
    await context.close();
});

test('learner role cannot open institution academic analytics', async ({ browser }) => {
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    if (!tenant) throw new Error('Guarded Phase 15 analytics tenant fixture is missing.');
    const context = await browser.newContext();
    const page = await context.newPage();
    await signIn(
        page,
        process.env.E2E_REGISTRATION_STUDENT_EMAIL,
        process.env.E2E_REGISTRATION_STUDENT_PASSWORD,
    );
    await page.goto(`/t/${tenant}/dashboard`);
    await expect(page.getByRole('link', { name: 'Course registration', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Examination operations', exact: true })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Official academic records', exact: true })).toHaveCount(0);
    const response = await page.goto(`/t/${tenant}/academics/analytics`);
    expect(response?.status()).toBe(403);
    await expect(page.getByRole('heading', { name: '403', exact: true })).toBeVisible();
    await context.close();
});
