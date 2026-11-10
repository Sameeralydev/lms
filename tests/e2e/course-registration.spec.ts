import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

async function signIn(page: Page, email: string | undefined, password: string | undefined) {
    if (!email || !password)
        throw new Error('The isolated browser setup did not provide Phase 08 role credentials.');
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(workspaces|t\/[^/]+\/dashboard)$/);
}

test('learners preview, register, waitlist, withdraw and accept a released seat on mobile', async ({
    page,
}) => {
    test.setTimeout(60_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    if (!tenant) throw new Error('The isolated browser setup did not provide a Phase 08 tenant.');

    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(
        page,
        process.env.E2E_REGISTRATION_STUDENT_EMAIL,
        process.env.E2E_REGISTRATION_STUDENT_PASSWORD,
    );
    const registrationResponse = await page.goto(`/t/${tenant}/registration`);
    expect(registrationResponse?.status(), await page.locator('body').innerText()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Course registration', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: /E2E Course Registration/ })).toBeVisible();
    const registrationCourse = page.locator('article').filter({ hasText: 'E2E Course Registration' }).first();
    const firstPreview = registrationCourse.getByRole('button', { name: 'Preview registration' });
    await firstPreview.click();
    const dialog = page.getByRole('dialog', { name: 'Review registration' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('status')).toContainText('Expected result: confirmed');
    await expect(dialog.getByRole('button', { name: 'Confirm request' })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(firstPreview).toBeFocused();

    await firstPreview.click();
    await page.getByRole('button', { name: 'Confirm request' }).click();
    await expect(page.getByRole('cell', { name: 'confirmed' })).toBeVisible();
    const scheduleResponse = await page.goto(`/t/${tenant}/schedule`);
    expect(scheduleResponse?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'My class timetable' })).toBeVisible();
    const upcomingClasses = page.getByRole('list', { name: 'Upcoming class occurrences' });
    await expect(upcomingClasses).toBeVisible();
    await expect(
        upcomingClasses.getByRole('heading', { name: /E2E Course Registration/ }).first(),
    ).toBeVisible();
    await page.goto(`/t/${tenant}/registration`);
    const registeredSection = registrationCourse;
    // The same registration request must be rejected regardless of whether the
    // just-freed offering currently has an open seat or is waitlist-only.
    await registeredSection
        .getByRole('button', { name: /^Preview (registration|waitlist request)$/ })
        .click();
    await expect(registeredSection.getByRole('alert')).toContainText('already have an active registration');

    const waitlistContext = await page.context().browser()?.newContext();
    if (!waitlistContext)
        throw new Error('Playwright did not provide a separate browser session for the second learner.');
    const waitlistPage = await waitlistContext.newPage();
    await waitlistPage.setViewportSize({ width: 390, height: 844 });
    await signIn(
        waitlistPage,
        process.env.E2E_WAITLIST_STUDENT_EMAIL,
        process.env.E2E_WAITLIST_STUDENT_PASSWORD,
    );
    await waitlistPage.goto(`/t/${tenant}/registration`);
    await waitlistPage.getByRole('button', { name: 'Preview waitlist request' }).click();
    const waitlistDialog = waitlistPage.getByRole('dialog', { name: 'Review registration' });
    await expect(waitlistDialog.getByRole('status')).toContainText('request will enter the waitlist');
    await waitlistPage.getByRole('button', { name: 'Confirm request' }).click();
    await expect(waitlistPage.getByRole('cell', { name: 'waitlisted' })).toBeVisible();

    const axe = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    expect(axe.violations).toEqual([]);
    const widths = await page.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        content: document.documentElement.scrollWidth,
    }));
    expect(widths.content).toBeLessThanOrEqual(widths.viewport);

    page.once('dialog', async (nativeDialog) => {
        expect(nativeDialog.type()).toBe('prompt');
        await nativeDialog.accept('E2E learner withdrawal after seat release.');
    });
    const registrationRow = page.getByRole('row').filter({ hasText: 'E2E Course Registration' });
    await registrationRow.getByRole('button', { name: 'Request withdrawal' }).click();
    await expect(registrationRow.getByRole('cell', { name: 'withdrawn' })).toBeVisible();

    await waitlistPage.reload();
    await expect(waitlistPage.getByRole('cell', { name: 'offer pending' })).toBeVisible();
    await waitlistPage.getByRole('button', { name: 'Accept seat' }).click();
    await expect(waitlistPage.getByRole('cell', { name: 'confirmed' })).toBeVisible();
    const workspaceId = process.env.E2E_REGISTRATION_WORKSPACE_ID;
    const lessonId = process.env.E2E_REGISTRATION_LESSON_ID;
    const learningResponse = await waitlistPage.goto(
        `/t/${tenant}/learning/${workspaceId}/lessons/${lessonId}`,
    );
    expect(learningResponse?.status()).toBe(200);
    await expect(waitlistPage.getByRole('heading', { name: 'Welcome to the course' })).toBeVisible();
    const deniedRegistrar = await waitlistPage.goto(`/t/${tenant}/registrar/enrollment`);
    expect(deniedRegistrar?.status()).toBe(403);
    await waitlistContext.close();
});

