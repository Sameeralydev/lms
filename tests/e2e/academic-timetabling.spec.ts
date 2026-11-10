import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

async function signIn(page: Page, email?: string, password?: string) {
    if (!email || !password)
        throw new Error('The isolated browser setup did not provide Phase 09 role credentials.');
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(workspaces|t\/[^/]+\/dashboard)$/);
}

test('registrar requests an occurrence reschedule, independent reviewer approves, and instructor sees the effective schedule', async ({
    page,
    browser,
}) => {
    test.setTimeout(300_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    if (!tenant) throw new Error('The isolated browser setup did not provide a Phase 09 tenant.');
    const consoleErrors: string[] = [];
    page.on('pageerror', (error) => consoleErrors.push(error.message));
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page, process.env.E2E_REGISTRATION_OWNER_EMAIL, process.env.E2E_REGISTRATION_OWNER_PASSWORD);
    const response = await page.goto(`/t/${tenant}/academics/timetables`);
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Academic timetables' })).toBeVisible();
    await expect(page.getByRole('region', { name: /E2E Published Timetable week view/ })).toBeVisible();
    await page.keyboard.press('Tab');
    const visibleKeyboardFocus = await page.evaluate(() => {
        const active = document.activeElement;
        const styles = active ? getComputedStyle(active) : null;
        return {
            tagName: active?.tagName ?? 'BODY',
            outlineWidth: styles?.outlineWidth ?? '0px',
            outlineStyle: styles?.outlineStyle ?? 'none',
        };
    });
    expect(visibleKeyboardFocus.tagName).not.toBe('BODY');
    expect(parseFloat(visibleKeyboardFocus.outlineWidth)).toBeGreaterThan(0);
    expect(visibleKeyboardFocus.outlineStyle).not.toBe('none');
    await page.getByRole('button', { name: 'Day view' }).click();
    await expect(page.getByRole('region', { name: /E2E Published Timetable day view/ })).toBeVisible();
    const timetableCard = page.locator('article').filter({ hasText: 'E2E Published Timetable' }).first();
    await timetableCard.getByLabel('Change type').selectOption('reschedule');
    const originalDate = await timetableCard.getByLabel('Original occurrence').inputValue();
    const replacement = new Date(`${originalDate}T12:00:00.000Z`);
    replacement.setUTCDate(replacement.getUTCDate() + 1);
    const replacementDate = replacement.toISOString().slice(0, 10);
    await timetableCard.getByLabel('New date').fill(replacementDate);
    await timetableCard
        .getByRole('form', { name: /Request a change for/ })
        .getByLabel('Reason')
        .fill('Campus room maintenance requires a new class date.');
    await timetableCard.getByRole('button', { name: 'Submit for independent approval' }).click();
    await expect(page.getByText('Schedule change submitted for independent approval.')).toBeVisible();
    await expect(page.getByText(/pending/).first()).toBeVisible();

    const reviewerContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const reviewerPage = await reviewerContext.newPage();
    await signIn(
        reviewerPage,
        process.env.E2E_SCHEDULING_REVIEWER_EMAIL,
        process.env.E2E_SCHEDULING_REVIEWER_PASSWORD,
    );
    await reviewerPage.goto(`/t/${tenant}/academics/timetables`);
    await expect(reviewerPage.getByRole('heading', { name: 'Occurrence change history' })).toBeVisible();
    await reviewerPage.getByRole('button', { name: 'Approve' }).click();
    await expect(reviewerPage.getByRole('listitem').filter({ hasText: 'Reschedule' }).last()).toContainText(
        'approved',
    );
    await reviewerPage.goto(`/t/${tenant}/academics/timetables/analytics`);
    await expect(reviewerPage.getByRole('heading', { name: 'Scheduling analytics' })).toBeVisible();
    await expect(reviewerPage.getByText('Open sections without a published meeting')).toBeVisible();
    const analyticsAxe = await new AxeBuilder({ page: reviewerPage })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    expect(analyticsAxe.violations).toEqual([]);
    await reviewerContext.close();

    const instructorContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const instructorPage = await instructorContext.newPage();
    await signIn(
        instructorPage,
        process.env.E2E_REGISTRATION_INSTRUCTOR_EMAIL,
        process.env.E2E_REGISTRATION_INSTRUCTOR_PASSWORD,
    );
    const scheduleResponse = await instructorPage.goto(`/t/${tenant}/instructor/schedule`);
    expect(scheduleResponse?.status()).toBe(200);
    await expect(instructorPage.getByRole('heading', { name: 'My teaching timetable' })).toBeVisible();
    await expect(instructorPage.getByRole('list', { name: 'Upcoming class occurrences' })).toContainText(
        replacementDate,
    );
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
        await expect(themePicker).toHaveValue(theme);
        const scheduleAxe = await new AxeBuilder({ page: instructorPage })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(scheduleAxe.violations, `Accessibility violations under ${theme}`).toEqual([]);
    }
    const dimensions = await instructorPage.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        content: document.documentElement.scrollWidth,
    }));
    expect(dimensions.content).toBeLessThanOrEqual(dimensions.viewport);
    await instructorPage.keyboard.press('Tab');
    expect(await instructorPage.evaluate(() => document.activeElement?.tagName)).not.toBe('BODY');
    expect(consoleErrors).toEqual([]);

    await page.goto(`/t/${tenant}/academics/timetables`);
    const editorThemePicker = page.getByLabel('Choose theme');
    for (const theme of themes) {
        await editorThemePicker.selectOption(theme);
        await expect(editorThemePicker).toHaveValue(theme);
        const editorAxe = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(editorAxe.violations, `Timetable editor accessibility violations under ${theme}`).toEqual([]);
    }
    const editorOverflow = await page.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        content: document.documentElement.scrollWidth,
    }));
    expect(editorOverflow.content).toBeLessThanOrEqual(editorOverflow.viewport);

    const operatingRulesForm = page
        .locator('form')
        .filter({ has: page.getByRole('heading', { name: 'Operating rules' }) });
    const occurrenceWeekday = new Date(`${originalDate}T12:00:00.000Z`).getUTCDay();
    const weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    await operatingRulesForm.getByLabel(weekdayLabels[occurrenceWeekday]).check();
    await operatingRulesForm.getByRole('button', { name: 'Save operating rules' }).click();
    await expect(page.getByText('Scheduling rules saved.')).toBeVisible();

    const buildingForm = page
        .locator('form')
        .filter({ has: page.getByRole('heading', { name: 'Campus buildings' }) });
    await buildingForm.getByLabel('Building code').fill('E2E-BLOCK');
    await buildingForm.getByLabel('Building name').fill('E2E Academic Block');
    await buildingForm.getByRole('button', { name: 'Add building' }).click();
    await expect(page.getByText('Building created.')).toBeVisible();
    await buildingForm.getByLabel('Building name').fill('E2E Duplicate Academic Block');
    await buildingForm.getByRole('button', { name: 'Add building' }).click();
    await expect(buildingForm.getByRole('alert')).toContainText(/code has already been taken/i);
    await buildingForm.getByLabel('Building code').fill('E2E-BLOCK-2');
    await buildingForm.getByLabel('Building name').fill('E2E Seminar Block');
    await buildingForm.getByRole('button', { name: 'Add building' }).click();
    await expect(page.getByText('Building created.')).toBeVisible();
    const roomForm = page
        .locator('form')
        .filter({ has: page.getByRole('heading', { name: 'Room directory' }) });
    await roomForm.getByLabel('Building').selectOption({ label: 'E2E Seminar Block' });
    await roomForm.getByLabel('Room code').fill('E2E-ROOM-2');
    await roomForm.getByLabel('Room name').fill('E2E Seminar Room');
    await roomForm.getByRole('button', { name: 'Add room' }).click();
    await expect(page.getByText('Room created.')).toBeVisible();

    const availabilityForm = page
        .locator('form')
        .filter({ has: page.getByRole('heading', { name: 'Instructor availability' }) });
    const availabilityStart = new Date();
    availabilityStart.setDate(availabilityStart.getDate() + 30);
    const availabilityEnd = new Date(availabilityStart);
    availabilityEnd.setDate(availabilityEnd.getDate() + 30);
    await availabilityForm.getByLabel('Effective from').fill(availabilityStart.toISOString().slice(0, 10));
    await availabilityForm.getByLabel('Through').fill(availabilityEnd.toISOString().slice(0, 10));
    await availabilityForm.getByLabel('From', { exact: true }).fill('18:00');
    await availabilityForm.getByLabel('Until', { exact: true }).fill('19:00');
    await availabilityForm.getByRole('button', { name: 'Save availability' }).click();
    await expect(page.getByText('Instructor availability recorded.')).toBeVisible();

    await timetableCard.getByRole('button', { name: 'Create next draft version' }).click();
    await expect(page.getByText('Revision 2 created as a draft.')).toBeVisible();
    const draft = page.locator('article').filter({ hasText: 'E2E Published Timetable v2' });
    await expect(draft).toHaveCount(1);
    await draft.getByRole('button', { name: 'Edit draft' }).click();
    await draft.getByRole('button', { name: 'Edit recurring series' }).click();
    const seriesEditor = draft.locator('form[id^="meeting-edit-"]');
    await seriesEditor.getByLabel('Local start time').fill('11:00');
    await seriesEditor.getByRole('button', { name: 'Preview conflicts' }).click();
    await expect(seriesEditor.getByRole('status')).toContainText('No conflicts found');
    await seriesEditor.getByRole('button', { name: 'Save series changes' }).click();
    await expect(
        page.getByText(
            'Recurring series updated in the draft timetable. The published version remains unchanged.',
        ),
    ).toBeVisible();

    const addMeeting = draft.locator('form#timetable-create-meeting');
    const schedulingSectionOption = addMeeting
        .getByLabel('Section')
        .locator('option')
        .filter({ hasText: '· SCHED ·' });
    await addMeeting
        .getByLabel('Section')
        .selectOption((await schedulingSectionOption.first().getAttribute('value')) ?? '');
    const seminarRoomOption = addMeeting
        .getByLabel('Room')
        .locator('option')
        .filter({ hasText: 'E2E Seminar Room' });
    await addMeeting.getByLabel('Room').selectOption((await seminarRoomOption.getAttribute('value')) ?? '');
    const firstClassDate = new Date();
    firstClassDate.setUTCHours(12, 0, 0, 0);
    firstClassDate.setUTCDate(firstClassDate.getUTCDate() + 1);
    const firstClassDateText = firstClassDate.toISOString().slice(0, 10);
    await addMeeting.getByLabel('First occurrence').fill(firstClassDateText);
    const lastClassDate = new Date(firstClassDate);
    lastClassDate.setUTCDate(lastClassDate.getUTCDate() + 14);
    await addMeeting.getByLabel('Last occurrence').fill(lastClassDate.toISOString().slice(0, 10));
    await addMeeting.getByLabel('Local start time').fill('16:00');
    const meetingWeekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const meetingDay = meetingWeekdays[(firstClassDate.getUTCDay() + 6) % 7];
    for (const weekday of meetingWeekdays) {
        const checkbox = addMeeting.getByLabel(weekday);
        if (weekday !== meetingDay && (await checkbox.isChecked())) {
            await checkbox.uncheck();
        }
    }
    await addMeeting.getByLabel(meetingDay).check();
    const conflictPreview = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/conflicts'),
    );
    await addMeeting.getByRole('button', { name: 'Preview conflicts' }).click();
    const conflictPreviewResponse = await conflictPreview;
    const conflictPreviewBody = await conflictPreviewResponse.text();
    expect(
        conflictPreviewResponse.ok(),
        `Conflict preview returned ${conflictPreviewResponse.status()}: ${conflictPreviewBody.slice(0, 1200)}`,
    ).toBe(true);
    const previewConflicts = (JSON.parse(conflictPreviewBody) as { conflicts: unknown[] }).conflicts;
    expect(previewConflicts, 'Conflict preview returned unexpected existing conflicts').toEqual([]);
    await expect(addMeeting.getByRole('status')).toContainText('No conflicts found');
    await addMeeting.getByRole('button', { name: 'Save meeting' }).click();
    await expect(page.getByText('Recurring class meeting added to the draft timetable.')).toBeVisible();

    await addMeeting.getByLabel('First occurrence').fill(firstClassDateText);
    await addMeeting.getByLabel('Last occurrence').fill(lastClassDate.toISOString().slice(0, 10));
    await addMeeting.getByLabel('Local start time').fill('16:00');
    await addMeeting.getByRole('button', { name: 'Preview conflicts' }).click();
    await expect(addMeeting.getByRole('alert')).toContainText(/instructor_double_booking/);
    await expect(addMeeting.getByRole('alert')).toContainText(/room_double_booking/);

    await draft.getByRole('button', { name: 'Submit for review' }).click();
    await expect(draft).toContainText('review');
    const publishReviewerContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const publishReviewerPage = await publishReviewerContext.newPage();
    await signIn(
        publishReviewerPage,
        process.env.E2E_SCHEDULING_REVIEWER_EMAIL,
        process.env.E2E_SCHEDULING_REVIEWER_PASSWORD,
    );
    await publishReviewerPage.goto(`/t/${tenant}/academics/timetables`);
    const reviewDraft = publishReviewerPage
        .locator('article')
        .filter({ hasText: 'E2E Published Timetable v2' });
    await reviewDraft.getByRole('button', { name: 'Approve' }).click();
    await expect(reviewDraft).toContainText('approved');
    await reviewDraft.getByRole('button', { name: 'Publish' }).click();
    await expect(reviewDraft).toContainText('published');
    await publishReviewerContext.close();

    await page.goto(`/t/${tenant}/academics/timetables`);
    const publishedTimetable = page.locator('article').filter({ hasText: 'E2E Published Timetable v2' });
    await expect(publishedTimetable).toBeVisible();
    const scheduleChangeForm = publishedTimetable.getByRole('form', {
        name: 'Request a change for E2E-SCHED-201 SCHED',
    });
    const submitChange = async (changeType: 'substitution' | 'cancellation' | 'makeup') => {
        const history = page.getByRole('region', { name: 'Occurrence change history' });
        const historyCount = await history.getByRole('listitem').count();
        const responsePromise = page.waitForResponse((response) => {
            const request = response.request();
            if (
                request.method() !== 'POST' ||
                !response.url().includes('/academics/timetable-meetings/') ||
                !response.url().endsWith('/changes')
            ) {
                return false;
            }
            try {
                return request.postDataJSON()?.change_type === changeType;
            } catch {
                return false;
            }
        });
        await scheduleChangeForm.getByRole('button', { name: 'Submit for independent approval' }).click();
        const response = await responsePromise;
        expect([302, 303]).toContain(response.status());
        await expect(history.getByRole('listitem'))
            .toHaveCount(historyCount + 1, { timeout: 10_000 })
            .catch(async () => {
                const errors = await scheduleChangeForm.getByRole('alert').allTextContents();
                throw new Error(
                    `${changeType} request did not create a history record (HTTP ${response.status()}); ${errors.join(' | ') || 'no form error was rendered'}.`,
                );
            });
        await expect(page.getByText('Schedule change submitted for independent approval.')).toBeVisible();
        await expect(page.getByRole('region', { name: 'Occurrence change history' })).toContainText(
            new RegExp(`${changeType}.*E2E-SCHED-201 / SCHED`, 'i'),
        );
        const alert = scheduleChangeForm.getByRole('alert');
        if (await alert.isVisible()) {
            throw new Error(`${changeType} request was rejected: ${await alert.innerText()}`);
        }
        return response;
    };
    const firstScheduleDate = firstClassDate.toISOString().slice(0, 10);
    const nextScheduleDate = new Date(firstClassDate);
    nextScheduleDate.setUTCDate(nextScheduleDate.getUTCDate() + 7);
    const nextScheduleDateText = nextScheduleDate.toISOString().slice(0, 10);
    await scheduleChangeForm.getByLabel('Change type').selectOption('substitution');
    await scheduleChangeForm.getByLabel('Original occurrence').fill(firstScheduleDate);
    await scheduleChangeForm.getByLabel('New date').fill(firstScheduleDate);
    await scheduleChangeForm.locator('select').nth(1).selectOption({
        label: 'Phase 09 E2E Substitute Instructor',
        timeout: 10_000,
    });
    await scheduleChangeForm
        .getByLabel('Reason')
        .fill('The assigned instructor is unavailable for this occurrence.');
    await submitChange('substitution');

    await scheduleChangeForm.getByLabel('Change type').selectOption('cancellation');
    await scheduleChangeForm.getByLabel('Original occurrence').fill(nextScheduleDateText);
    await scheduleChangeForm
        .getByLabel('Reason')
        .fill('The campus has a verified closure on this occurrence.');
    await submitChange('cancellation');

    const makeupDate = new Date(lastClassDate);
    // Place the replacement on the configured teaching weekday after the
    // recurring series ends, so it is both operationally valid and conflict-free.
    makeupDate.setUTCDate(makeupDate.getUTCDate() + 7);
    await scheduleChangeForm.getByLabel('Change type').selectOption('makeup');
    const scheduledInstructor = scheduleChangeForm
        .locator('select')
        .nth(1)
        .locator('option', { hasText: 'Phase 09 E2E Scheduling Instructor' });
    const scheduledInstructorId = Number(await scheduledInstructor.getAttribute('value'));
    await scheduleChangeForm.locator('select').nth(1).selectOption({
        label: 'Phase 09 E2E Scheduling Instructor',
    });
    await scheduleChangeForm.getByLabel('Original occurrence').fill(nextScheduleDateText);
    await scheduleChangeForm.getByLabel('Makeup date').fill(makeupDate.toISOString().slice(0, 10));
    await scheduleChangeForm
        .getByLabel('Reason')
        .fill('A makeup meeting replaces the approved campus closure.');
    const makeupResponse = await submitChange('makeup');
    const makeupPayload = makeupResponse.request().postDataJSON() as Record<string, unknown>;
    expect(makeupPayload).toMatchObject({
        change_type: 'makeup',
        occurrence_date: nextScheduleDateText,
        replacement_date: makeupDate.toISOString().slice(0, 10),
        instructor_user_id: scheduledInstructorId,
    });
    await expect(page.getByRole('region', { name: 'Occurrence change history' })).toContainText(
        /makeup.*E2E-SCHED-201 \/ SCHED/i,
    );

    const changeReviewerContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const changeReviewerPage = await changeReviewerContext.newPage();
    await signIn(
        changeReviewerPage,
        process.env.E2E_SCHEDULING_REVIEWER_EMAIL,
        process.env.E2E_SCHEDULING_REVIEWER_PASSWORD,
    );
    await changeReviewerPage.goto(`/t/${tenant}/academics/timetables`);
    for (const changeType of ['substitution', 'cancellation', 'makeup']) {
        const pendingChange = changeReviewerPage
            .getByRole('listitem')
            .filter({ hasText: new RegExp(`${changeType}.*E2E-SCHED-201 / SCHED`, 'i') })
            .last();
        await expect(pendingChange).toContainText('pending');
        await pendingChange.getByRole('button', { name: 'Approve' }).click();
        await expect(pendingChange).toContainText('approved');
    }
    await changeReviewerPage.goto(`/t/${tenant}/academics/timetables/analytics`);
    await expect(changeReviewerPage.getByRole('heading', { name: 'Scheduling analytics' })).toBeVisible();
    for (const changeType of ['substitution', 'cancellation', 'makeup']) {
        await expect(
            changeReviewerPage.getByRole('listitem').filter({ hasText: new RegExp(`${changeType}: 1`, 'i') }),
        ).toBeVisible();
    }
    for (const theme of themes) {
        const analyticsTheme = changeReviewerPage.getByLabel('Choose theme');
        await analyticsTheme.selectOption(theme);
        await expect(analyticsTheme).toHaveValue(theme);
        const analyticsThemeAxe = await new AxeBuilder({ page: changeReviewerPage })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(analyticsThemeAxe.violations, `Analytics accessibility violations under ${theme}`).toEqual([]);
    }
    await changeReviewerContext.close();

    const learnerContext = await browser.newContext({
        viewport: { width: 390, height: 844 },
        reducedMotion: 'reduce',
    });
    const learnerPage = await learnerContext.newPage();
    await signIn(
        learnerPage,
        process.env.E2E_SCHEDULING_STUDENT_EMAIL,
        process.env.E2E_SCHEDULING_STUDENT_PASSWORD,
    );
    const learnerResponse = await learnerPage.goto(`/t/${tenant}/schedule`);
    expect(learnerResponse?.status()).toBe(200);
    await expect(learnerPage.getByRole('heading', { name: 'My class timetable' })).toBeVisible();
    await expect(learnerPage.getByRole('list', { name: 'Upcoming class occurrences' })).toContainText(
        'SCHED',
    );
    await expect(learnerPage.getByRole('list', { name: 'Upcoming class occurrences' })).toContainText(
        'Phase 09 E2E Substitute Instructor',
    );
    await expect(learnerPage.getByRole('list', { name: 'Upcoming class occurrences' })).toContainText(
        makeupDate.toISOString().slice(0, 10),
    );
    const learnerAxe = await new AxeBuilder({ page: learnerPage })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    expect(learnerAxe.violations).toEqual([]);
    const learnerOverflow = await learnerPage.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        content: document.documentElement.scrollWidth,
    }));
    expect(learnerOverflow.content).toBeLessThanOrEqual(learnerOverflow.viewport);
    for (const theme of themes) {
        const learnerTheme = learnerPage.getByLabel('Choose theme');
        await learnerTheme.selectOption(theme);
        await expect(learnerTheme).toHaveValue(theme);
        const themedAxe = await new AxeBuilder({ page: learnerPage })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(themedAxe.violations, `Learner timetable accessibility violations under ${theme}`).toEqual([]);
    }
    const csvDownload = learnerPage.waitForEvent('download');
    await learnerPage.getByRole('link', { name: 'Download CSV' }).click();
    expect((await csvDownload).suggestedFilename()).toBe('timetable.csv');
    const icsDownload = learnerPage.waitForEvent('download');
    await learnerPage.getByRole('link', { name: 'Add to calendar (.ics)' }).click();
    expect((await icsDownload).suggestedFilename()).toBe('timetable.ics');
    await learnerContext.close();

    const otherTenant = process.env.E2E_TENANT_SLUG;
    if (otherTenant) {
        const crossTenantResponse = await page.goto(`/t/${otherTenant}/academics/timetables`);
        expect(crossTenantResponse?.status()).toBe(404);
    }

    await page.goto(`/t/${tenant}/academics/timetables`);
    const publishedV2 = page.locator('article').filter({ hasText: 'E2E Published Timetable v2' });
    await publishedV2.getByRole('button', { name: 'Create next draft version' }).click();
    await expect(page.getByText('Revision 3 created as a draft.')).toBeVisible();
    const cancellationDraft = page.locator('article').filter({ hasText: 'E2E Published Timetable v3' });
    await cancellationDraft.getByRole('button', { name: 'Edit draft' }).click();
    await cancellationDraft.getByRole('button', { name: 'Edit recurring series' }).first().click();
    await cancellationDraft
        .getByLabel('Cancellation reason')
        .first()
        .fill('The draft series is no longer required after review.');
    await cancellationDraft.getByRole('button', { name: 'Cancel series in draft' }).first().click();
    await expect(
        page.getByText(
            'Recurring series canceled in the draft timetable. The published version remains unchanged.',
        ),
    ).toBeVisible();

    await instructorContext.close();
});
