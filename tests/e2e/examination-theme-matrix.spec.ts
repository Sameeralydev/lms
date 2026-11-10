import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const themes = [
    'executive-navy',
    'academic-emerald',
    'modern-indigo',
    'warm-ivory',
    'graphite-dark',
    'midnight-violet',
] as const;

type WorkspaceRole =
    'institution-administrator' | 'examination-controller' | 'examiner' | 'invigilator' | 'student';
type Screen = {
    id: string;
    path: string;
    component: string;
    databaseContent: string;
};

async function signIn(page: Page, email: string | undefined, password: string | undefined): Promise<void> {
    if (!email || !password) throw new Error('Phase 13 role/theme fixture credentials are missing.');
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(workspaces|t\/[^/]+\/dashboard)$/);
}

test('applicable examination role-screen cells render and persist all six themes', async ({
    browser,
}, testInfo) => {
    test.setTimeout(900_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    if (!tenant) throw new Error('The guarded Phase 13 tenant fixture is missing.');

    const root = `/t/${tenant}`;
    // This spec must be independently runnable. Earlier full-suite order used
    // the delivery journey to bind the quiz; focused runs have no such order.
    // Provision the binding through the same authorized HTTP workflow when it
    // is absent, and verify the resulting persisted state in the workspace.
    const deliveryContext = await browser.newContext();
    const deliveryAdmin = await deliveryContext.newPage();
    await signIn(
        deliveryAdmin,
        process.env.E2E_REGISTRATION_OWNER_EMAIL,
        process.env.E2E_REGISTRATION_OWNER_PASSWORD,
    );
    await deliveryAdmin.goto(`${root}/academics/examinations`);
    const deliverySection = deliveryAdmin.getByRole('region', {
        name: 'Online delivery for E2E Online Examination',
    });
    await expect(deliverySection).toBeVisible();
    if (!(await deliverySection.getByText(/Bound to published quiz/).count())) {
        const quizId = process.env.E2E_EXAM_QUIZ_ID;
        const paperVersionId = process.env.E2E_EXAM_PAPER_VERSION_ID;
        if (!quizId || !paperVersionId) throw new Error('The online exam delivery fixture IDs are missing.');
        await deliverySection.getByLabel('Published quiz').selectOption(quizId);
        await deliverySection.getByLabel('Current approved paper').selectOption(paperVersionId);
        await deliverySection.getByRole('button', { name: 'Bind exam delivery' }).click();
        await expect(deliverySection).toContainText(/Bound to published quiz/);
    }
    await deliveryContext.close();

    const roles: { role: WorkspaceRole; email?: string; password?: string; screens: Screen[] }[] = [
        {
            role: 'institution-administrator',
            email: process.env.E2E_REGISTRATION_OWNER_EMAIL,
            password: process.env.E2E_REGISTRATION_OWNER_PASSWORD,
            screens: [
                {
                    id: 'cycle-date-sheet-seating',
                    path: `${root}/academics/examinations`,
                    component: 'workspace/academics/examinations/index',
                    databaseContent: 'E2E formal exam cycle',
                },
                {
                    id: 'eligibility-accommodation-review',
                    path: `${root}/academics/examinations/candidate-governance`,
                    component: 'workspace/examinations/candidate-governance',
                    databaseContent: 'E2E Online Examination',
                },
                {
                    id: 'results-appeals-incidents-operations',
                    path: `${root}/academics/examinations/results`,
                    component: 'workspace/examinations/results',
                    databaseContent: 'E2E Online Examination',
                },
            ],
        },
        {
            role: 'examination-controller',
            email: process.env.E2E_EXAMINATION_CONTROLLER_EMAIL,
            password: process.env.E2E_EXAMINATION_CONTROLLER_PASSWORD,
            screens: [
                {
                    id: 'cycle-date-sheet-seating',
                    path: `${root}/academics/examinations`,
                    component: 'workspace/academics/examinations/index',
                    databaseContent: 'E2E formal exam cycle',
                },
                {
                    id: 'eligibility-accommodation-review',
                    path: `${root}/academics/examinations/candidate-governance`,
                    component: 'workspace/examinations/candidate-governance',
                    databaseContent: 'E2E Online Examination',
                },
                {
                    id: 'results-appeals-incidents-operations',
                    path: `${root}/academics/examinations/results`,
                    component: 'workspace/examinations/results',
                    databaseContent: 'E2E Online Examination',
                },
            ],
        },
        {
            role: 'examiner',
            email: process.env.E2E_REGISTRATION_INSTRUCTOR_EMAIL,
            password: process.env.E2E_REGISTRATION_INSTRUCTOR_PASSWORD,
            screens: [
                {
                    id: 'examiner-marking',
                    path: `${root}/academics/examinations/marking`,
                    component: 'workspace/examinations/marking',
                    databaseContent: 'Phase 13 Theme Matrix Practical',
                },
            ],
        },
        {
            role: 'invigilator',
            email: process.env.E2E_EXAMINATION_INVIGILATOR_EMAIL,
            password: process.env.E2E_EXAMINATION_INVIGILATOR_PASSWORD,
            screens: [
                {
                    id: 'invigilator-duties',
                    path: `${root}/examination-duties`,
                    component: 'workspace/academics/exam-duties',
                    databaseContent: 'E2E In-person Examination',
                },
            ],
        },
        {
            role: 'student',
            email: process.env.E2E_REGISTRATION_STUDENT_EMAIL,
            password: process.env.E2E_REGISTRATION_STUDENT_PASSWORD,
            screens: [
                {
                    id: 'available-online-examinations',
                    path: `${root}/exams`,
                    component: 'workspace/examinations/available',
                    databaseContent: 'E2E Online Examination',
                },
                {
                    id: 'practical-and-oral-schedule',
                    path: `${root}/examinations/my`,
                    component: 'workspace/examinations/student-components',
                    databaseContent: 'Phase 13 Theme Matrix Practical',
                },
                {
                    id: 'candidate-governance',
                    path: `${root}/examinations/my-governance`,
                    component: 'workspace/examinations/candidate-governance',
                    databaseContent: 'E2E formal exam cycle',
                },
                {
                    id: 'released-results',
                    path: `${root}/examinations/results`,
                    component: 'workspace/examinations/my-results',
                    databaseContent: 'E2E Online Examination',
                },
            ],
        },
    ];

    const evidence: { role: WorkspaceRole; screen: string; theme: string; screenshot: string }[] = [];
    for (const role of roles) {
        const context = await browser.newContext({
            viewport: { width: 390, height: 844 },
            reducedMotion: 'reduce',
        });
        const page = await context.newPage();
        const pageErrors: string[] = [];
        page.on('pageerror', (error) => pageErrors.push(error.message));
        await signIn(page, role.email, role.password);

        const screens = [...role.screens];
        if (role.role === 'student') {
            const issuerContext = await browser.newContext();
            const issuer = await issuerContext.newPage();
            await signIn(
                issuer,
                process.env.E2E_REGISTRATION_OWNER_EMAIL,
                process.env.E2E_REGISTRATION_OWNER_PASSWORD,
            );
            await issuer.goto(`${root}/academics/examinations/candidate-governance`);
            const issue = issuer
                .locator('article')
                .filter({ hasText: 'E2E Online Examination' })
                .getByRole('button', { name: 'Issue admit document' });
            await expect(issue).toBeVisible();
            await issue.click();
            await issuerContext.close();

            const governance = await page.goto(`${root}/examinations/my-governance`);
            expect(governance?.status()).toBe(200);
            const admitLink = page.getByRole('link', { name: 'Open admit document' }).first();
            await expect(admitLink).toBeVisible();
            const href = await admitLink.getAttribute('href');
            expect(href).toBeTruthy();
            screens.push({
                id: 'private-admit-document',
                path: href as string,
                component: 'workspace/examinations/admit-document',
                databaseContent: 'Examination admit document',
            });
        }

        for (const screen of screens) {
            const response = await page.goto(screen.path);
            expect(response?.status(), `${role.role}/${screen.id} should be authorized`).toBe(200);
            await expect(page.locator('#main-content').first()).toBeVisible();
            await expect(page.locator('body')).toContainText(screen.databaseContent);

            const serializedPage = await page
                .locator('script[type="application/json"][data-page]')
                .textContent();
            expect(serializedPage, `${screen.id} should render an Inertia page`).toBeTruthy();
            const inertiaPage = JSON.parse(serializedPage as string) as {
                component: string;
                props: { auth?: { tenant?: { slug?: string } }; theme?: string };
            };
            expect(inertiaPage.component).toBe(screen.component);
            expect(inertiaPage.props.auth?.tenant?.slug).toBe(tenant);

            for (const theme of themes) {
                await page.getByLabel('Choose theme').selectOption(theme);
                await expect(page.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
                await page.reload();
                await expect(page.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
                await expect(page.locator('body')).toContainText(screen.databaseContent);

                const audit = await new AxeBuilder({ page })
                    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
                    .analyze();
                expect(audit.violations, `${role.role}/${screen.id}/${theme}`).toEqual([]);
                expect(
                    await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
                ).toBe(true);
                await page.keyboard.press('Tab');
                await expect(page.locator(':focus')).toBeVisible();

                const screenshot = testInfo.outputPath(`${role.role}--${screen.id}--${theme}.png`);
                await page.screenshot({ path: screenshot, fullPage: true });
                evidence.push({ role: role.role, screen: screen.id, theme, screenshot });
            }
        }

        expect(pageErrors, `${role.role} JavaScript errors`).toEqual([]);
        await context.close();
    }

    expect(evidence).toHaveLength(78);
    await testInfo.attach('phase13-role-screen-theme-evidence.json', {
        body: JSON.stringify({ combinations: evidence }, null, 2),
        contentType: 'application/json',
    });
});
