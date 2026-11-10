import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

test('controller creates and submits an immutable date-sheet revision across all six themes', async ({
    browser,
}) => {
    test.setTimeout(120_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    const email = process.env.E2E_REGISTRATION_OWNER_EMAIL;
    const password = process.env.E2E_REGISTRATION_OWNER_PASSWORD;
    if (!tenant || !email || !password)
        throw new Error('Guarded Phase 13 examination fixture credentials are missing.');

    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(workspaces|t\/[^/]+\/dashboard)$/);

    const response = await page.goto(`/t/${tenant}/academics/examinations`);
    expect(response?.status()).toBe(200);
    const cycle = page.locator('article').filter({ hasText: 'E2E formal exam cycle' });
    await expect(cycle).toBeVisible();
    await cycle.getByRole('button', { name: 'Create schedule revision' }).click();
    await expect(cycle.getByText(/Revision 1 · draft/)).toBeVisible();
    await cycle.getByRole('button', { name: 'Submit for review' }).click();
    await expect(cycle.getByText(/Revision 1 · submitted/)).toBeVisible();

    for (const theme of [
        'executive-navy',
        'academic-emerald',
        'modern-indigo',
        'warm-ivory',
        'graphite-dark',
        'midnight-violet',
    ]) {
        await page.getByLabel('Choose theme').selectOption(theme);
        await expect(page.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        const audit = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(audit.violations).toEqual([]);
        const overflow = await page.evaluate(() => ({
            hasOverflow: document.documentElement.scrollWidth <= window.innerWidth,
            elements: Array.from(document.querySelectorAll<HTMLElement>('*'))
                .map((element) => ({ element, rect: element.getBoundingClientRect() }))
                .filter(({ rect }) => rect.right > window.innerWidth + 1 || rect.left < -1)
                .slice(0, 8)
                .map(
                    ({ element, rect }) =>
                        `${element.tagName.toLowerCase()}${element.id ? `#${element.id}` : ''}.${element.className.toString().split(' ').slice(0, 3).join('.')} left=${Math.round(rect.left)} right=${Math.round(rect.right)} width=${Math.round(rect.width)}`,
                ),
        }));
        expect(overflow.hasOverflow, overflow.elements.join('\n')).toBe(true);
    }
    await page.keyboard.press('Tab');
    await expect(page.locator(':focus')).toBeVisible();
    expect(errors).toEqual([]);
    await context.close();
});

test('exam controller builds a campus-scoped seating plan for review and independent publication', async ({
    browser,
}) => {
    test.setTimeout(120_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    const ownerEmail = process.env.E2E_REGISTRATION_OWNER_EMAIL;
    const ownerPassword = process.env.E2E_REGISTRATION_OWNER_PASSWORD;
    if (!tenant || !ownerEmail || !ownerPassword)
        throw new Error('Guarded Phase 13 examination fixture credentials are missing.');

    const ownerContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const ownerPage = await ownerContext.newPage();
    const errors: string[] = [];
    ownerPage.on('pageerror', (error) => errors.push(error.message));
    await signIn(ownerPage, ownerEmail, ownerPassword);
    const root = `/t/${tenant}/academics/examinations`;
    expect((await ownerPage.goto(root))?.status()).toBe(200);
    const cycle = ownerPage.locator('article').filter({ hasText: 'E2E formal exam cycle' });
    const planner = cycle.locator('section[aria-label="Seating planner for E2E In-person Examination"]');
    await expect(planner).toBeVisible();
    await planner.locator('select[aria-label^="Room for"]').selectOption({ index: 1 });
    await planner.locator('input[aria-label^="Seat for"]').fill('H-01');
    await planner.getByRole('button', { name: 'Save seating revision' }).click();
    await expect(planner.getByText(/Revision 1 · draft/)).toBeVisible();
    await planner.getByRole('button', { name: 'Submit for review' }).click();
    await expect(planner.getByText(/Revision 1 · submitted/)).toBeVisible();

    for (const theme of [
        'executive-navy',
        'academic-emerald',
        'modern-indigo',
        'warm-ivory',
        'graphite-dark',
        'midnight-violet',
    ]) {
        await ownerPage.getByLabel('Choose theme').selectOption(theme);
        await expect(ownerPage.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        const audit = await new AxeBuilder({ page: ownerPage })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(audit.violations).toEqual([]);
        expect(
            await ownerPage.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
        ).toBe(true);
    }
    await ownerPage.keyboard.press('Tab');
    await expect(ownerPage.locator(':focus')).toBeVisible();

    const reviewerContext = await browser.newContext();
    const reviewerPage = await reviewerContext.newPage();
    await signIn(
        reviewerPage,
        process.env.E2E_SCHEDULING_REVIEWER_EMAIL,
        process.env.E2E_SCHEDULING_REVIEWER_PASSWORD,
    );
    await reviewerPage.goto(root);
    const reviewerPlanner = reviewerPage
        .locator('article')
        .filter({ hasText: 'E2E formal exam cycle' })
        .locator('section[aria-label="Seating planner for E2E In-person Examination"]');
    await reviewerPlanner.getByRole('button', { name: 'Approve seating' }).click();
    await expect(reviewerPlanner.getByText(/Revision 1 · approved/)).toBeVisible();

    const publisherContext = await browser.newContext();
    const publisherPage = await publisherContext.newPage();
    await signIn(
        publisherPage,
        process.env.E2E_ATTENDANCE_OWNER_EMAIL,
        process.env.E2E_ATTENDANCE_OWNER_PASSWORD,
    );
    await publisherPage.goto(root);
    const publisherPlanner = publisherPage
        .locator('article')
        .filter({ hasText: 'E2E formal exam cycle' })
        .locator('section[aria-label="Seating planner for E2E In-person Examination"]');
    await publisherPlanner.getByRole('button', { name: 'Publish seating' }).click();
    await expect(publisherPlanner.getByText(/^Revision 1/)).toBeVisible();
    const operations = publisherPage.getByRole('region', {
        name: 'Invigilation and attendance for E2E In-person Examination',
    });
    await operations.getByLabel('Assign staff member').selectOption({
        label: 'Phase 10 E2E Instructor',
    });
    await operations.getByRole('button', { name: 'Assign duty' }).click();
    await expect(operations.locator('li').filter({ hasText: 'Phase 10 E2E Instructor' })).toBeVisible();

    const invigilatorContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const invigilatorPage = await invigilatorContext.newPage();
    await signIn(
        invigilatorPage,
        process.env.E2E_ATTENDANCE_INSTRUCTOR_EMAIL,
        process.env.E2E_ATTENDANCE_INSTRUCTOR_PASSWORD,
    );
    await expect(invigilatorPage.getByText('Phase 10 E2E Instructor', { exact: true })).toBeVisible();
    const dutyResponse = await invigilatorPage.goto(`/t/${tenant}/examination-duties`);
    expect(dutyResponse?.status()).toBe(200);
    await expect(invigilatorPage.getByRole('heading', { name: 'E2E In-person Examination' })).toBeVisible();
    const dutyCard = invigilatorPage.locator('article').filter({
        hasText: 'E2E In-person Examination',
    });
    await dutyCard.getByRole('button', { name: 'Accept duty' }).click();
    await expect(dutyCard.getByText('Accepted')).toBeVisible();
    for (const theme of [
        'executive-navy',
        'academic-emerald',
        'modern-indigo',
        'warm-ivory',
        'graphite-dark',
        'midnight-violet',
    ]) {
        await invigilatorPage.getByLabel('Choose theme').selectOption(theme);
        await expect(invigilatorPage.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        const audit = await new AxeBuilder({ page: invigilatorPage })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(audit.violations).toEqual([]);
        expect(
            await invigilatorPage.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
        ).toBe(true);
    }
    await invigilatorPage.keyboard.press('Tab');
    await expect(invigilatorPage.locator(':focus')).toBeVisible();
    await invigilatorContext.close();
    await expect(publisherPlanner.getByText(/Revision 1 · published/)).toBeVisible();
    expect(errors).toEqual([]);

    await ownerContext.close();
    await reviewerContext.close();
    await publisherContext.close();
});

async function signIn(
    page: import('@playwright/test').Page,
    email?: string,
    password?: string,
): Promise<void> {
    if (!email || !password) throw new Error('The authenticated examination test identity is missing.');
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(workspaces|t\/[^/]+\/dashboard)$/);
}
