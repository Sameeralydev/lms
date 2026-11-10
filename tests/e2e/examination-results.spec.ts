import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

async function signIn(page: import('@playwright/test').Page, email?: string, password?: string) {
    if (!email || !password) throw new Error('Guarded Phase 13 fixture credentials are missing.');
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(workspaces|t\/[^/]+\/dashboard)$/);
}

test('result workspaces are authenticated, scoped, responsive and theme-compatible', async ({ browser }) => {
    test.setTimeout(240_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    if (!tenant) throw new Error('Guarded E2E tenant was not provisioned.');
    const root = `/t/${tenant}`;
    const controllerContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const controller = await controllerContext.newPage();
    const browserErrors: string[] = [];
    controller.on('pageerror', (error) => browserErrors.push(error.message));
    await signIn(
        controller,
        process.env.E2E_REGISTRATION_OWNER_EMAIL,
        process.env.E2E_REGISTRATION_OWNER_PASSWORD,
    );
    const controllerResponse = await controller.goto(`${root}/academics/examinations/results`);
    expect(controllerResponse?.status()).toBe(200);
    if ((await controller.getByRole('heading', { name: 'Results & appeals' }).count()) === 0) {
        throw new Error(
            `Unexpected result workspace at ${controller.url()}: ${(await controller.locator('body').innerText()).slice(0, 1200)} Browser errors: ${browserErrors.join(' | ')}`,
        );
    }
    await expect(controller.getByRole('heading', { name: 'Results & appeals' })).toBeVisible();
    await expect(controller.getByRole('heading', { name: 'Released result distribution' })).toBeVisible();
    await expect(controller.getByRole('heading', { name: 'Examination operations report' })).toBeVisible();
    // The results workspace has separate session selectors for result policy
    // and incident reporting; assert the policy selector without violating
    // Playwright's strict locator mode.
    await expect(controller.getByLabel('Examination session').first()).toBeVisible();
    await expect(
        controller.getByRole('link', { name: 'Download scoped result register (CSV)' }),
    ).toBeVisible();
    const incidentSummary = `Browser examination network incident ${Date.now()}`;
    const incidentForm = controller
        .locator('section')
        .filter({ has: controller.getByRole('heading', { name: 'Incident register' }) })
        .locator('form')
        .first();
    await incidentForm.getByLabel('Factual incident description').fill(incidentSummary);
    const reportResponsePromise = controller.waitForResponse(
        (response) =>
            response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/incidents'),
    );
    await incidentForm.getByRole('button', { name: 'Report examination incident' }).click();
    const reportResponse = await reportResponsePromise;
    expect([200, 302, 303]).toContain(reportResponse.status());
    await controller.reload();
    const incidentRow = controller.getByRole('listitem').filter({ hasText: incidentSummary });
    await expect(incidentRow).toContainText('reported');
    const incidentWorkflow = incidentRow.getByRole('form', { name: /Update incident/ });
    await incidentWorkflow.getByLabel('Incident action').selectOption('assign');
    await incidentWorkflow.getByLabel('Assign to reviewer').selectOption({ index: 1 });
    await incidentWorkflow
        .getByLabel('Reason / investigation note')
        .fill('Assigned to the authorized controller for review.');
    await incidentWorkflow.getByRole('button', { name: 'Save incident update' }).click();
    for (const [action, note] of [
        ['escalate', 'Escalated for operational review.'],
        ['investigate', 'Investigation started.'],
        ['resolve', 'The issue was corrected and verified.'],
        ['close', 'Corrective actions are complete.'],
        ['reopen', 'New evidence requires investigation.'],
    ]) {
        await incidentWorkflow.getByLabel('Incident action').selectOption(action);
        await incidentWorkflow.getByLabel('Reason / investigation note').fill(note);
        await incidentWorkflow.getByRole('button', { name: 'Save incident update' }).click();
    }
    await expect(incidentRow).toContainText('investigating');
    await expect(incidentRow.getByRole('list').last()).toContainText('reopened');
    await controller.getByRole('link', { name: 'Skip to content' }).focus();
    await controller.keyboard.press('Tab');
    expect(await controller.evaluate(() => document.activeElement !== document.body)).toBe(true);
    for (const theme of [
        'executive-navy',
        'academic-emerald',
        'modern-indigo',
        'warm-ivory',
        'graphite-dark',
        'midnight-violet',
    ]) {
        await controller.getByLabel('Choose theme').selectOption(theme);
        await expect(controller.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        const audit = await new AxeBuilder({ page: controller })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(audit.violations).toEqual([]);
        expect(
            await controller.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
        ).toBe(true);
    }
    await controllerContext.close();

    const studentContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const student = await studentContext.newPage();
    await signIn(
        student,
        process.env.E2E_REGISTRATION_STUDENT_EMAIL,
        process.env.E2E_REGISTRATION_STUDENT_PASSWORD,
    );
    const studentResponse = await student.goto(`${root}/examinations/results`);
    expect(studentResponse?.status()).toBe(200);
    await expect(student.getByRole('heading', { name: 'My examination results' })).toBeVisible();
    await expect(student.getByRole('heading', { name: 'E2E Online Examination' })).toBeVisible();
    await expect(student.locator('#main-content')).toContainText('Recheck request: submitted');
    const appeal = student.locator('article').filter({ hasText: 'E2E Online Examination' });
    const evidenceInput = appeal.getByLabel('Appeal evidence (PDF or image, up to 10 MB)');
    await evidenceInput.setInputFiles({
        name: 'appeal-clean.pdf',
        mimeType: 'application/pdf',
        buffer: Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF'),
    });
    await appeal.getByRole('button', { name: 'Upload to quarantine' }).click();
    const cleanEvidenceLink = appeal.getByRole('link', { name: 'Download' });
    await expect
        .poll(
            async () => {
                await student.reload();
                return await student.locator('#main-content').innerText();
            },
            { timeout: 45_000 },
        )
        .toContain('appeal-clean.pdf · clean');
    await expect(cleanEvidenceLink).toBeVisible();
    const cleanUrl = await cleanEvidenceLink.getAttribute('href');
    expect(cleanUrl).toContain('/examinations/appeal-evidence/');
    const cleanDownload = await student.request.get(new URL(cleanUrl!, student.url()).toString());
    expect(cleanDownload.status()).toBe(200);

    const eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    await evidenceInput.setInputFiles({
        name: 'appeal-eicar.pdf',
        mimeType: 'application/pdf',
        buffer: Buffer.from(
            `%PDF-1.4\n1 0 obj\n<< /Length ${eicar.length} >>\nstream\n${eicar}\nendstream\nendobj\n%%EOF`,
        ),
    });
    await appeal.getByRole('button', { name: 'Upload to quarantine' }).click();
    await expect
        .poll(
            async () => {
                await student.reload();
                return await student.locator('#main-content').innerText();
            },
            { timeout: 45_000 },
        )
        .toContain('appeal-eicar.pdf · infected');
    await expect(appeal.getByRole('link', { name: 'Download' })).toHaveCount(1);

    const wrongStudentContext = await browser.newContext();
    const wrongStudent = await wrongStudentContext.newPage();
    await signIn(
        wrongStudent,
        process.env.E2E_SCHEDULING_STUDENT_EMAIL,
        process.env.E2E_SCHEDULING_STUDENT_PASSWORD,
    );
    const denied = await wrongStudent.request.get(new URL(cleanUrl!, wrongStudent.url()).toString());
    expect([403, 404]).toContain(denied.status());
    await wrongStudentContext.close();
    await student.getByRole('link', { name: 'Skip to content' }).focus();
    await student.keyboard.press('Tab');
    expect(await student.evaluate(() => document.activeElement !== document.body)).toBe(true);
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
        const audit = await new AxeBuilder({ page: student })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(audit.violations).toEqual([]);
        expect(await student.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
            true,
        );
    }
    await studentContext.close();
});
