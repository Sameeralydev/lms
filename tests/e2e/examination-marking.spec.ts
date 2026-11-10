import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { readFile } from 'node:fs/promises';

async function signIn(
    page: import('@playwright/test').Page,
    email: string | undefined,
    password: string | undefined,
) {
    if (!email || !password) throw new Error('Guarded Phase 13 fixture credentials are missing.');
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(workspaces|t\/[^/]+\/dashboard)$/);
}

test('controller schedules a practical, two examiners mark it, moderator finalizes and student sees completion only', async ({
    browser,
}, testInfo) => {
    test.setTimeout(300_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    const run = process.env.E2E_RUN_ID;
    if (!tenant || !run) throw new Error('Guarded E2E tenant and run id were not provided.');
    const title = `Practical marking ${run}`;
    const root = `/t/${tenant}`;
    const controllerContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const controller = await controllerContext.newPage();
    await signIn(
        controller,
        process.env.E2E_REGISTRATION_OWNER_EMAIL,
        process.env.E2E_REGISTRATION_OWNER_PASSWORD,
    );
    const markingResponse = await controller.goto(`${root}/academics/examinations/marking`);
    expect(markingResponse?.status()).toBe(200);
    await expect(
        controller.getByRole('heading', { name: 'Schedule a practical or oral component' }),
    ).toBeVisible();
    const create = controller.locator('#main-content form').first();
    await create.getByLabel('Examination session').selectOption({ label: 'E2E Online Examination' });
    await create.getByLabel('Component type').selectOption('practical');
    await create.getByLabel('Component code').fill(`PRACT-${run.slice(-8)}`);
    await create.getByLabel('Title').fill(title);
    await create.getByLabel('Maximum marks').fill('20');
    await create.locator('input[name="rubric_one_title"]').fill('Method and safety');
    await create.getByLabel('Criterion 1 marks').fill('12');
    await create.locator('input[name="rubric_two_title"]').fill('Analysis and explanation');
    await create.getByLabel('Criterion 2 marks').fill('8');
    await create.getByLabel('Require independent second marking').check();
    await create.getByLabel('Moderation difference threshold (marks)').fill('2');
    await create.getByRole('button', { name: 'Save component draft' }).click();
    const component = controller.locator('article').filter({ hasText: title });
    await expect(component).toContainText('draft');
    await component.getByRole('button', { name: 'Publish component' }).click();
    await expect(component).toContainText('published');

    const assignments = component.locator('form');
    await assignments.nth(0).getByLabel('Assign examiner').selectOption({ label: 'Phase 08 E2E Instructor' });
    await assignments.nth(0).getByLabel('Role').selectOption('primary');
    await assignments.nth(0).getByRole('button', { name: 'Save role assignment' }).click();
    await assignments
        .nth(0)
        .getByLabel('Assign examiner')
        .selectOption({ label: 'Phase 09 E2E Scheduling Reviewer' });
    await assignments.nth(0).getByLabel('Role').selectOption('second');
    await assignments.nth(0).getByRole('button', { name: 'Save role assignment' }).click();
    await assignments.nth(0).getByLabel('Assign examiner').selectOption({ label: 'Phase 08 E2E Registrar' });
    await assignments.nth(0).getByLabel('Role').selectOption('moderator');
    await assignments.nth(0).getByRole('button', { name: 'Save role assignment' }).click();

    const allocate = assignments.nth(1);
    await allocate.getByLabel('Candidate').selectOption({ index: 0 });
    await allocate.getByLabel('Examiner').selectOption({ label: 'Phase 08 E2E Instructor' });
    await allocate.getByLabel('Marking round').selectOption('1');
    await allocate.getByLabel('Allocation reason').fill('Scheduled first examiner review');
    await allocate.getByRole('button', { name: 'Allocate candidate' }).click();
    await allocate.getByLabel('Examiner').selectOption({ label: 'Phase 09 E2E Scheduling Reviewer' });
    await allocate.getByLabel('Marking round').selectOption('2');
    await allocate.getByRole('button', { name: 'Allocate candidate' }).click();

    const uploadForm = controller.locator('form').filter({
        has: controller.getByText('Quarantine a scanned answer script'),
    });
    await uploadForm.locator('select[name="session_id"]').selectOption({ label: 'E2E Online Examination' });
    await uploadForm.locator('select[name="candidate_id"]').selectOption({ index: 0 });
    await uploadForm.getByLabel('Component (optional)').selectOption({ label: title });
    await uploadForm.getByLabel('Private script file').setInputFiles({
        name: `answer-${run}.txt`,
        mimeType: 'text/plain',
        buffer: Buffer.from(`Confidential practical evidence for ${run}.`),
    });
    const uploadResponse = controller.waitForResponse(
        (response) => response.url().includes('/scripts') && response.request().method() === 'POST',
    );
    await uploadForm.getByRole('button', { name: 'Upload and scan' }).click();
    expect((await uploadResponse).status()).toBeLessThan(400);
    await expect(
        controller.getByText('Script passed scanning and is available only to assigned exam roles.'),
    ).toBeVisible();
    await uploadForm.getByLabel('Private script file').setInputFiles({
        name: `eicar-test-${run}.txt`,
        mimeType: 'text/plain',
        buffer: Buffer.from('X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'),
    });
    const infectedUploadResponse = controller.waitForResponse(
        (response) => response.url().includes('/scripts') && response.request().method() === 'POST',
    );
    await uploadForm.getByRole('button', { name: 'Upload and scan' }).click();
    expect((await infectedUploadResponse).status()).toBeLessThan(400);
    await expect(
        controller.getByText('Script remains quarantined because scanning did not approve it.'),
    ).toBeVisible();

    const firstContext = await browser.newContext();
    const first = await firstContext.newPage();
    await signIn(
        first,
        process.env.E2E_REGISTRATION_INSTRUCTOR_EMAIL,
        process.env.E2E_REGISTRATION_INSTRUCTOR_PASSWORD,
    );
    const firstResponse = await first.goto(`${root}/academics/examinations/marking`);
    expect(firstResponse?.status()).toBe(200);
    const firstCard = first.locator('article').filter({ hasText: title });
    if ((await firstCard.count()) === 0) {
        throw new Error(
            `Examiner workspace did not show the assigned marking. URL: ${first.url()}. Page: ${(await first.locator('main').innerText()).slice(0, 2000)}`,
        );
    }
    await expect(firstCard).toBeVisible();
    await first.setViewportSize({ width: 390, height: 844 });
    for (const theme of [
        'executive-navy',
        'academic-emerald',
        'modern-indigo',
        'warm-ivory',
        'graphite-dark',
        'midnight-violet',
    ]) {
        await first.getByLabel('Choose theme').selectOption(theme);
        await expect(first.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        const themeAudit = await new AxeBuilder({ page: first })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(themeAudit.violations, `Examiner marking violations with ${theme}`).toEqual([]);
        expect(await first.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
            true,
        );
        await first.screenshot({
            path: testInfo.outputPath(`phase13-examiner-${theme}.png`),
            fullPage: true,
        });
    }
    await first.emulateMedia({ reducedMotion: 'reduce' });
    await first.keyboard.press('Tab');
    await expect(first.locator(':focus')).toBeVisible();
    const answerScript = firstCard.getByRole('link', { name: `answer-${run}.txt` });
    await expect(answerScript).toBeVisible();
    await expect(firstCard).not.toContainText(`eicar-test-${run}.txt`);
    const download = first.waitForEvent('download');
    await answerScript.click();
    const downloadedScript = await download;
    expect(downloadedScript.suggestedFilename()).toBe(`answer-${run}.txt`);
    const downloadPath = await downloadedScript.path();
    expect(downloadPath).not.toBeNull();
    if (downloadPath !== null) {
        expect(await readFile(downloadPath, 'utf8')).toBe(`Confidential practical evidence for ${run}.`);
    }
    await firstCard.getByLabel('Method and safety marks').fill('10.5');
    await firstCard.getByLabel('Analysis and explanation marks').fill('5');
    const draftResponse = first.waitForResponse(
        (response) => response.url().includes('/draft') && response.request().method() === 'PATCH',
    );
    await firstCard.getByRole('button', { name: 'Save marking draft' }).click();
    expect((await draftResponse).status()).toBeLessThan(400);
    await first.reload();
    const recoveredCard = first
        .getByRole('region', { name: 'My assigned marking' })
        .locator('article')
        .filter({ hasText: title });
    await expect(recoveredCard.getByLabel('Method and safety marks')).toHaveValue('10.50');
    await expect(recoveredCard.getByLabel('Analysis and explanation marks')).toHaveValue('5.00');
    await expect(recoveredCard.getByRole('button', { name: 'Submit and lock marks' })).toBeEnabled();
    await recoveredCard.getByRole('button', { name: 'Submit and lock marks' }).click();
    await expect(first.getByText(/Marking revision 1 submitted and locked\./)).toBeVisible();

    const secondContext = await browser.newContext();
    const second = await secondContext.newPage();
    await signIn(
        second,
        process.env.E2E_SCHEDULING_REVIEWER_EMAIL,
        process.env.E2E_SCHEDULING_REVIEWER_PASSWORD,
    );
    await second.goto(`${root}/academics/examinations/marking`);
    const secondCard = second
        .getByRole('region', { name: 'My assigned marking' })
        .locator('article')
        .filter({ hasText: title });
    await expect(secondCard).toBeVisible();
    await secondCard.getByLabel('Method and safety marks').fill('9');
    await secondCard.getByLabel('Analysis and explanation marks').fill('4.5');
    await secondCard.getByRole('button', { name: 'Save marking draft' }).click();
    await secondCard.getByRole('button', { name: 'Submit and lock marks' }).click();
    await expect(second.getByText(/Marking revision 1 submitted and locked\./)).toBeVisible();

    await controller.reload();
    const review = controller.locator('li').filter({ hasText: title });
    await expect(review).toBeVisible();
    await review.getByLabel('Final moderated marks').fill('14.75');
    await review.getByLabel('Moderation decision').selectOption('adjusted');
    await review.getByLabel('Moderation reason').fill('Resolved the difference using the rubric evidence.');
    const moderationResponse = controller.waitForResponse((response) => response.url().includes('/moderate'));
    await review.getByRole('button', { name: 'Finalize moderation' }).click();
    expect((await moderationResponse).status()).toBeLessThan(400);
    await expect(controller.getByText(/Moderation decision recorded as result version/)).toBeVisible();

    const studentContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const student = await studentContext.newPage();
    await signIn(
        student,
        process.env.E2E_REGISTRATION_STUDENT_EMAIL,
        process.env.E2E_REGISTRATION_STUDENT_PASSWORD,
    );
    await student.goto(`${root}/examinations/my`);
    const item = student.getByRole('listitem').filter({ hasText: title });
    await expect(item).toContainText('completed');
    await expect(item).not.toContainText('14.75');
    await student.emulateMedia({ reducedMotion: 'reduce' });
    for (const theme of [
        'executive-navy',
        'academic-emerald',
        'modern-indigo',
        'warm-ivory',
        'graphite-dark',
        'midnight-violet',
    ]) {
        await student.getByLabel('Choose theme').selectOption(theme);
        await expect(student.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        const accessibility = await new AxeBuilder({ page: student })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(accessibility.violations).toEqual([]);
        expect(await student.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
            true,
        );
    }
    await student.reload();
    await expect(student.locator('[data-theme]')).toHaveAttribute('data-theme', 'midnight-violet');

    await Promise.all([
        controllerContext.close(),
        firstContext.close(),
        secondContext.close(),
        studentContext.close(),
    ]);
});