test('registrar assigns an instructor who can view only the confirmed section roster', async ({
    page,
    browser,
}) => {
    test.setTimeout(60_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    if (!tenant) throw new Error('The isolated browser setup did not provide a Phase 08 tenant.');

    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page, process.env.E2E_REGISTRATION_OWNER_EMAIL, process.env.E2E_REGISTRATION_OWNER_PASSWORD);
    const registrarResponse = await page.goto(`/t/${tenant}/registrar/enrollment`);
    expect(registrarResponse?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Enrollment administration' })).toBeVisible();
    await expect(page.getByRole('heading', { name: /E2E Course Registration/ })).toBeVisible();
    const registrationOffering = page
        .locator('article')
        .filter({ hasText: 'E2E Course Registration · E2E-REG-101-A' });
    const registrationSection = registrationOffering
        .getByRole('listitem')
        .filter({ hasText: 'Section A: 1/1 confirmed' });
    await registrationSection
        .getByLabel('Assign instructor')
        .selectOption(process.env.E2E_REGISTRATION_INSTRUCTOR_USER_ID ?? '');
    await registrationSection.getByRole('button', { name: 'Assign instructor' }).click();
    await expect(page.getByRole('status')).toContainText('Instructor assigned to section.');
    const registrarAxe = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    expect(registrarAxe.violations).toEqual([]);

    const instructorContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const instructorPage = await instructorContext.newPage();
    await signIn(
        instructorPage,
        process.env.E2E_REGISTRATION_INSTRUCTOR_EMAIL,
        process.env.E2E_REGISTRATION_INSTRUCTOR_PASSWORD,
    );
    const sectionsResponse = await instructorPage.goto(`/t/${tenant}/instructor/sections`);
    expect(sectionsResponse?.status()).toBe(200);
    await expect(instructorPage.getByRole('heading', { name: 'My teaching sections' })).toBeVisible();
    await instructorPage
        .getByRole('row')
        .filter({ hasText: 'E2E-REG-101-A' })
        .getByRole('link', { name: /Open roster/ })
        .click();
    await expect(instructorPage.getByRole('heading', { name: /E2E Course Registration/ })).toBeVisible();
    await expect(instructorPage.getByRole('cell', { name: /Phase 08 Waitlist/ })).toBeVisible();
    const rosterAxe = await new AxeBuilder({ page: instructorPage })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    expect(rosterAxe.violations).toEqual([]);
    const widths = await instructorPage.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        content: document.documentElement.scrollWidth,
    }));
    expect(widths.content).toBeLessThanOrEqual(widths.viewport);
    await instructorPage.keyboard.press('Tab');
    await expect(instructorPage.locator(':focus')).toBeVisible();
    await instructorContext.close();

    const unauthorizedContext = await browser.newContext();
    const unauthorizedPage = await unauthorizedContext.newPage();
    await signIn(
        unauthorizedPage,
        process.env.E2E_UNASSIGNED_INSTRUCTOR_EMAIL,
        process.env.E2E_UNASSIGNED_INSTRUCTOR_PASSWORD,
    );
    const forbiddenRoster = await unauthorizedPage.goto(
        `/t/${tenant}/instructor/sections/${process.env.E2E_REGISTRATION_SECTION_ID}/roster`,
    );
    expect(forbiddenRoster?.status()).toBe(403);
    await unauthorizedContext.close();
});
