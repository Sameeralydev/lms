import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const themes = [
    'executive-navy',
    'academic-emerald',
    'modern-indigo',
    'warm-ivory',
    'graphite-dark',
    'midnight-violet',
] as const;

type RoleCase = {
    role: string;
    email?: string;
    password?: string;
    path: string;
    heading: string;
    requiredNavigation: string[];
    requiredMetric: string;
    actionPath: string;
};

async function signIn(page: Page, email?: string, password?: string): Promise<void> {
    if (!email || !password) throw new Error('Phase 15 workspace matrix fixture credentials are missing.');
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(workspaces|platform\/dashboard|t\/[^/]+\/dashboard)$/);
}

test('Phase 15 authenticated dashboards persist all six themes across supported roles', async ({
    browser,
}, testInfo) => {
    test.setTimeout(900_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    if (!tenant) throw new Error('The guarded Phase 15 institution fixture is missing.');
    const tenantRoot = `/t/${tenant}`;
    const roles: RoleCase[] = [
        {
            role: 'platform-super-administrator',
            email: process.env.E2E_ADMIN_EMAIL,
            password: process.env.E2E_ADMIN_PASSWORD,
            path: '/platform/dashboard',
            heading: 'Platform overview',
            requiredNavigation: ['Institutions', 'Operators', 'Audit history'],
            requiredMetric: 'Active tenants',
            actionPath: '/platform/tenants',
        },
        {
            role: 'institution-administrator',
            email: process.env.E2E_REGISTRATION_OWNER_EMAIL,
            password: process.env.E2E_REGISTRATION_OWNER_PASSWORD,
            path: `${tenantRoot}/dashboard`,
            heading: 'Institution administration',
            requiredNavigation: ['Institution profile', 'Campuses', 'Academics'],
            requiredMetric: 'Campuses in scope',
            actionPath: `${tenantRoot}/institution`,
        },
        {
            role: 'campus-administrator',
            email: process.env.E2E_CAMPUS_ADMIN_EMAIL,
            password: process.env.E2E_CAMPUS_ADMIN_PASSWORD,
            path: `${tenantRoot}/dashboard`,
            heading: 'Campus administration',
            requiredNavigation: ['Institution profile', 'Campuses'],
            requiredMetric: 'Campuses in scope',
            actionPath: `${tenantRoot}/campuses`,
        },
        {
            role: 'academic-leadership',
            email: process.env.E2E_SCHEDULING_REVIEWER_EMAIL,
            password: process.env.E2E_SCHEDULING_REVIEWER_PASSWORD,
            path: `${tenantRoot}/dashboard`,
            heading: 'Academic administration',
            requiredNavigation: ['Academics', 'Curriculum', 'Timetables'],
            requiredMetric: 'Active courses',
            actionPath: `${tenantRoot}/academics/curriculum`,
        },
        {
            role: 'instructor',
            email: process.env.E2E_REGISTRATION_INSTRUCTOR_EMAIL,
            password: process.env.E2E_REGISTRATION_INSTRUCTOR_PASSWORD,
            path: `${tenantRoot}/dashboard`,
            heading: 'Instructor workspace',
            requiredNavigation: ['Instructor schedule', 'Course delivery', 'Assignment management'],
            requiredMetric: 'Assigned sections',
            actionPath: `${tenantRoot}/instructor/schedule`,
        },
        {
            role: 'examination-controller',
            email: process.env.E2E_EXAMINATION_CONTROLLER_EMAIL,
            password: process.env.E2E_EXAMINATION_CONTROLLER_PASSWORD,
            path: `${tenantRoot}/dashboard`,
            heading: 'Examination controller workspace',
            requiredNavigation: ['Examinations', 'Result management'],
            requiredMetric: 'Published exam sessions',
            actionPath: `${tenantRoot}/academics/examinations`,
        },
        {
            role: 'examiner',
            email: process.env.E2E_EXAMINER_EMAIL,
            password: process.env.E2E_EXAMINER_PASSWORD,
            path: `${tenantRoot}/dashboard`,
            heading: 'Examiner workspace',
            requiredNavigation: ['Examiner marking'],
            requiredMetric: 'Assigned scripts to mark',
            actionPath: `${tenantRoot}/academics/examinations/marking`,
        },
        {
            role: 'invigilator',
            email: process.env.E2E_EXAMINATION_INVIGILATOR_EMAIL,
            password: process.env.E2E_EXAMINATION_INVIGILATOR_PASSWORD,
            path: `${tenantRoot}/dashboard`,
            heading: 'Invigilator workspace',
            requiredNavigation: ['My examination duties'],
            requiredMetric: 'Assigned invigilation duties',
            actionPath: `${tenantRoot}/examination-duties`,
        },
        {
            role: 'student',
            email: process.env.E2E_REGISTRATION_STUDENT_EMAIL,
            password: process.env.E2E_REGISTRATION_STUDENT_PASSWORD,
            path: `${tenantRoot}/dashboard`,
            heading: 'Learner workspace',
            requiredNavigation: ['Course registration', 'My assignments', 'My attendance'],
            requiredMetric: 'Confirmed registrations',
            actionPath: `${tenantRoot}/registration`,
        },
        {
            role: 'guardian',
            email: process.env.E2E_GUARDIAN_EMAIL,
            password: process.env.E2E_GUARDIAN_PASSWORD,
            path: `${tenantRoot}/dashboard`,
            heading: 'Family attendance',
            requiredNavigation: ['Family attendance'],
            requiredMetric: 'Linked learners',
            actionPath: `${tenantRoot}/attendance/guardian`,
        },
        {
            role: 'registrar',
            email: process.env.E2E_REGISTRAR_EMAIL,
            password: process.env.E2E_REGISTRAR_PASSWORD,
            path: `${tenantRoot}/dashboard`,
            heading: 'Registrar workspace',
            requiredNavigation: ['Student registry', 'Enrollment administration'],
            requiredMetric: 'Open course offerings',
            actionPath: `${tenantRoot}/sis`,
        },
        {
            role: 'admissions-officer',
            email: process.env.E2E_ADMISSIONS_OFFICER_EMAIL,
            password: process.env.E2E_ADMISSIONS_OFFICER_PASSWORD,
            path: `${tenantRoot}/dashboard`,
            heading: 'Admissions workspace',
            requiredNavigation: ['Admissions'],
            requiredMetric: 'Admission intakes',
            actionPath: `${tenantRoot}/admissions`,
        },
    ];

    const evidenceRoot = resolve(process.cwd(), 'test-results', 'phase15-role-dashboard-theme-matrix');
    mkdirSync(evidenceRoot, { recursive: true });
    const results: Array<{
        role: string;
        screen: string;
        theme: string;
        expected: string;
        observed: string;
        result: 'PASS';
        screenshot: string;
    }> = [];

    for (const roleCase of roles) {
        const context = await browser.newContext({
            viewport: { width: 1280, height: 800 },
            reducedMotion: 'reduce',
        });
        const page = await context.newPage();
        try {
            await signIn(page, roleCase.email, roleCase.password);
            const response = await page.goto(roleCase.path);
            expect(response?.status(), `${roleCase.role} dashboard response`).toBe(200);
            await expect(page.getByRole('heading', { level: 1, name: roleCase.heading })).toBeVisible();
            for (const label of roleCase.requiredNavigation) {
                await expect(
                    page
                        .getByRole('navigation', { name: 'Workspace' })
                        .getByRole('link', { name: label, exact: true }),
                ).toBeVisible();
            }
            await expect(page.getByRole('main')).not.toBeEmpty();
            if (roleCase.role === 'platform-super-administrator') {
                await expect(page.getByText(roleCase.requiredMetric, { exact: true })).toBeVisible();
            } else {
                await expect(
                    page
                        .getByLabel('Current workspace metrics')
                        .getByText(roleCase.requiredMetric, { exact: true }),
                ).toBeVisible();
            }

            const actionResponse = await page.goto(roleCase.actionPath);
            expect(actionResponse?.status(), `${roleCase.role} primary dashboard action`).toBe(200);
            await page.goto(roleCase.path);
            await expect(page.getByRole('heading', { level: 1, name: roleCase.heading })).toBeVisible();

            for (const theme of themes) {
                await page.getByLabel('Choose theme').selectOption(theme);
                await expect(page.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
                const axe = await new AxeBuilder({ page })
                    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
                    .analyze();
                expect(axe.violations, `${roleCase.role} dashboard ${theme}`).toEqual([]);

                await page.getByRole('link', { name: 'Skip to content' }).focus();
                await page.keyboard.press('Tab');
                expect(await page.evaluate(() => document.activeElement !== document.body)).toBe(true);
                const screenshot = `${roleCase.role}--${theme}.png`;
                await page.screenshot({ path: resolve(evidenceRoot, screenshot), fullPage: true });

                await page.setViewportSize({ width: 390, height: 844 });
                const dimensions = await page.evaluate(() => ({
                    viewport: document.documentElement.clientWidth,
                    content: document.documentElement.scrollWidth,
                }));
                expect(dimensions.content, `${roleCase.role} ${theme} mobile reflow`).toBeLessThanOrEqual(
                    dimensions.viewport,
                );
                await page.setViewportSize({ width: 1280, height: 800 });
                await page.reload();
                await expect(page.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
                await expect(page.getByRole('heading', { level: 1, name: roleCase.heading })).toBeVisible();
                results.push({
                    role: roleCase.role,
                    screen: 'dashboard',
                    theme,
                    expected: roleCase.heading,
                    observed: roleCase.heading,
                    result: 'PASS',
                    screenshot,
                });
            }
        } finally {
            await context.close();
        }
    }

    writeFileSync(
        resolve(evidenceRoot, 'results.json'),
        JSON.stringify({ generatedAt: new Date().toISOString(), cases: results }, null, 2),
    );
    expect(results).toHaveLength(roles.length * themes.length);
    testInfo.annotations.push({
        type: 'matrix',
        description: `${results.length} authenticated role-screen-theme cells; results.json and screenshot per cell`,
    });
});
