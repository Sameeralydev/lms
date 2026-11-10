import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

const themes = [
    'executive-navy',
    'academic-emerald',
    'modern-indigo',
    'warm-ivory',
    'graphite-dark',
    'midnight-violet',
];

test('student accommodation, independent review and private admit document journey', async ({ browser }) => {
    test.setTimeout(240_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    const studentEmail = process.env.E2E_REGISTRATION_STUDENT_EMAIL;
    const studentPassword = process.env.E2E_REGISTRATION_STUDENT_PASSWORD;
    const ownerEmail = process.env.E2E_REGISTRATION_OWNER_EMAIL;
    const ownerPassword = process.env.E2E_REGISTRATION_OWNER_PASSWORD;
    if (!tenant || !studentEmail || !studentPassword || !ownerEmail || !ownerPassword) {
        throw new Error('Guarded Phase 13 candidate governance fixtures are missing.');
    }

    const root = `/t/${tenant}`;
    const studentContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const student = await studentContext.newPage();
    const errors: string[] = [];
    student.on('pageerror', (error) => errors.push(error.message));
    await signIn(student, studentEmail, studentPassword);
    expect((await student.goto(`${root}/examinations/my-governance`))?.status()).toBe(200);
    const candidate = student.locator('article').filter({ hasText: 'E2E formal exam cycle' });
    await expect(candidate).toBeVisible();
    await candidate.getByLabel('Additional minutes').fill('15');
    await candidate
        .getByLabel('Confidential supporting details')
        .fill('Accessible assessment support requested.');
    await candidate.getByRole('button', { name: 'Submit confidential request' }).click();
    await expect(candidate.getByText(/Accommodation request.*pending/)).toBeVisible();

    for (const theme of themes) {
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
    await student.keyboard.press('Tab');
    await expect(student.locator(':focus')).toBeVisible();

    const ownerContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const owner = await ownerContext.newPage();
    owner.on('pageerror', (error) => errors.push(error.message));
    await signIn(owner, ownerEmail, ownerPassword);
    expect((await owner.goto(`${root}/academics/examinations/candidate-governance`))?.status()).toBe(200);
    const request = owner.locator('article').filter({ hasText: 'extra time' }).filter({ hasText: 'pending' });
    await expect(request).toBeVisible();
    await owner.getByLabel('Decision reason').fill('Reviewed the candidate request independently.');
    const expiry = new Date(Date.now() + 3 * 24 * 60 * 60 * 1000);
    const localExpiry = new Date(expiry.getTime() - expiry.getTimezoneOffset() * 60_000)
        .toISOString()
        .slice(0, 16);
    await owner.getByLabel('Approval expiry').fill(localExpiry);
    await request.getByRole('button', { name: 'Approve' }).click();
    await expect(owner.locator('article').filter({ hasText: /extra time.*approved/ })).toBeVisible();
    const issuance = owner.locator('article').filter({ hasText: 'E2E Online Examination' });
    await issuance.getByRole('button', { name: 'Issue admit document' }).click();
    await expect(owner.getByRole('heading', { name: 'Issue admit documents' })).toBeVisible();

    for (const theme of themes) {
        await owner.getByLabel('Choose theme').selectOption(theme);
        await expect(owner.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        const audit = await new AxeBuilder({ page: owner })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(audit.violations).toEqual([]);
        expect(await owner.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
            true,
        );
    }

    await student.reload();
    const issued = student.getByRole('link', { name: 'Open admit document' });
    await expect(issued).toBeVisible();
    await issued.click();
    await expect(student.getByRole('heading', { name: 'Examination admit document' })).toBeVisible();
    await expect(
        student.getByRole('region', { name: 'Admit document details' }).getByText('Verification code'),
    ).toBeVisible();
    await expect(
        owner.getByText('Confidential details: Accessible assessment support requested.'),
    ).toBeVisible();
    expect(errors).toEqual([]);
    await studentContext.close();
    await ownerContext.close();
});

test('authorized examination staff report incidents and upload evidence into private scan quarantine', async ({
    browser,
}) => {
    test.setTimeout(180_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    const ownerEmail = process.env.E2E_REGISTRATION_OWNER_EMAIL;
    const ownerPassword = process.env.E2E_REGISTRATION_OWNER_PASSWORD;
    if (!tenant || !ownerEmail || !ownerPassword) {
        throw new Error('Guarded Phase 13 incident fixtures are missing.');
    }
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await signIn(page, ownerEmail, ownerPassword);
    const root = `/t/${tenant}/academics/examinations`;
    expect((await page.goto(`${root}/results`))?.status()).toBe(200);
    const report = page.getByRole('button', { name: 'Report examination incident' });
    await expect(report).toBeVisible();
    const reportForm = page.locator('form').filter({ has: report });
    await reportForm.getByLabel('Examination session').selectOption({ label: 'E2E Online Examination' });
    await reportForm.getByLabel('Incident type').selectOption('technical');
    await reportForm.getByLabel('Severity').selectOption('urgent');
    const description = `E2E incident ${Date.now()}: online examination controls stopped responding.`;
    await reportForm.getByLabel('Factual incident description').fill(description);
    await report.click();
    const incident = page.locator('li').filter({ hasText: description });
    await expect(incident).toBeVisible();
    await incident.locator('input[type="file"]').setInputFiles({
        name: 'incident-evidence.pdf',
        mimeType: 'application/pdf',
        buffer: Buffer.from('%PDF-1.4\nTechMentors test evidence\n%%EOF'),
    });
    await incident.getByRole('button', { name: 'Upload to quarantine' }).click();
    if (process.env.E2E_CLAMAV_ENABLED === 'true') {
        // Scanning is intentionally queued after the upload transaction commits.
        // Poll a fresh server-rendered Inertia response so this assertion proves
        // the persisted scan outcome rather than relying on stale page props.
        await expect
            .poll(
                async () => {
                    await page.reload();
                    return await incident.innerText();
                },
                { timeout: 45_000, intervals: [250, 500, 1_000, 2_000] },
            )
            .toMatch(/incident-evidence\.pdf.*verified clean/);
        await expect(incident.getByRole('link', { name: /incident-evidence\.pdf/ })).toBeVisible();
        const eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        await incident.locator('input[type="file"]').setInputFiles({
            name: 'infected-eicar.pdf',
            mimeType: 'application/pdf',
            buffer: Buffer.from(
                `%PDF-1.4\n1 0 obj\n<< /Length ${eicar.length} >>\nstream\n${eicar}\nendstream\nendobj\n%%EOF`,
            ),
        });
        await incident.getByRole('button', { name: 'Upload to quarantine' }).click();
        await expect
            .poll(
                async () => {
                    await page.reload();
                    return await incident.innerText();
                },
                { timeout: 45_000, intervals: [250, 500, 1_000, 2_000] },
            )
            .toMatch(/infected-eicar\.pdf.*infected;/);
        await expect(incident.getByRole('link', { name: /infected-eicar\.pdf/ })).toHaveCount(0);
    } else {
        await expect(
            incident.getByText(
                /incident-evidence\.pdf.*unavailable; unavailable until scan passes|incident-evidence\.pdf.*quarantined; unavailable until scan passes/,
            ),
        ).toBeVisible();
        await expect(incident.getByRole('link', { name: /incident-evidence\.pdf/ })).toHaveCount(0);
    }
    for (const theme of themes) {
        await page.getByLabel('Choose theme').selectOption(theme);
        await expect(page.locator('[data-theme]')).toHaveAttribute('data-theme', theme);
        const audit = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(audit.violations).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
            true,
        );
    }
    await page.keyboard.press('Tab');
    await expect(page.locator(':focus')).toBeVisible();
    expect(errors).toEqual([]);
    await context.close();
});

async function signIn(page: import('@playwright/test').Page, email: string, password: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(workspaces|t\/[^/]+\/dashboard)$/);
}
