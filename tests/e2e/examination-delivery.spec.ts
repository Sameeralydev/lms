import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

async function signIn(
    page: import('@playwright/test').Page,
    email: string | undefined,
    password: string | undefined,
) {
    if (!email || !password)
        throw new Error('The guarded browser fixture did not provide exam role credentials.');
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(workspaces|t\/[^/]+\/dashboard)$/);
}

test('controller binds a published exam and student completes a recoverable online attempt', async ({
    page,
    browser,
}) => {
    test.setTimeout(90_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    const sessionId = process.env.E2E_EXAM_SESSION_ID;
    const paperId = process.env.E2E_EXAM_PAPER_VERSION_ID;
    if (!tenant || !sessionId || !paperId)
        throw new Error('The guarded setup did not provision the Phase 13 online exam fixture.');

    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page, process.env.E2E_REGISTRATION_OWNER_EMAIL, process.env.E2E_REGISTRATION_OWNER_PASSWORD);
    await page.goto(`/t/${tenant}/academics/examinations`);
    await expect(page.getByRole('heading', { name: 'Examinations', exact: true })).toBeVisible();
    const delivery = page.getByRole('region', { name: 'Online delivery for E2E Online Examination' });
    const examQuizId = process.env.E2E_EXAM_QUIZ_ID;
    if (!examQuizId) throw new Error('The guarded fixture did not provide a dedicated formal exam quiz.');
    await delivery.getByLabel('Published quiz').selectOption(examQuizId);
    await delivery.getByLabel('Current approved paper').selectOption(paperId);
    await delivery.getByRole('button', { name: 'Bind exam delivery' }).click();
    await expect(delivery).toContainText('Bound to published quiz');

    const studentContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const studentPage = await studentContext.newPage();
    await signIn(
        studentPage,
        process.env.E2E_REGISTRATION_STUDENT_EMAIL,
        process.env.E2E_REGISTRATION_STUDENT_PASSWORD,
    );
    // Navigate to the authenticated tenant route directly: a student can have
    // access to exam delivery without the registrar's broader sidebar grants.
    await studentPage.goto(`/t/${tenant}/exams`);
    await expect(
        studentPage.getByRole('heading', { name: 'Online examinations', exact: true }),
    ).toBeVisible();
    const card = studentPage.getByRole('listitem').filter({ hasText: 'E2E Online Examination' });
    await expect(card).toContainText('E2E approved exam paper');
    await card.getByRole('button', { name: 'Start examination' }).click();
    await expect(studentPage).toHaveURL(/\/assessments\/attempts\/\d+$/);
    await expect(studentPage.getByRole('heading', { name: 'E2E Online Examination' })).toBeVisible();
    await studentPage.emulateMedia({ reducedMotion: 'reduce' });
    const accessibility = await new AxeBuilder({ page: studentPage })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    expect(accessibility.violations).toEqual([]);
    expect(await studentPage.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
        true,
    );
    const answer = studentPage.getByLabel('Your response');
    const timerBeforeRefresh = await studentPage.getByRole('timer').innerText();
    await answer.fill('A server-saved answer that survives a browser refresh.');
    await expect(studentPage.getByText('Saved on server', { exact: true })).toBeVisible({ timeout: 10_000 });
    await studentPage.reload();
    await expect(studentPage.getByLabel('Your response')).toHaveValue(
        'A server-saved answer that survives a browser refresh.',
    );
    await expect(studentPage.getByRole('timer')).toHaveText(/Time remaining: \d+:\d{2}/);
    expect(timerBeforeRefresh).toMatch(/Time remaining: \d+:\d{2}/);
    studentPage.once('dialog', (dialog) => dialog.accept());
    await studentPage.getByRole('button', { name: 'Submit assessment' }).click();
    await expect(studentPage.getByRole('heading', { name: 'Submission receipt' })).toBeVisible();
    await expect(studentPage.getByText(/Receipt reference:/)).toBeVisible();

    const unauthorizedContext = await browser.newContext();
    const unauthorizedPage = await unauthorizedContext.newPage();
    await signIn(
        unauthorizedPage,
        process.env.E2E_SCHEDULING_STUDENT_EMAIL,
        process.env.E2E_SCHEDULING_STUDENT_PASSWORD,
    );
    const directAttempt = await unauthorizedPage.goto(studentPage.url());
    expect(directAttempt?.status()).toBe(404);
    await unauthorizedContext.close();
    await studentContext.close();
});
