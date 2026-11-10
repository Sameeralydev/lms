import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

async function signIn(page: Page, email?: string, password?: string) {
    if (!email || !password)
        throw new Error('The isolated browser setup did not provide attendance credentials.');
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(workspaces|t\/[^/]+\/dashboard)$/);
}

test('attendance policy, instructor register, learner statement, responsive layout and six-theme accessibility journey', async ({
    page,
    browser,
}) => {
    test.setTimeout(180_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    const date = process.env.E2E_ATTENDANCE_DATE;
    if (!tenant || !date)
        throw new Error('The isolated browser setup did not provide the Phase 10 tenant or occurrence.');
    const consoleErrors: string[] = [];
    page.on('pageerror', (error) => consoleErrors.push(error.message));
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page, process.env.E2E_ATTENDANCE_OWNER_EMAIL, process.env.E2E_ATTENDANCE_OWNER_PASSWORD);
    await page.goto(`/t/${tenant}/attendance`);
    await expect(page.getByRole('heading', { name: 'Attendance', exact: true })).toBeVisible();
    const policyForm = page
        .locator('form')
        .filter({ has: page.getByRole('heading', { name: 'Create policy' }) });
    await policyForm.getByLabel('Policy name').fill('E2E Attendance Policy');
    await policyForm.getByLabel('Campus scope').selectOption({ label: 'E2E Main Campus' });
    await policyForm.getByRole('button', { name: 'Create policy' }).click();
    await expect(page.getByText('E2E Attendance Policy')).toBeVisible();

    const instructorContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const instructorPage = await instructorContext.newPage();
    await signIn(
        instructorPage,
        process.env.E2E_ATTENDANCE_INSTRUCTOR_EMAIL,
        process.env.E2E_ATTENDANCE_INSTRUCTOR_PASSWORD,
    );
    await instructorPage.goto(`/t/${tenant}/attendance`);
    const occurrence = instructorPage.getByRole('listitem').filter({ hasText: 'E2E Attendance Biology' });
    await expect(occurrence).toContainText(date);
    await occurrence.getByRole('button', { name: 'Open attendance register' }).click();
    await expect(instructorPage.getByRole('heading', { name: `Attendance · ${date}` })).toBeVisible();
    const registerUrl = instructorPage.url();
    await expect(instructorPage.getByText('1 expected learners')).toBeVisible();
    await instructorPage.getByRole('button', { name: 'Mark all present' }).click();
    await instructorPage.getByLabel('Attendance status').selectOption('ABSENT');
    const saveAttendance = instructorPage.getByRole('button', { name: 'Save attendance' });
    const saveResponse = instructorPage.waitForResponse((response) => response.url().includes('/entries'));
    await saveAttendance.click();
    const saved = await saveResponse;
    expect(saved.status()).toBe(303);
    await instructorPage.waitForLoadState('networkidle');
    await expect(instructorPage.getByRole('button', { name: 'Save attendance' })).toBeEnabled();
    await expect(instructorPage.getByText(/Current: Absent/)).toBeVisible();
    await instructorPage.getByRole('button', { name: 'Submit register' }).click();
    await expect(instructorPage.getByText(/1 expected learners · submitted · Revision 3/)).toBeVisible();

    await instructorPage.goto(`/t/${tenant}/attendance`);
    const themes = [
        'executive-navy',
        'academic-emerald',
        'modern-indigo',
        'warm-ivory',
        'graphite-dark',
        'midnight-violet',
    ];
    const themePicker = instructorPage.getByLabel('Choose theme');
    for (const theme of themes) {
        await themePicker.selectOption(theme);
        const accessibility = await new AxeBuilder({ page: instructorPage })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();
        expect(accessibility.violations, `Attendance page violations under ${theme}`).toEqual([]);
    }
    await instructorPage.keyboard.press('Tab');
    expect(await instructorPage.evaluate(() => document.activeElement?.tagName)).not.toBe('BODY');
    const dimensions = await instructorPage.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        content: document.documentElement.scrollWidth,
    }));
    expect(dimensions.content).toBeLessThanOrEqual(dimensions.viewport);
    expect(consoleErrors).toEqual([]);
    await instructorContext.close();

    const reviewerContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const reviewerPage = await reviewerContext.newPage();
    await signIn(
        reviewerPage,
        process.env.E2E_ATTENDANCE_OWNER_EMAIL,
        process.env.E2E_ATTENDANCE_OWNER_PASSWORD,
    );
    await reviewerPage.goto(registerUrl);
    await reviewerPage.getByRole('button', { name: 'Finalize register' }).click();
    await expect(reviewerPage.locator('main').last()).toContainText('locked');
    await reviewerPage.goto(`/t/${tenant}/attendance/reports?from=${date}&to=${date}`);
    await expect(reviewerPage.getByRole('link', { name: 'Download filtered CSV' })).toBeVisible();
    await reviewerPage.getByLabel('Instructor').selectOption({ label: 'Phase 10 E2E Instructor' });
    await reviewerPage.getByRole('button', { name: 'Apply filters' }).click();
    await expect(reviewerPage.getByRole('region', { name: 'Attendance report results' })).toContainText(
        'E2E Attendance Biology',
    );
    await expect(reviewerPage.getByRole('link', { name: 'Download filtered CSV' })).toBeVisible();
    const reportDownload = reviewerPage.waitForEvent('download');
    await reviewerPage.getByRole('link', { name: 'Download filtered CSV' }).click();
    expect((await reportDownload).suggestedFilename()).toBe('attendance-report.csv');
    for (const theme of [
        'executive-navy',
        'academic-emerald',
        'modern-indigo',
        'warm-ivory',
        'graphite-dark',
        'midnight-violet',
    ]) {
        await reviewerPage.getByLabel('Choose theme').selectOption(theme);
        const accessibility = await new AxeBuilder({ page: reviewerPage })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();
        expect(accessibility.violations, `Attendance report violations under ${theme}`).toEqual([]);
    }
    const studentContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const studentPage = await studentContext.newPage();
    await signIn(
        studentPage,
        process.env.E2E_ATTENDANCE_STUDENT_EMAIL,
        process.env.E2E_ATTENDANCE_STUDENT_PASSWORD,
    );
    await studentPage.goto(`/t/${tenant}/attendance/my`);
    await expect(studentPage.getByRole('heading', { name: 'My attendance' })).toBeVisible();
    await expect(studentPage.getByText('Attendance rate')).toBeVisible();
    await expect(studentPage.getByText('0%', { exact: true })).toBeVisible();
    await expect(studentPage.getByRole('heading', { name: 'Monthly attendance calendar' })).toBeVisible();
    await expect(studentPage.getByRole('table', { name: /Attendance calendar for/ })).toContainText('ABSENT');
    await studentPage.getByLabel('Reason').fill('I was unwell and could not attend class that day.');
    await studentPage.getByRole('button', { name: 'Request review' }).click();
    await expect(studentPage.getByText('pending', { exact: true })).toBeVisible();
    for (const theme of [
        'executive-navy',
        'academic-emerald',
        'modern-indigo',
        'warm-ivory',
        'graphite-dark',
        'midnight-violet',
    ]) {
        await studentPage.getByLabel('Choose theme').selectOption(theme);
        const accessibility = await new AxeBuilder({ page: studentPage })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();
        expect(accessibility.violations, `Student attendance violations under ${theme}`).toEqual([]);
    }
    await studentPage.getByRole('button', { name: 'Next month' }).click();
    await expect(studentPage.getByRole('table', { name: /Attendance calendar for/ })).toBeVisible();
    await studentContext.close();

    await reviewerPage.goto(`/t/${tenant}/attendance/justifications`);
    await expect(reviewerPage.getByRole('heading', { name: 'Absence justifications' })).toBeVisible();
    for (const theme of [
        'executive-navy',
        'academic-emerald',
        'modern-indigo',
        'warm-ivory',
        'graphite-dark',
        'midnight-violet',
    ]) {
        await reviewerPage.getByLabel('Choose theme').selectOption(theme);
        const accessibility = await new AxeBuilder({ page: reviewerPage })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();
        expect(accessibility.violations, `Absence review violations under ${theme}`).toEqual([]);
    }
    await reviewerPage.setViewportSize({ width: 390, height: 844 });
    await reviewerPage.keyboard.press('Tab');
    expect(await reviewerPage.evaluate(() => document.activeElement?.tagName)).not.toBe('BODY');
    const reviewDimensions = await reviewerPage.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        content: document.documentElement.scrollWidth,
    }));
    expect(reviewDimensions.content).toBeLessThanOrEqual(reviewDimensions.viewport);
    await reviewerPage.getByRole('button', { name: 'Approve' }).click();
    await expect(reviewerPage.getByText('No absence justifications need review.')).toBeVisible();
    await reviewerContext.close();
    await page.context().close();
});
