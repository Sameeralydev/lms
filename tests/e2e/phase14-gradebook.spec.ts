import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

async function signIn(page: Page, email?: string, password?: string): Promise<void> {
    if (!email || !password) throw new Error('Guarded Phase 14 browser fixture credentials are missing.');
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(workspaces|t\/[^/]+\/dashboard)$/);
}

test('Phase 14 grade policy, assessment, independent review and released result work end to end', async ({
    browser,
}) => {
    test.setTimeout(900_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    const periodId = process.env.E2E_PHASE14_ACADEMIC_PERIOD_ID;
    const sectionId = process.env.E2E_PHASE14_SECTION_ID;
    if (!tenant || !periodId || !sectionId)
        throw new Error('Guarded Phase 14 grading fixtures were not provisioned.');
    const root = `/t/${tenant}/academics/grades`;
    const context = await browser.newContext();
    const instructor = await context.newPage();
    const errors: string[] = [];
    instructor.on('pageerror', (error) => errors.push(error.message));
    await signIn(
        instructor,
        process.env.E2E_REGISTRATION_OWNER_EMAIL,
        process.env.E2E_REGISTRATION_OWNER_PASSWORD,
    );
    await instructor.goto(root);
    await expect(instructor.getByRole('heading', { name: 'Gradebooks', exact: true })).toBeVisible();

    const policyName = `E2E Phase 14 ${process.env.E2E_RUN_ID}`;
    await instructor.getByLabel('Policy name').fill(policyName);
    await instructor.getByLabel('Policy key').fill(`E2E-${process.env.E2E_RUN_ID}`);
    await instructor.getByLabel('Academic period').selectOption(periodId);
    await instructor.getByLabel('Scale').selectOption('pass_fail');
    const policyResponsePromise = instructor.waitForResponse(
        (response) =>
            response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/policies'),
    );
    await instructor.getByRole('button', { name: 'Save policy draft' }).click();
    const policyResponse = await policyResponsePromise;
    expect([302, 303]).toContain(policyResponse.status());
    await expect(instructor.getByText(policyName)).toBeVisible();
    await instructor.getByRole('button', { name: 'Activate policy' }).click();
    await expect(instructor.getByText(/pass fail · v1 · active/i)).toBeVisible();

    await instructor.getByLabel('Assigned section').selectOption(sectionId);
    const policyOption = instructor
        .getByLabel('Active policy')
        .locator('option')
        .filter({ hasText: policyName });
    const policyId = await policyOption.getAttribute('value');
    expect(policyId).toBeTruthy();
    await instructor.getByLabel('Active policy').selectOption(policyId!);
    await instructor.getByRole('button', { name: 'Create gradebook' }).click();
    await expect(instructor).toHaveURL(new RegExp(`${root}/gradebooks/\\d+$`));
    const gradebookUrl = instructor.url();

    for (const [key, title, category] of [
        ['COURSEWORK', 'Coursework score', 'Coursework'],
        ['EXAM', 'Examination score', 'Examination'],
    ]) {
        await instructor.getByLabel('Item key').fill(key);
        await instructor.getByLabel('Assessment name').fill(title);
        await instructor.getByLabel('Category').selectOption({ label: category });
        await instructor.getByLabel('Maximum points').fill('100');
        await instructor.getByRole('button', { name: 'Add item' }).click();
        await expect(instructor.getByLabel(title)).toBeVisible();
        await instructor.getByLabel(title).fill('80');
        await instructor
            .getByLabel(title)
            .locator('xpath=ancestor::form[1]')
            .getByRole('button', { name: 'Save' })
            .click();
    }
    await instructor.getByRole('button', { name: 'Submit for review' }).click();
    await expect(instructor.getByText(/submitted/)).toBeVisible();
    await instructor.goto(gradebookUrl);
    const gradebookHeading = await instructor.getByRole('heading', { level: 1 }).innerText();
    const [courseName, sectionCode] = gradebookHeading.split('·').map((value) => value.trim());
    const themes = [
        'executive-navy',
        'academic-emerald',
        'modern-indigo',
        'warm-ivory',
        'graphite-dark',
        'midnight-violet',
    ];
    const evidenceDirectory = resolve(process.cwd(), 'test-results', 'phase14-gradebook-theme-evidence');
    mkdirSync(evidenceDirectory, { recursive: true });
    for (const theme of themes) {
        await instructor.getByLabel('Choose theme').selectOption(theme);
        await expect(instructor.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        const audit = await new AxeBuilder({ page: instructor })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(audit.violations).toEqual([]);
        await instructor.screenshot({
            path: resolve(evidenceDirectory, `instructor-${theme}.png`),
            fullPage: true,
        });
    }
    const reviewerContext = await browser.newContext();
    const reviewer = await reviewerContext.newPage();
    reviewer.on('pageerror', (error) => errors.push(error.message));
    await signIn(
        reviewer,
        process.env.E2E_SCHEDULING_REVIEWER_EMAIL,
        process.env.E2E_SCHEDULING_REVIEWER_PASSWORD,
    );
    await reviewer.goto(gradebookUrl);
    await reviewer.getByRole('button', { name: 'Approve gradebook' }).click();
    await expect(reviewer.getByRole('button', { name: 'Publish grades' })).toBeVisible();
    await reviewer.getByRole('button', { name: 'Publish grades' }).click();
    await expect(reviewer.getByText(/published/)).toBeVisible();

    const recordsRoot = `/t/${tenant}/academics/records`;
    await instructor.goto(`/t/${tenant}/registrar/enrollment`);
    await expect(instructor.getByRole('heading', { name: 'Enrollment administration' })).toBeVisible();
    const matchingOffering = instructor.locator('article').filter({ hasText: courseName }).first();
    const matchingSection = matchingOffering
        .locator('li')
        .filter({ hasText: `Section ${sectionCode}:` })
        .first();
    const instructorSelect = matchingSection.getByLabel('Assign instructor');
    const ownerOption = instructorSelect
        .locator('option')
        .filter({ hasText: process.env.E2E_REGISTRATION_OWNER_EMAIL! });
    await expect(ownerOption).toHaveCount(1);
    await instructorSelect.selectOption((await ownerOption.getAttribute('value'))!);
    await matchingSection.getByRole('button', { name: 'Assign instructor' }).click();
    const commentsRoot = `/t/${tenant}/academics/report-card-comments`;
    await instructor.goto(commentsRoot);
    await expect(instructor.getByRole('heading', { name: 'Report-card comments' })).toBeVisible();
    const commentAssignment = instructor.getByRole('combobox', { name: 'Assigned learner and section' });
    const commentOption = commentAssignment.locator('option').filter({ hasText: 'Phase 08 Learner' });
    await expect(commentOption).toHaveCount(1);
    await commentAssignment.selectOption((await commentOption.getAttribute('value'))!);
    const reportCardComment = `Phase 14 learner comment ${process.env.E2E_RUN_ID} shows steady progress.`;
    await instructor.getByLabel(/^Comment/).fill(reportCardComment);
    await instructor.getByRole('button', { name: 'Submit for review' }).click();
    await expect(instructor.getByText(reportCardComment, { exact: true })).toBeVisible();
    await reviewer.goto(recordsRoot);
    await expect(reviewer.getByRole('heading', { name: 'Submitted instructor comments' })).toBeVisible();
    await expect(reviewer.getByText(reportCardComment)).toBeVisible();
    await reviewer.getByRole('button', { name: 'Approve' }).first().click();
    await instructor.goto(recordsRoot);
    const reportCardTemplateName = `E2E report card ${process.env.E2E_RUN_ID}`;
    await instructor.getByLabel('Template name').fill(reportCardTemplateName);
    await instructor.getByLabel('Template key').fill(`E2E-REPORT-CARD-${process.env.E2E_RUN_ID}`);
    await instructor.getByLabel('Record type').selectOption('report_card');
    await instructor.getByRole('button', { name: 'Save template draft' }).click();
    await instructor.getByRole('button', { name: 'Activate version' }).first().click();
    const reportStudent = instructor.getByRole('combobox', { name: 'Student' });
    const reportStudentOption = reportStudent.locator('option').filter({ hasText: 'Phase 08 Learner' });
    await reportStudent.selectOption((await reportStudentOption.getAttribute('value'))!);
    const reportTemplateOption = instructor
        .getByLabel('Active template')
        .locator('option')
        .filter({ hasText: reportCardTemplateName });
    await instructor
        .getByLabel('Active template')
        .selectOption((await reportTemplateOption.getAttribute('value'))!);
    await instructor.getByLabel('Academic period').selectOption(periodId);
    await instructor.getByRole('button', { name: 'Prepare draft' }).click();
    await expect(instructor.getByText(/report_card #1.*draft/i)).toBeVisible();
    await instructor.getByRole('button', { name: 'Submit for approval' }).click();
    await reviewer.goto(recordsRoot);
    await reviewer.getByRole('button', { name: 'Approve' }).first().click();
    await instructor.goto(recordsRoot);
    await instructor.getByRole('button', { name: 'Issue record' }).first().click();
    await expect(instructor.getByText(/report_card #1.*issued/i)).toBeVisible();

    await instructor.getByLabel('Template name').fill(`E2E transcript ${process.env.E2E_RUN_ID}`);
    await instructor.getByLabel('Template key').fill(`E2E-TRANSCRIPT-${process.env.E2E_RUN_ID}`);
    await instructor.getByLabel('Record type').selectOption('transcript');
    await instructor.getByRole('button', { name: 'Save template draft' }).click();
    await expect(
        instructor.getByText(
            new RegExp(`E2E transcript ${process.env.E2E_RUN_ID} \\u00b7 transcript v1 \\u00b7 draft`),
        ),
    ).toBeVisible();
    await instructor.getByRole('button', { name: 'Activate version' }).click();
    await expect(instructor.getByText(/transcript v1 · active/i)).toBeVisible();
    const recordStudent = instructor.getByRole('combobox', { name: 'Student' });
    const registrationStudentOption = recordStudent.locator('option').filter({ hasText: 'Phase 08 Learner' });
    await expect(registrationStudentOption).toHaveCount(1);
    const registrationStudentId = await registrationStudentOption.getAttribute('value');
    expect(registrationStudentId).toBeTruthy();
    await recordStudent.selectOption(registrationStudentId!);
    await instructor.getByLabel('Active template').selectOption({ index: 1 });
    await instructor.getByRole('button', { name: 'Prepare draft' }).click();
    await expect(instructor.getByText(/transcript #1.*draft/i)).toBeVisible();
    await instructor.getByRole('button', { name: 'Submit for approval' }).click();
    await reviewer.goto(recordsRoot);
    await expect(reviewer.getByRole('heading', { name: 'Official academic records' })).toBeVisible();
    await reviewer.getByRole('button', { name: 'Approve' }).click();
    await expect(reviewer.getByText(/transcript #1.*approved/i)).toBeVisible();
    await instructor.goto(recordsRoot);
    await instructor.getByRole('button', { name: 'Issue record' }).click();
    await expect(instructor.getByText(/transcript #1.*issued/i)).toBeVisible();

    const certificateTemplateName = `E2E certificate ${process.env.E2E_RUN_ID}`;
    await instructor.getByLabel('Template name').fill(certificateTemplateName);
    await instructor.getByLabel('Template key').fill(`E2E-CERTIFICATE-${process.env.E2E_RUN_ID}`);
    await instructor.getByLabel('Record type').selectOption('certificate');
    await instructor.getByRole('button', { name: 'Save template draft' }).click();
    await instructor.getByRole('button', { name: 'Activate version' }).last().click();
    await instructor.getByRole('combobox', { name: 'Student' }).selectOption(registrationStudentId!);
    const certificateTemplate = instructor
        .getByLabel('Active template')
        .locator('option')
        .filter({ hasText: certificateTemplateName });
    await instructor
        .getByLabel('Active template')
        .selectOption((await certificateTemplate.getAttribute('value'))!);
    await instructor.getByRole('button', { name: 'Prepare draft' }).click();
    await expect(instructor.getByText(/certificate #1.*draft/i)).toBeVisible();
    await instructor.getByRole('button', { name: 'Submit for approval' }).click();
    await reviewer.goto(recordsRoot);
    await reviewer.getByRole('button', { name: 'Approve' }).click();
    await instructor.goto(recordsRoot);
    await instructor.getByRole('button', { name: 'Issue record' }).click();
    await expect(instructor.getByText(/certificate #1.*issued/i)).toBeVisible();

    const reportsRoot = `/t/${tenant}/academics/reports`;
    await instructor.goto(reportsRoot);
    await expect(instructor.getByRole('heading', { name: 'Academic operations reports' })).toBeVisible();
    await expect(instructor.getByRole('heading', { name: 'Official record activity' })).toBeVisible();
    const reportCsv = await instructor.request.get(
        `${reportsRoot}/records.csv?record_type=transcript&status=issued`,
    );
    expect(reportCsv.status()).toBe(200);
    expect(reportCsv.headers()['content-type']).toContain('text/csv');
    expect((await reportCsv.text()).toLowerCase()).toContain('transcript');

    for (const theme of themes) {
        await instructor.getByLabel('Choose theme').selectOption(theme);
        await expect(instructor.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        const reportAudit = await new AxeBuilder({ page: instructor })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(reportAudit.violations).toEqual([]);
        await instructor.screenshot({
            path: resolve(evidenceDirectory, `registrar-reports-${theme}.png`),
            fullPage: true,
        });
        await reviewer.getByLabel('Choose theme').selectOption(theme);
        await expect(reviewer.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        const audit = await new AxeBuilder({ page: reviewer })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(audit.violations).toEqual([]);
        await reviewer.screenshot({
            path: resolve(evidenceDirectory, `reviewer-${theme}.png`),
            fullPage: true,
        });
    }
    await instructor.goto(`/t/${tenant}/academics/gpa`);
    await expect(instructor.getByRole('heading', { name: 'GPA and academic standing' })).toBeVisible();
    const gpaPolicyName = `E2E GPA ${process.env.E2E_RUN_ID}`;
    instructor.on('dialog', (dialog) => dialog.accept());
    await instructor.getByLabel('Policy name').fill(gpaPolicyName);
    await instructor.getByLabel('Policy key').fill(`E2E-GPA-${process.env.E2E_RUN_ID}`);
    await instructor.getByRole('button', { name: 'Save policy draft' }).click();
    await expect(instructor.getByText(gpaPolicyName)).toBeVisible();
    await instructor.getByRole('button', { name: 'Activate policy' }).click();
    await expect(instructor.getByText(/active · repeated course/i)).toBeVisible();
    for (const theme of themes) {
        await instructor.getByLabel('Choose theme').selectOption(theme);
        await expect(instructor.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        const audit = await new AxeBuilder({ page: instructor })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(audit.violations).toEqual([]);
        await instructor.screenshot({
            path: resolve(evidenceDirectory, `owner-gpa-${theme}.png`),
            fullPage: true,
        });
    }

    const studentContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const student = await studentContext.newPage();
    student.on('pageerror', (error) => errors.push(error.message));
    await signIn(
        student,
        process.env.E2E_REGISTRATION_STUDENT_EMAIL,
        process.env.E2E_REGISTRATION_STUDENT_PASSWORD,
    );
    await student.goto(`/t/${tenant}/academic-records/my-grades`);
    await expect(student.getByRole('heading', { name: 'My grades' })).toBeVisible();
    await expect(student.getByLabel('Official published results')).toContainText('Pass');
    await expect(student.getByLabel('Published grades')).toContainText(policyName);
    await student.goto(`/t/${tenant}/academic-records/my-progress`);
    await expect(student.getByRole('heading', { name: 'My academic progress' })).toBeVisible();
    await expect(student.getByLabel('Academic progress empty state')).toBeVisible();
    await student.getByRole('link', { name: 'Skip to content' }).focus();
    await student.keyboard.press('Tab');
    expect(await student.evaluate(() => document.activeElement !== document.body)).toBe(true);
    for (const theme of themes) {
        await student.getByLabel('Choose theme').selectOption(theme);
        await expect(student.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        await student.reload();
        await expect(student.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        expect(await student.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
            true,
        );
        const audit = await new AxeBuilder({ page: student })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(audit.violations).toEqual([]);
        await student.screenshot({
            path: resolve(evidenceDirectory, `student-gpa-${theme}.png`),
            fullPage: true,
        });
    }
    await student.goto(`/t/${tenant}/academic-records`);
    await expect(student.getByRole('heading', { name: 'My academic records' })).toBeVisible();
    await expect(student.getByText(/transcript.*issue 1/i)).toBeVisible();
    await expect(student.getByText(/report card.*issue 1/i)).toBeVisible();
    expect(await student.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
        true,
    );
    await student.getByRole('link', { name: 'Skip to content' }).focus();
    await student.keyboard.press('Tab');
    expect(await student.evaluate(() => document.activeElement !== document.body)).toBe(true);
    const transcriptRow = student.getByRole('listitem').filter({ hasText: /transcript/i });
    const pdfHref = await transcriptRow
        .getByRole('link', { name: 'Download secure PDF' })
        .getAttribute('href');
    expect(pdfHref).toBeTruthy();
    const pdf = await student.request.get(pdfHref!);
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toContain('application/pdf');
    expect((await pdf.body()).toString('latin1').startsWith('%PDF-1.4')).toBe(true);
    const reportCardRow = student.getByRole('listitem').filter({ hasText: /report card.*issue 1/i });
    const reportCardHref = await reportCardRow
        .getByRole('link', { name: 'Download secure PDF' })
        .getAttribute('href');
    expect(reportCardHref).toBeTruthy();
    const reportCardPdf = await student.request.get(reportCardHref!);
    expect(reportCardPdf.status()).toBe(200);
    const reportCardPdfText = (await reportCardPdf.body()).toString('latin1');
    expect(reportCardPdfText).toContain(reportCardComment.slice(0, 60));
    expect(reportCardPdfText).toContain('progress.');
    const certificateRow = student.getByRole('listitem').filter({ hasText: /certificate/i });
    const certificateHref = await certificateRow
        .getByRole('link', { name: 'Download secure PDF' })
        .getAttribute('href');
    expect(certificateHref).toBeTruthy();
    const certificatePdf = await student.request.get(certificateHref!);
    expect(certificatePdf.status()).toBe(200);
    const certificatePdfText = (await certificatePdf.body()).toString('latin1');
    expect(certificatePdfText).toContain('Verify at:');
    expect(certificatePdfText).toContain(' re f');
    const verificationHref = await certificateRow
        .getByRole('link', { name: 'Verify certificate' })
        .getAttribute('href');
    expect(verificationHref).toBeTruthy();
    const verificationPage = await studentContext.newPage();
    await verificationPage.goto(verificationHref!);
    await expect(verificationPage.getByRole('status')).toContainText(/Valid certificate/i);
    expect(new URL(verificationPage.url()).pathname).toMatch(/^\/academic-records\/verify\/[0-9a-f-]{36}$/i);
    for (const theme of themes) {
        await student.getByLabel('Choose theme').selectOption(theme);
        await expect(student.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        const audit = await new AxeBuilder({ page: student })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(audit.violations).toEqual([]);
        await student.screenshot({
            path: resolve(evidenceDirectory, `student-records-${theme}.png`),
            fullPage: true,
        });
    }
    const guardianContext = await browser.newContext();
    const guardian = await guardianContext.newPage();
    await signIn(guardian, process.env.E2E_GUARDIAN_EMAIL, process.env.E2E_GUARDIAN_PASSWORD);
    await expect(guardian.getByRole('heading', { name: 'My institution memberships' })).toBeVisible();
    await guardian.getByRole('button', { name: 'Open workspace' }).click();
    const guardianRecordsLink = guardian.getByRole('link', { name: 'My academic records' });
    await expect(guardianRecordsLink).toBeVisible();
    await guardianRecordsLink.click();
    await expect(guardian.getByRole('heading', { name: 'My academic records' })).toBeVisible();
    await expect(guardian.getByRole('listitem')).toHaveCount(3);
    await expect(guardian.getByText(/report card.*issue 1/i)).toBeVisible();
    await expect(guardian.getByText(/transcript.*issue 1/i)).toBeVisible();
    await expect(guardian.getByText(/certificate.*issue 1/i)).toBeVisible();
    for (const theme of themes) {
        await guardian.getByLabel('Choose theme').selectOption(theme);
        await expect(guardian.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        await guardian.reload();
        await expect(guardian.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        const audit = await new AxeBuilder({ page: guardian })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(audit.violations).toEqual([]);
        expect(await guardian.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
            true,
        );
        await guardian.screenshot({
            path: resolve(evidenceDirectory, `guardian-records-${theme}.png`),
            fullPage: true,
        });
    }
    const guardianGradesLink = guardian.getByRole('link', { name: 'Learner grades' });
    await expect(guardianGradesLink).toBeVisible();
    await guardianGradesLink.click();
    await expect(guardian.getByRole('heading', { name: 'Learner grades' })).toBeVisible();
    await expect(guardian.getByRole('table', { name: /current published grade snapshots/i })).toContainText(
        'Pass',
    );
    await guardian.getByRole('link', { name: 'Skip to content' }).focus();
    await guardian.keyboard.press('Tab');
    expect(await guardian.evaluate(() => document.activeElement !== document.body)).toBe(true);
    for (const theme of themes) {
        await guardian.getByLabel('Choose theme').selectOption(theme);
        await expect(guardian.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        await guardian.reload();
        await expect(guardian.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        await expect(
            guardian.getByRole('table', { name: /current published grade snapshots/i }),
        ).toContainText('Pass');
        expect(await guardian.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
            true,
        );
        const audit = await new AxeBuilder({ page: guardian })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(audit.violations).toEqual([]);
        await guardian.screenshot({
            path: resolve(evidenceDirectory, `guardian-grades-${theme}.png`),
            fullPage: true,
        });
    }
    await guardianContext.close();
    await student.goto(`/t/${tenant}/academic-records/my-grades`);
    expect(errors).toEqual([]);
    await studentContext.close();
    await reviewerContext.close();
    await context.close();
});
