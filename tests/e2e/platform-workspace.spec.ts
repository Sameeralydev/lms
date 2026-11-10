import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';

const baseURL = 'http://127.0.0.1:8017';
const tenantSlug = process.env.E2E_TENANT_SLUG;
const runId = process.env.E2E_RUN_ID;
const realClamAvE2E = process.env.E2E_CLAMAV_ENABLED === 'true';
const foreignTenantSlug = tenantSlug ? `${tenantSlug}-foreign` : undefined;
let invitedMemberPage: Page | undefined;
let invitedMemberContext: BrowserContext | undefined;
let platformAdminPage: Page | undefined;
let platformAdminContext: BrowserContext | undefined;
let institutionOwnerPage: Page | undefined;
let institutionOwnerContext: BrowserContext | undefined;
let institutionOwnerPassword: string | undefined;
let transferOwnerContext: BrowserContext | undefined;
let promotionReviewerContext: BrowserContext | undefined;
let submittedApplicationNumber: string | undefined;
let staffApplicationPath: string | undefined;
const auxiliaryContexts: BrowserContext[] = [];

function dateTimeInputInTimezone(date: Date, timezone: string): string {
    const values = Object.fromEntries(
        new Intl.DateTimeFormat('en-US', {
            timeZone: timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(date)
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, part.value]),
    );

    return `${values.year}-${values.month}-${values.day}T${values.hour}:${values.minute}`;
}

async function inviteAndAcceptMember(
    browser: Browser,
    ownerPage: Page,
    tenant: string,
    run: string,
    email: string,
    role: string,
    name: string,
): Promise<Page> {
    await ownerPage.goto(`/t/${tenant}/members`);
    await ownerPage.getByLabel('Email address').fill(email);
    await ownerPage.getByLabel('Institution role').selectOption(role);
    const invitationResponse = ownerPage.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            response.url().endsWith(`/t/${tenant}/members/invitations`),
    );
    await ownerPage.getByRole('button', { name: 'Send invitation' }).click();
    const invitation = await invitationResponse;
    expect(invitation.status()).toBe(302);
    const acceptanceUrl = await invitation.headerValue('x-e2e-invitation-url');
    expect(acceptanceUrl).toBeTruthy();
    const context = await browser.newContext({ baseURL });
    auxiliaryContexts.push(context);
    const memberPage = await context.newPage();
    await memberPage.goto(acceptanceUrl!);
    await expect(memberPage.getByRole('heading', { name: /Join .* Institute/ })).toBeVisible();
    const password = `Learning-${run}-${role}-42!`;
    await memberPage.getByLabel('Your name').fill(name);
    await memberPage.getByLabel('Create password').fill(password);
    await memberPage.getByLabel('Confirm password').fill(password);
    await memberPage.getByRole('button', { name: 'Create account and join' }).click();
    await expect(memberPage).toHaveURL(new RegExp(`(/t/${tenant}/dashboard|/workspaces)$`));

    return memberPage;
}

async function signIn(
    page: Page,
    email = process.env.E2E_ADMIN_EMAIL,
    password = process.env.E2E_ADMIN_PASSWORD,
) {
    if (!email || !password) {
        throw new Error('Playwright credentials were not supplied by the isolated database setup.');
    }

    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(platform\/dashboard|workspaces)$/);
}

async function getPlatformAdminPage(browser: Browser): Promise<Page> {
    if (!platformAdminPage) {
        platformAdminContext = await browser.newContext({ baseURL });
        platformAdminPage = await platformAdminContext.newPage();
        await signIn(platformAdminPage);
    }

    return platformAdminPage;
}

async function getInstitutionOwnerPage(browser: Browser): Promise<Page> {
    if (!institutionOwnerPage) {
        if (!institutionOwnerPassword)
            throw new Error('The institution owner has not accepted the invitation.');
        institutionOwnerContext = await browser.newContext({ baseURL });
        institutionOwnerPage = await institutionOwnerContext.newPage();
        await signIn(institutionOwnerPage, process.env.E2E_OWNER_EMAIL, institutionOwnerPassword);
    }

    return institutionOwnerPage;
}

test.describe('authenticated platform and institution workflows', () => {
    test.describe.configure({ mode: 'serial' });

    test.afterAll(async () => {
        await Promise.all(auxiliaryContexts.map((context) => context.close()));
        await invitedMemberContext?.close();
        await transferOwnerContext?.close();
        await promotionReviewerContext?.close();
        await institutionOwnerContext?.close();
        await platformAdminContext?.close();
    });

    test('platform administrator signs in and provisions an institution', async ({ browser }) => {
        if (!tenantSlug || !runId) throw new Error('The test tenant slug was not provisioned.');
        const page = await getPlatformAdminPage(browser);

        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (message) => {
            if (message.type() === 'error') errors.push(message.text());
        });

        await expect(page).toHaveURL(/\/platform\/dashboard$/);
        await expect(page.getByRole('heading', { name: 'Platform overview' })).toBeVisible();
        await page.getByRole('link', { name: 'Create institution' }).click();
        await page.getByLabel('Official institution name').fill(`Phase 02 Test Institute ${runId}`);
        await page.getByLabel('Display name').fill(`Phase 02 Institute ${runId}`);
        await page.getByLabel('Preferred workspace slug').fill(tenantSlug);
        await page.getByLabel('Institution type').selectOption('academy');
        await page.getByLabel('Country code (optional)').fill('PK');
        await page.getByLabel('Initial institution owner email').fill(process.env.E2E_OWNER_EMAIL!);
        const ownerInvitationResponse = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' && response.url().endsWith('/platform/tenants'),
        );
        await page.getByRole('button', { name: 'Provision institution' }).click();
        const ownerResponse = await ownerInvitationResponse;

        await expect(page).toHaveURL(new RegExp(`/platform/tenants/${tenantSlug}$`));
        await expect(page.getByRole('heading', { name: `Phase 02 Institute ${runId}` })).toBeVisible();
        await expect(page.getByText('active', { exact: true })).toBeVisible();
        await expect(page.getByText(process.env.E2E_OWNER_EMAIL!, { exact: true })).toBeVisible();
        await expect(page.getByText('pending', { exact: true })).toBeVisible();
        expect((await page.request.get(`/t/${tenantSlug}/dashboard`)).status()).toBe(404);

        const ownerInvitationUrl = await ownerResponse.headerValue('x-e2e-invitation-url');
        expect(ownerInvitationUrl).toBeTruthy();

        await page.goto('/platform/tenants/create');
        await page.getByLabel('Official institution name').fill(`Cross Tenant Institute ${runId}`);
        await page.getByLabel('Display name').fill(`Cross Tenant ${runId}`);
        await page.getByLabel('Preferred workspace slug').fill(foreignTenantSlug!);
        await page.getByLabel('Institution type').selectOption('university');
        await page.getByLabel('Initial institution owner email').fill(`foreign-owner-${runId}@example.test`);
        await page.getByRole('button', { name: 'Provision institution' }).click();
        await expect(page).toHaveURL(new RegExp(`/platform/tenants/${foreignTenantSlug}$`));
        await expect(page.getByRole('heading', { name: `Cross Tenant ${runId}` })).toBeVisible();

        institutionOwnerPassword = `Phase02-${runId}-Owner-Password-42!`;
        institutionOwnerContext = await browser.newContext({ baseURL });
        institutionOwnerPage = await institutionOwnerContext.newPage();
        await institutionOwnerPage.goto(ownerInvitationUrl!);
        await expect(
            institutionOwnerPage.getByRole('heading', { name: /Join Phase 02 Institute/ }),
        ).toBeVisible();
        await institutionOwnerPage.getByLabel('Your name').fill(`Institution Owner ${runId}`);
        await institutionOwnerPage.getByLabel('Create password').fill(institutionOwnerPassword);
        await institutionOwnerPage.getByLabel('Confirm password').fill(institutionOwnerPassword);
        await institutionOwnerPage.getByRole('button', { name: 'Create account and join' }).click();
        await expect(institutionOwnerPage).toHaveURL(new RegExp(`/t/${tenantSlug}/dashboard$`));
        await expect(
            institutionOwnerPage.getByRole('heading', { name: 'Institution administration' }),
        ).toBeVisible();
        expect(errors).toEqual([]);
    });

    test('institution owner creates and authors a curriculum-linked course delivery workspace', async ({
        browser,
    }) => {
        // This authenticated workflow includes two real ClamAV scans followed
        // by a six-screen/six-theme accessibility matrix. Keep it bounded, but
        // do not let scanner startup time consume the UI verification window.
        test.setTimeout(900_000);
        if (!tenantSlug || !runId) throw new Error('The test tenant was not provisioned.');
        const page = await getInstitutionOwnerPage(browser);
        const courseCode = `LD-${runId}`;
        const courseTitle = `Learning delivery ${runId}`;
        const versionTitle = `${courseTitle} published catalog version`;

        await page.goto(`/t/${tenantSlug}/academics/catalog`);
        await page.getByLabel('Course title').fill(courseTitle);
        await page.getByLabel('Course code').fill(courseCode);
        await page.getByRole('button', { name: 'Add course' }).click();
        const courseCard = page.locator('article').filter({ hasText: courseCode });
        await expect(courseCard.getByRole('heading', { name: courseTitle })).toBeVisible();
        await courseCard.getByText('Manage versions and learning outcomes').click();
        await courseCard.getByLabel('Version title').fill(versionTitle);
        await courseCard.getByRole('button', { name: 'Create immutable draft version' }).click();
        await expect(courseCard.getByText(versionTitle)).toBeVisible();
        page.once('dialog', (dialog) => dialog.accept());
        await courseCard.getByRole('button', { name: 'Publish', exact: true }).click();
        await expect(courseCard.getByText(/published/)).toBeVisible();

        await page.goto(`/t/${tenantSlug}/learning`);
        const version = page.getByLabel('Published course version');
        const versionId = await version
            .locator('option')
            .filter({ hasText: courseCode })
            .getAttribute('value');
        expect(versionId).toBeTruthy();
        await version.selectOption(versionId!);
        await page.getByLabel('Workspace code').fill(`LD-${runId}-DELIVERY`);
        await page.getByLabel('Workspace title').fill(`${courseTitle} workspace`);
        await page.getByLabel('Delivery model').selectOption('self_paced');
        await page.getByRole('button', { name: 'Create workspace' }).click();
        await expect(page).toHaveURL(new RegExp(`/t/${tenantSlug}/learning/\\d+$`));
        await expect(page.getByRole('heading', { name: `${courseTitle} workspace` })).toBeVisible();

        const builderAccessibility = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(builderAccessibility.violations).toEqual([]);
        await page.getByLabel('Institution member').selectOption({ index: 1 });
        await page.getByRole('button', { name: 'Assign member' }).click();
        await expect(page.getByRole('list').getByText('instructor', { exact: true })).toBeVisible();
        await page.getByLabel('Title').fill(`Live session ${runId}`);
        await page.getByLabel('Assigned instructor').selectOption({ index: 1 });
        await page.getByLabel('Format').selectOption('online');
        await page.getByLabel('Time zone').fill('Asia/Karachi');
        await page.getByLabel('Starts at').fill('2031-05-01T09:00');
        await page.getByLabel('Ends at').fill('2031-05-01T10:00');
        await page.getByLabel('Secure meeting link (HTTPS)').fill('https://meet.example.test/live-session');
        await page.getByRole('button', { name: 'Schedule live session' }).click();
        const liveSession = page.getByRole('listitem').filter({ hasText: `Live session ${runId}` });
        await expect(liveSession).toContainText('scheduled');
        await liveSession.getByRole('button', { name: 'Reschedule or edit' }).click();
        await liveSession.getByLabel('Starts at').fill('2031-05-02T11:00');
        await liveSession.getByLabel('Ends at').fill('2031-05-02T12:00');
        await liveSession.getByRole('button', { name: 'Save session changes' }).click();
        await liveSession.getByLabel('Cancellation reason').fill('E2E session cancelled after rescheduling.');
        await liveSession.getByRole('button', { name: 'Cancel session' }).click();
        await expect(liveSession).toContainText('cancelled');

        const authoredWorkspaceUrl = page.url();
        await page.goto(`/t/${tenantSlug}/learning/sessions`);
        await page.getByLabel('From').fill('2031-05-02');
        await page.getByLabel('To').fill('2031-05-03');
        await page.getByLabel('Status').selectOption('cancelled');
        await page.getByRole('button', { name: 'Apply filters' }).click();
        const calendarEntry = page.getByRole('listitem').filter({ hasText: `Live session ${runId}` });
        await expect(calendarEntry).toContainText('cancelled');
        await expect(calendarEntry.getByRole('link', { name: 'Join session' })).toHaveCount(0);
        const sessionCalendarAccessibility = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(sessionCalendarAccessibility.violations).toEqual([]);
        await page.goto(authoredWorkspaceUrl);

        await page.getByLabel('New module').fill('Course orientation');
        await page.getByRole('button', { name: 'Add module' }).click();
        await expect(page.getByRole('heading', { name: 'Course orientation' })).toBeVisible();
        await page.getByLabel('New lesson').fill('Getting started');
        await page.getByLabel('Objective').fill('Find the essential course information.');
        await page.getByRole('button', { name: 'Add lesson' }).click();
        await expect(page.getByRole('heading', { name: 'Getting started' })).toBeVisible();
        await page.getByLabel('Text content').fill('This lesson introduces the course workspace.');
        await page.getByLabel('Lesson sequence').selectOption('sequential');
        await page
            .getByLabel('Release date and time')
            .fill(dateTimeInputInTimezone(new Date(Date.now() + 60 * 60 * 1000), 'UTC'));
        await page.getByLabel('Require an instructor to confirm learner completion').check();
        await expect(page.getByLabel('Instructor release preview')).toContainText('Scheduled for');
        await page.getByRole('button', { name: 'Save draft' }).click();
        await expect(page.getByRole('status')).toContainText('Draft lesson saved');
        await expect(page.getByLabel('Lesson sequence')).toHaveValue('sequential');
        const mediaLibraryAccessibility = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(mediaLibraryAccessibility.violations).toEqual([]);
        if (realClamAvE2E) {
            await page.locator('input[type="file"]').setInputFiles({
                name: `learning-resource-${runId}.txt`,
                mimeType: 'text/plain',
                buffer: Buffer.from('Accessible course resource, version one.'),
            });
            await page.getByLabel('License or permission').fill('Institution owned training material');
            await page.getByRole('button', { name: 'Upload and scan' }).click();
            const mediaList = page.getByRole('list', { name: 'Attached media scan status' });
            await expect(mediaList).toContainText(`learning-resource-${runId}.txt`, { timeout: 90_000 });
            await expect(mediaList).toContainText('Version 1');
            await expect(
                mediaList.locator('li').filter({ hasText: `learning-resource-${runId}.txt` }),
            ).toContainText('clean', { timeout: 90_000 });
            await page
                .getByLabel('Replace existing version (optional)')
                .selectOption({ label: `learning-resource-${runId}.txt (v1)` });
            await page.locator('input[type="file"]').setInputFiles({
                name: `learning-resource-${runId}-v2.txt`,
                mimeType: 'text/plain',
                buffer: Buffer.from('Accessible course resource, version two.'),
            });
            await expect
                .poll(() =>
                    page
                        .locator('input[type="file"]')
                        .evaluate((input: HTMLInputElement) => input.files?.[0]?.name),
                )
                .toBe(`learning-resource-${runId}-v2.txt`);
            await page.getByLabel('License or permission').fill('Institution owned training material');
            const replacementResponsePromise = page.waitForResponse(
                (response) => response.request().method() === 'POST' && response.url().endsWith('/media'),
            );
            await page.getByRole('button', { name: 'Upload and scan' }).click();
            const replacementResponse = await replacementResponsePromise;
            expect(replacementResponse.status()).toBe(302);
            await expect(mediaList).toContainText(`learning-resource-${runId}-v2.txt`, { timeout: 90_000 });
            await expect(mediaList).toContainText('Version 2');
            await expect(
                mediaList.locator('li').filter({ hasText: `learning-resource-${runId}-v2.txt` }),
            ).toContainText('clean', { timeout: 90_000 });
        }
        await page.getByRole('button', { name: 'Submit for review' }).click();
        await expect(page.getByText('in review', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Move module Course orientation up' })).toBeDisabled();
        await expect(page.getByRole('button', { name: 'Move lesson Getting started up' })).toBeDisabled();

        const reviewerName = `Learning Reviewer ${runId}`;
        const reviewerPage = await inviteAndAcceptMember(
            browser,
            page,
            tenantSlug,
            runId,
            `learning-reviewer-${runId}@example.test`,
            'academic.admin',
            reviewerName,
        );
        await page.goto(authoredWorkspaceUrl);
        await page.getByLabel('Course role').selectOption('reviewer');
        await page.getByLabel('Institution member').selectOption({ label: reviewerName });
        await page.getByRole('button', { name: 'Assign member' }).click();
        await reviewerPage.goto(authoredWorkspaceUrl);
        await expect(reviewerPage.getByRole('button', { name: 'Record review' })).toBeVisible();
        await reviewerPage.getByRole('button', { name: 'Record review' }).click();
        await expect(reviewerPage.getByText('approved', { exact: true })).toBeVisible();
        const publisherName = `Learning Publisher ${runId}`;
        const publisherPage = await inviteAndAcceptMember(
            browser,
            page,
            tenantSlug,
            runId,
            `learning-publisher-${runId}@example.test`,
            'tenant.admin',
            publisherName,
        );
        await page.goto(authoredWorkspaceUrl);
        await page.getByLabel('Course role').selectOption('publisher');
        await page.getByLabel('Institution member').selectOption({ label: publisherName });
        await page.getByRole('button', { name: 'Assign member' }).click();
        await publisherPage.goto(authoredWorkspaceUrl);
        await publisherPage.getByRole('button', { name: 'Publish reviewed version' }).click();
        await expect(publisherPage.getByText('published', { exact: true }).first()).toBeVisible();
        await page.goto(authoredWorkspaceUrl);
        await page.getByRole('button', { name: 'Publish workspace' }).click();
        await expect(page.getByRole('status')).toContainText('Workspace published.');

        const teacherName = `Learning Instructor ${runId}`;
        const teacherPage = await inviteAndAcceptMember(
            browser,
            page,
            tenantSlug,
            runId,
            `learning-instructor-${runId}@example.test`,
            'teacher',
            teacherName,
        );
        const learnerName = `Learning Learner ${runId}`;
        const learnerPage = await inviteAndAcceptMember(
            browser,
            page,
            tenantSlug,
            runId,
            `learning-learner-${runId}@example.test`,
            'student',
            learnerName,
        );
        await page.goto(authoredWorkspaceUrl);
        await expect(page.getByLabel('Course role')).toBeVisible({ timeout: 15_000 });
        await page.getByLabel('Course role').selectOption('instructor');
        await page.getByLabel('Institution member').selectOption({ label: teacherName });
        await page.getByRole('button', { name: 'Assign member' }).click();
        await expect(page.getByRole('status')).toContainText('Scoped course assignment recorded');
        await expect(page.getByText(`${teacherName} · instructor`)).toBeVisible();
        await page.getByLabel('Course role').selectOption('learner');
        await page.getByLabel('Institution member').selectOption({ label: learnerName });
        await page.getByRole('button', { name: 'Assign member' }).click();
        await expect(page.getByRole('status')).toContainText('Scoped course assignment recorded');
        await expect(page.getByText(`${learnerName} · learner`)).toBeVisible();
        await teacherPage.goto(`/t/${tenantSlug}/learning/sessions`);
        await teacherPage.getByLabel('Status').selectOption('cancelled');
        await teacherPage.getByRole('button', { name: 'Apply filters' }).click();
        const instructorSession = teacherPage
            .getByRole('listitem')
            .filter({ hasText: `Live session ${runId}` });
        await expect(instructorSession).toContainText('cancelled');
        await learnerPage.goto(`/t/${tenantSlug}/learning/sessions`);
        await learnerPage.getByLabel('Status').selectOption('cancelled');
        await learnerPage.getByRole('button', { name: 'Apply filters' }).click();
        const learnerSession = learnerPage.getByRole('listitem').filter({ hasText: `Live session ${runId}` });
        await expect(learnerSession).toContainText('cancelled');
        await expect(learnerSession.getByRole('link', { name: 'Join session' })).toHaveCount(0);
        await learnerPage.setViewportSize({ width: 390, height: 844 });
        await learnerPage.keyboard.press('Tab');
        await expect(learnerPage.locator(':focus')).toBeVisible();
        const sessionAccessibility = await new AxeBuilder({ page: learnerPage })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(sessionAccessibility.violations).toEqual([]);
        await page.goto(authoredWorkspaceUrl);
        const releaseExceptions = page.getByRole('region', { name: 'Learner release exceptions' });
        await releaseExceptions.getByLabel('Published lesson').selectOption({ label: 'Getting started' });
        await releaseExceptions.getByLabel('Assigned learner').selectOption({ label: learnerName });
        await releaseExceptions
            .getByLabel('Reason')
            .fill('Provide a documented supervised early access window.');
        await releaseExceptions
            .getByLabel('Expires (UTC)')
            .fill(dateTimeInputInTimezone(new Date(Date.now() + 2 * 60 * 60 * 1000), 'UTC'));
        await releaseExceptions.getByRole('button', { name: 'Request independent approval' }).click();
        await expect(page.getByRole('status')).toContainText('submitted for independent approval');
        await publisherPage.goto(authoredWorkspaceUrl);
        const learnerException = publisherPage
            .getByRole('region', { name: 'Learner release exceptions' })
            .getByRole('listitem')
            .filter({ hasText: learnerName });
        await learnerException.getByRole('button', { name: 'Approve' }).click();
        await expect(learnerException).toContainText('approved');
        const lessonId = await page.locator('article[data-lesson-id]').getAttribute('data-lesson-id');
        expect(lessonId).toBeTruthy();
        const workspaceId = /\/learning\/(\d+)$/.exec(authoredWorkspaceUrl)?.[1];
        expect(workspaceId).toBeTruthy();
        const learnerLessonUrl = `/t/${tenantSlug}/learning/${workspaceId}/lessons/${lessonId}`;
        await learnerPage.goto(learnerLessonUrl);
        await expect(learnerPage.getByRole('heading', { name: 'Getting started' })).toBeVisible();
        const learnerLessonAccessibility = await new AxeBuilder({ page: learnerPage })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(learnerLessonAccessibility.violations).toEqual([]);
        await learnerPage.getByRole('button', { name: 'Confirm lesson completion' }).click();
        await expect(
            learnerPage.getByRole('region', { name: 'Learning progress' }).getByRole('status'),
        ).toContainText('Waiting for instructor confirmation');
        await teacherPage.goto(`/t/${tenantSlug}/learning/${workspaceId}/completion-confirmations`);
        await expect(teacherPage.getByRole('button', { name: 'Confirm completion' })).toBeVisible();
        await teacherPage.getByRole('button', { name: 'Confirm completion' }).click();
        await expect(teacherPage.getByText('Learner completion confirmed.')).toBeVisible();

        await page.goto(`/t/${tenantSlug}/learning`);
        const destinationVersion = page.getByLabel('Published course version');
        const destinationVersionId = await destinationVersion
            .locator('option')
            .filter({ hasText: courseCode })
            .getAttribute('value');
        expect(destinationVersionId).toBeTruthy();
        await destinationVersion.selectOption(destinationVersionId!);
        await page.getByLabel('Workspace code').fill(`LDC-${runId}`);
        await page.getByLabel('Workspace title').fill(`Copy destination ${runId}`);
        await page.getByRole('button', { name: 'Create workspace' }).click();
        await expect(page).toHaveURL(new RegExp(`/t/${tenantSlug}/learning/\\d+$`));

        await page.goto(`/t/${tenantSlug}/learning/templates`);
        const templatesAccessibility = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(templatesAccessibility.violations).toEqual([]);
        const sourceWorkspace = page.getByLabel('Source workspace');
        const sourceWorkspaceId = await sourceWorkspace
            .locator('option')
            .filter({ hasText: `LD-${runId}-DELIVERY` })
            .getAttribute('value');
        await sourceWorkspace.selectOption(sourceWorkspaceId!);
        await page.getByLabel('Template name').fill(`Reusable template ${runId}`);
        await page.getByRole('button', { name: 'Create version 1' }).click();
        const templateCard = page.locator('article').filter({ hasText: `Reusable template ${runId}` });
        await expect(templateCard).toBeVisible();
        await templateCard.getByRole('button', { name: new RegExp(`Reusable template ${runId}`) }).click();
        const destinationSelect = page.getByLabel('Copy into draft workspace');
        const destinationWorkspaceId = await destinationSelect
            .locator('option')
            .filter({ hasText: `LDC-${runId}` })
            .getAttribute('value');
        await destinationSelect.selectOption(destinationWorkspaceId!);
        await page.getByLabel('Course orientation', { exact: true }).check();
        const copyResponsePromise = page.waitForResponse(
            (response) => response.request().method() === 'POST' && /\/copies$/.test(response.url()),
            { timeout: 120_000 },
        );
        await page.getByRole('button', { name: 'Copy selected content' }).click();
        expect((await copyResponsePromise).status()).toBe(302);
        await expect(page).toHaveURL(new RegExp(`/t/${tenantSlug}/learning/\\d+$`), { timeout: 120_000 });
        await expect(page.getByRole('heading', { name: 'Course orientation' })).toBeVisible();

        await page.goto(`/t/${tenantSlug}/learning/templates`);
        const versionSource = page.getByLabel('Publish a new immutable version from');
        await versionSource.selectOption(sourceWorkspaceId!);
        await page.getByRole('button', { name: 'Publish version 2' }).click();
        await expect(page.getByText(/new immutable template version was published/i)).toBeVisible();
        await page.getByRole('link', { name: 'Version history' }).click();
        await expect(page.getByText('Version 2', { exact: true })).toBeVisible();

        await page.goto(`/t/${tenantSlug}/learning`);
        await page.getByRole('link', { name: 'Learning analytics and quality' }).click();
        await expect(page).toHaveURL(new RegExp(`/t/${tenantSlug}/learning/analytics`));
        await expect(page.getByRole('heading', { name: 'Analytics and content quality' })).toBeVisible();
        const workspaceRow = page.getByRole('row').filter({ hasText: `${courseTitle} workspace` });
        const publishedCellIndex = await page
            .getByRole('columnheader', { name: 'Published' })
            .evaluate(
                (header: HTMLTableCellElement) =>
                    Array.from(header.parentElement?.children ?? []).indexOf(header) - 1,
            );
        const learnerCellIndex = await page
            .getByRole('columnheader', { name: 'Learners' })
            .evaluate(
                (header: HTMLTableCellElement) =>
                    Array.from(header.parentElement?.children ?? []).indexOf(header) - 1,
            );
        await expect(workspaceRow.locator('td').nth(publishedCellIndex)).toHaveText('1');
        await expect(workspaceRow.locator('td').nth(learnerCellIndex)).toHaveText('1');
        await expect(page.getByRole('region', { name: 'Review backlog' })).toBeVisible();
        const analyticsAccessibility = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(analyticsAccessibility.violations).toEqual([]);
        await page.setViewportSize({ width: 390, height: 844 });
        await expect(page.getByRole('heading', { name: 'Analytics and content quality' })).toBeVisible();
        await page.getByLabel('Publication status').selectOption('published');
        await page.getByRole('button', { name: 'Apply filters' }).click();
        await expect(page).toHaveURL(/publication_status=published/);
        await expect(workspaceRow.locator('td').first()).toHaveText('published');

        const themes = [
            'executive-navy',
            'academic-emerald',
            'modern-indigo',
            'warm-ivory',
            'graphite-dark',
            'midnight-violet',
        ];
        const learningScreens: { name: string; page: Page; url: string }[] = [
            { name: 'course builder, media library, release manager', page, url: authoredWorkspaceUrl },
            { name: 'learner lesson and progress', page: learnerPage, url: learnerLessonUrl },
            { name: 'template and copy management', page, url: `/t/${tenantSlug}/learning/templates` },
            { name: 'live-session calendar', page: teacherPage, url: `/t/${tenantSlug}/learning/sessions` },
            {
                name: 'completion confirmation',
                page: teacherPage,
                url: `/t/${tenantSlug}/learning/${workspaceId}/completion-confirmations`,
            },
            { name: 'analytics and content quality', page, url: `/t/${tenantSlug}/learning/analytics` },
        ];

        await page.goto(authoredWorkspaceUrl);
        const sessionRegion = page.getByRole('region', { name: 'Live and blended sessions' });
        await sessionRegion.getByRole('button', { name: 'Schedule live session' }).click();
        const requiredError = await sessionRegion
            .getByLabel('Title', { exact: true })
            .evaluate((input: HTMLInputElement) => input.validationMessage);
        expect(requiredError, 'Native required-field feedback must be available to users').toBeTruthy();

        for (const theme of themes) {
            for (const screen of learningScreens) {
                await screen.page.setViewportSize({ width: 390, height: 844 });
                await screen.page.goto(screen.url);
                await expect(screen.page.getByLabel('Choose theme')).toBeVisible();
                await screen.page.getByLabel('Choose theme').selectOption(theme);
                await expect(screen.page.locator('[data-theme]').first()).toHaveAttribute(
                    'data-theme',
                    theme,
                );
                const themedAccessibility = await new AxeBuilder({ page: screen.page })
                    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
                    .analyze();
                expect(themedAccessibility.violations, `${screen.name} / ${theme}`).toEqual([]);

                for (let tab = 0; tab < 3; tab++) await screen.page.keyboard.press('Tab');
                const focused = screen.page.locator(':focus');
                await expect(focused, `${screen.name} / ${theme} keyboard focus`).toBeVisible();
                const focusIndicatorVisible = await focused.evaluate((element) => {
                    const style = getComputedStyle(element);

                    return (
                        (style.outlineStyle !== 'none' && Number.parseFloat(style.outlineWidth) > 0) ||
                        style.boxShadow !== 'none'
                    );
                });
                expect(
                    focusIndicatorVisible,
                    `${screen.name} / ${theme} visible focus indicator`,
                ).toBeTruthy();
                const layout = await screen.page.evaluate(() => ({
                    viewport: document.documentElement.clientWidth,
                    document: document.documentElement.scrollWidth,
                    offenders: Array.from(document.querySelectorAll<HTMLElement>('*'))
                        .map((element) => ({
                            tag: element.tagName.toLowerCase(),
                            className: typeof element.className === 'string' ? element.className : '',
                            right: Math.round(element.getBoundingClientRect().right),
                            width: Math.round(element.getBoundingClientRect().width),
                            text: (element.innerText ?? '').slice(0, 70),
                        }))
                        .filter((element) => element.right > document.documentElement.clientWidth + 2)
                        .slice(0, 8),
                }));
                expect(
                    layout.document,
                    `${screen.name} / ${theme} mobile reflow: ${JSON.stringify(layout.offenders)}`,
                ).toBeLessThanOrEqual(layout.viewport);

                const dialog = screen.page.getByRole('dialog');
                if (await dialog.count()) {
                    await expect(dialog.first()).toContainText(/./);
                    await expect(dialog.first().locator(':focus')).toBeVisible();
                }
            }
        }
    });

    test('institution profile and campus changes persist in the workspace', async ({ browser }) => {
        if (!tenantSlug || !runId) throw new Error('The test tenant was not provisioned.');
        const page = await getInstitutionOwnerPage(browser);

        await page.goto(`/t/${tenantSlug}/institution`);
        await expect(page.getByRole('heading', { name: 'Institution profile' })).toBeVisible();
        await page.getByLabel('Display name').fill(`Updated Institute ${runId}`);
        await page.getByRole('button', { name: 'Save profile' }).click();
        await expect(page.getByRole('status')).toContainText('Institution profile saved');

        await page.goto(`/t/${tenantSlug}/campuses`);
        await page.getByLabel('Campus code').fill(`C-${runId}`);
        await page.getByLabel('Campus name').fill(`Main Campus ${runId}`);
        await page.getByLabel('City').first().fill('Lahore');
        await page.getByLabel('Set as primary campus').check();
        await page.getByRole('button', { name: 'Create campus' }).click();
        await expect(page.getByRole('status')).toContainText('Campus Main Campus');
        await expect(page.getByText(`Main Campus ${runId}`, { exact: true })).toBeVisible();
        await expect(page.getByText(/Primary/).first()).toBeVisible();
    });

    test('institution owner registers a student and manages guardian authority', async ({ browser }) => {
        test.setTimeout(240_000);
        if (!tenantSlug || !runId) throw new Error('The test tenant was not provisioned.');
        const page = await getInstitutionOwnerPage(browser);

        await page.goto(`/t/${tenantSlug}/sis`);
        await expect(page.getByRole('heading', { name: 'Lifecycle analytics' })).toBeVisible();
        const dashboardAccessibility = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(dashboardAccessibility.violations).toEqual([]);
        await page.getByLabel('Student label').fill('Learner');
        await page.getByLabel('Number prefix').fill('LNR');
        await page.getByLabel('Number digits').fill('5');
        await page.getByLabel('Require independent approval for bulk promotions').check();
        await page.getByRole('button', { name: 'Save registry settings' }).click();
        await expect(page.getByRole('status')).toContainText('Student registry settings saved');

        await page.goto(`/t/${tenantSlug}/sis/students`);
        await expect(page.getByRole('heading', { name: 'Student registry' })).toBeVisible();
        const directoryAccessibility = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(directoryAccessibility.violations).toEqual([]);
        await page.setViewportSize({ width: 390, height: 844 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
            true,
        );
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.getByRole('button', { name: 'Register student' }).click();
        await page.getByLabel('Legal first name').fill(`Mina${runId}`);
        await page.getByLabel('Legal family name').fill('Learner');
        await page.getByLabel('Admission date').fill(new Date().toISOString().slice(0, 10));
        await page.getByRole('button', { name: 'Save student record' }).click();

        await expect(page).toHaveURL(/\/sis\/students\/\d+$/);
        await expect(page.getByRole('heading', { name: new RegExp(`Mina${runId} Learner`) })).toBeVisible();
        const firstStudentUrl = page.url();
        await expect(page.getByText('LNR00001')).toBeVisible();
        await expect(page.getByText('Student registered.')).toBeVisible();
        const studentProfileAccessibility = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(studentProfileAccessibility.violations).toEqual([]);
        await page.setViewportSize({ width: 390, height: 844 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
            true,
        );
        await page.setViewportSize({ width: 1280, height: 800 });
        const studentIdentityEmail = `sis-student-${runId}@example.test`;
        await page.getByLabel('Student email').fill(studentIdentityEmail);
        const studentIdentityResponse = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                new URL(response.url()).pathname.endsWith('/identity/invite'),
        );
        await page.getByRole('button', { name: 'Send account invitation' }).click();
        const studentInvitation = await studentIdentityResponse;
        expect(studentInvitation.status()).toBe(302);
        await expect(
            page.getByText('Student account invitation sent. The signed link expires in seven days.', {
                exact: true,
            }),
        ).toBeVisible();
        const studentAcceptanceUrl = await studentInvitation.headerValue('x-e2e-invitation-url');
        expect(studentAcceptanceUrl).toBeTruthy();

        const studentContext = await browser.newContext({ baseURL });
        const studentPage = await studentContext.newPage();
        await studentPage.goto(studentAcceptanceUrl!);
        await expect(studentPage.getByRole('heading', { name: /Join/ })).toBeVisible();
        await studentPage.getByLabel('Your name').fill(`Mina${runId} Learner`);
        const studentPassword = `SIS-${runId}-Student-Password-42!`;
        await studentPage.getByLabel('Create password').fill(studentPassword);
        await studentPage.getByLabel('Confirm password').fill(studentPassword);
        await studentPage.getByRole('button', { name: 'Create account and join' }).click();
        await expect(studentPage).toHaveURL(/\/workspaces$/);
        await expect(studentPage.getByRole('heading', { name: 'My institution memberships' })).toBeVisible();
        await expect(studentPage.getByText('Student', { exact: true })).toBeVisible();

        await page.goto(firstStudentUrl);
        const linkedAccount = page
            .locator('dl > div')
            .filter({ has: page.getByText('Linked account', { exact: true }) })
            .locator('dd');
        await expect(linkedAccount).toContainText(studentIdentityEmail);
        await expect(linkedAccount).toContainText(`Mina${runId} Learner`);
        const lifecycleForm = page
            .locator('form')
            .filter({ has: page.getByRole('button', { name: 'Record status change' }) });
        const lifecycleDate = new Date().toISOString().slice(0, 10);
        await lifecycleForm.getByLabel('New status').selectOption('active');
        await lifecycleForm.getByLabel('Effective date').fill(lifecycleDate);
        await lifecycleForm.getByLabel('Reason code').fill('browser_student_activation');
        await lifecycleForm.getByRole('button', { name: 'Record status change' }).click();
        await expect(page.getByText('Active institution membership')).toBeVisible();
        await lifecycleForm.getByLabel('New status').selectOption('suspended');
        await lifecycleForm.getByLabel('Effective date').fill(lifecycleDate);
        await lifecycleForm.getByLabel('Reason code').fill('browser_student_suspension');
        await lifecycleForm.getByRole('button', { name: 'Record status change' }).click();
        await expect(page.getByText('Suspended by student status policy')).toBeVisible();
        expect((await studentContext.request.get(`/t/${tenantSlug}/dashboard`)).status()).toBe(404);
        await lifecycleForm.getByLabel('New status').selectOption('active');
        await lifecycleForm.getByLabel('Reason code').fill('browser_student_reactivation');
        await lifecycleForm.getByRole('button', { name: 'Record status change' }).click();
        await expect(page.getByText('Active institution membership')).toBeVisible();
        await studentPage.goto(`/t/${tenantSlug}/dashboard`);
        await expect(studentPage.getByRole('heading', { name: 'Learner workspace' })).toBeVisible();
        await studentContext.close();

        await page.getByLabel('Document category').selectOption('transfer_certificate');
        await page.getByLabel(/PDF, JPEG or PNG/).setInputFiles({
            name: `transfer-certificate-${runId}.pdf`,
            mimeType: 'application/pdf',
            buffer: Buffer.from('%PDF-1.7\nBrowser quarantine fixture\n'),
        });
        const sisDocumentUpload = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                new URL(response.url()).pathname.endsWith('/private-documents'),
        );
        await page.getByRole('button', { name: 'Upload to quarantine' }).click();
        const sisDocumentUploadResponse = await sisDocumentUpload;
        expect(sisDocumentUploadResponse.status()).toBeLessThan(400);
        await expect(page.getByRole('status')).toContainText('private quarantine');
        const documents = page.locator('section').filter({
            has: page.getByRole('heading', { name: 'Documents' }),
        });
        await expect(documents.getByText(`transfer-certificate-${runId}.pdf`, { exact: true })).toBeVisible();
        await expect(documents.getByRole('link', { name: 'Download' })).toHaveCount(0);
        if (realClamAvE2E) {
            await expect
                .poll(
                    async () => {
                        await page.reload();
                        return documents.getByText(/scan clean/).count();
                    },
                    { timeout: 180_000, intervals: [1_000, 3_000, 5_000] },
                )
                .toBe(1);
            const cleanDownload = documents.getByRole('link', { name: 'Download' });
            await expect(cleanDownload).toHaveCount(1);
            const [download] = await Promise.all([page.waitForEvent('download'), cleanDownload.click()]);
            expect(download.suggestedFilename()).toBe(`transfer-certificate-${runId}.pdf`);
        } else {
            await expect(documents.getByText(/scan (pending|unavailable)/)).toBeVisible();
            await expect(documents.getByRole('link', { name: 'Download' })).toHaveCount(0);
        }

        await page.getByLabel('Guardian name').fill(`Guardian ${runId}`);
        await page.getByLabel('Email', { exact: true }).fill(process.env.E2E_OWNER_EMAIL!);
        await page.getByLabel('Relationship', { exact: true }).fill('parent');
        await page.getByRole('button', { name: 'Add pending guardian' }).click();

        const guardianCard = page.locator('li').filter({ hasText: `Guardian ${runId}` });
        await expect(guardianCard).toContainText('pending');
        await guardianCard.getByLabel('Verification method').selectOption('in_person');
        const guardianAccountSelect = guardianCard.getByLabel(/Link a verified institution account/);
        const guardianAccountOption = guardianAccountSelect.locator('option').filter({
            hasText: process.env.E2E_OWNER_EMAIL!,
        });
        await expect(guardianAccountOption).toHaveCount(1);
        const guardianAccountId = await guardianAccountOption.getAttribute('value');
        if (!guardianAccountId) throw new Error('The matching verified guardian account was not selectable.');
        await guardianAccountSelect.selectOption(guardianAccountId);
        await guardianCard.getByRole('button', { name: 'Verify relationship' }).click();
        await expect(guardianCard).toContainText('verified');
        await guardianCard.getByLabel('Revocation reason').fill('Authority review completed.');
        await guardianCard.getByRole('button', { name: 'Revoke access' }).click();
        await expect(guardianCard).toContainText('revoked');
        await expect(guardianCard.getByRole('button', { name: 'Revoke access' })).toHaveCount(0);

        const newGuardianName = `New Guardian ${runId}`;
        const newGuardianEmail = `new-guardian-${runId}@example.test`;
        await page.getByLabel('Guardian name').fill(newGuardianName);
        await page.getByLabel('Email', { exact: true }).fill(newGuardianEmail);
        await page.getByLabel('Relationship', { exact: true }).fill('parent');
        await page.getByRole('button', { name: 'Add pending guardian' }).click();
        const newGuardianCard = page.locator('li').filter({ hasText: newGuardianName });
        await expect(newGuardianCard).toContainText('pending');
        await newGuardianCard.getByRole('button', { name: 'Verify relationship' }).click();
        await expect(newGuardianCard).toContainText('verified');
        await expect(newGuardianCard.getByRole('button', { name: 'Invite guardian account' })).toHaveCount(
            1,
            { timeout: 3_000 },
        );
        const guardianInviteResponse = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                new URL(response.url()).pathname.endsWith('/identity/invite'),
        );
        await newGuardianCard.getByRole('button', { name: 'Invite guardian account' }).click();
        const guardianInvitation = await guardianInviteResponse;
        expect(guardianInvitation.status()).toBe(302);
        const guardianAcceptanceUrl = await guardianInvitation.headerValue('x-e2e-invitation-url');
        expect(guardianAcceptanceUrl).toBeTruthy();

        const guardianContext = await browser.newContext({ baseURL });
        const guardianPage = await guardianContext.newPage();
        await guardianPage.goto(guardianAcceptanceUrl!);
        await expect(guardianPage.getByRole('heading', { name: /Join/ })).toBeVisible();
        await guardianPage.getByLabel('Your name').fill(newGuardianName);
        const newGuardianPassword = `SIS-${runId}-Guardian-Password-42!`;
        await guardianPage.getByLabel('Create password').fill(newGuardianPassword);
        await guardianPage.getByLabel('Confirm password').fill(newGuardianPassword);
        await guardianPage.getByRole('button', { name: 'Create account and join' }).click();
        await expect(guardianPage).toHaveURL(/\/workspaces$/);
        await expect(guardianPage.getByText('Parent / Guardian', { exact: true })).toBeVisible();
        const deniedProfile = await guardianContext.request.get(firstStudentUrl);
        expect(deniedProfile.status()).toBe(403);
        await guardianContext.close();
        await page.goto(firstStudentUrl);
        const linkedGuardianCard = page.locator('li').filter({ hasText: newGuardianName });
        await expect(linkedGuardianCard).toContainText(newGuardianEmail);
        await expect(linkedGuardianCard).toContainText('Profile access remains disabled');

        const familySection = page.locator('section').filter({
            has: page.getByRole('heading', { name: 'Institution family groups' }),
        });
        await familySection.getByLabel('Family group label').fill(`Household ${runId}`);
        await familySection.getByLabel('Relationship to this student').fill('child');
        await familySection.getByLabel('Verification basis').first().selectOption('institution_record');
        await familySection.getByRole('button', { name: 'Create family group' }).click();
        await expect(page.getByText(/Institution family group created/)).toBeVisible();

        await page.goto(`/t/${tenantSlug}/sis/students`);
        await page.getByRole('button', { name: 'Register student' }).click();
        await page.getByLabel('Legal first name').fill(`Noor${runId}`);
        await page.getByLabel('Legal family name').fill('Learner');
        await page.getByLabel('Admission date').fill(new Date().toISOString().slice(0, 10));
        await page.getByRole('button', { name: 'Save student record' }).click();
        await expect(page.getByRole('heading', { name: new RegExp(`Noor${runId} Learner`) })).toBeVisible();
        await expect(page.getByText('LNR00002')).toBeVisible();

        await page.goto(firstStudentUrl);
        const activeFamilySection = page.locator('section').filter({
            has: page.getByRole('heading', { name: 'Institution family groups' }),
        });
        await activeFamilySection
            .getByLabel('Add another student to group')
            .selectOption({ label: `Household ${runId}` });
        await activeFamilySection.getByLabel('Search institution students').fill(`Noor${runId}`);
        const siblingOption = page.getByRole('radio', { name: new RegExp(`Noor${runId} Learner`) });
        await expect(siblingOption).toBeVisible();
        await siblingOption.check();
        await activeFamilySection.getByLabel('Relationship to this family').fill('sibling');
        await activeFamilySection
            .getByLabel('Verification basis')
            .last()
            .selectOption('guardian_confirmation');
        await activeFamilySection.getByRole('button', { name: 'Verify and link student' }).click();
        await expect(page.getByText('Student linked to this institution family group.')).toBeVisible();
        await expect(activeFamilySection).toContainText('Noor');

        await activeFamilySection.getByLabel('Family group label').first().fill(`Household ${runId} updated`);
        await activeFamilySection.getByLabel(/This student’s family relationship/).fill('dependent');
        await activeFamilySection.getByLabel('Verification basis').first().selectOption('document_review');
        await activeFamilySection.getByRole('button', { name: 'Save and verify relationship' }).click();
        await expect(
            page.getByText('Family details updated and relationship verification recorded.'),
        ).toBeVisible();
        await expect(activeFamilySection.getByLabel('Family group label').first()).toHaveValue(
            `Household ${runId} updated`,
        );

        const candidateEmail = `sis-reconciliation-candidate-${runId}@example.test`;
        await page.goto(`/t/${tenantSlug}/members`);
        await page.getByLabel('Email address').fill(candidateEmail);
        await page.getByLabel('Institution role').selectOption('student');
        const candidateInviteResponse = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                response.url().endsWith(`/t/${tenantSlug}/members/invitations`),
        );
        await page.getByRole('button', { name: 'Send invitation' }).click();
        const candidateInvitation = await candidateInviteResponse;
        expect(candidateInvitation.status()).toBe(302);
        const candidateAcceptanceUrl = await candidateInvitation.headerValue('x-e2e-invitation-url');
        expect(candidateAcceptanceUrl).toBeTruthy();
        const candidateContext = await browser.newContext({ baseURL });
        const candidatePage = await candidateContext.newPage();
        await candidatePage.goto(candidateAcceptanceUrl!);
        await expect(candidatePage.getByRole('heading', { name: /Join .* Institute/ })).toBeVisible();
        await candidatePage.getByLabel('Your name').fill(`Reconciliation Candidate ${runId}`);
        const candidatePassword = `SIS-Reconcile-Candidate-${runId}-42!`;
        await candidatePage.getByLabel('Create password').fill(candidatePassword);
        await candidatePage.getByLabel('Confirm password').fill(candidatePassword);
        await candidatePage.getByRole('button', { name: 'Create account and join' }).click();

        const reviewerEmail = `sis-reconciliation-reviewer-${runId}@example.test`;
        await page.goto(`/t/${tenantSlug}/members`);
        await page.getByLabel('Email address').fill(reviewerEmail);
        await page.getByLabel('Institution role').selectOption('tenant.admin');
        const reviewerInviteResponse = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                response.url().endsWith(`/t/${tenantSlug}/members/invitations`),
        );
        await page.getByRole('button', { name: 'Send invitation' }).click();
        const reviewerInvitation = await reviewerInviteResponse;
        expect(reviewerInvitation.status()).toBe(302);
        const reviewerAcceptanceUrl = await reviewerInvitation.headerValue('x-e2e-invitation-url');
        expect(reviewerAcceptanceUrl).toBeTruthy();
        const reviewerContext = await browser.newContext({ baseURL });
        const reviewerPage = await reviewerContext.newPage();
        await reviewerPage.goto(reviewerAcceptanceUrl!);
        await expect(reviewerPage.getByRole('heading', { name: /Join .* Institute/ })).toBeVisible();
        await reviewerPage.getByLabel('Your name').fill(`Reconciliation Reviewer ${runId}`);
        const reviewerPassword = `SIS-Reconcile-Reviewer-${runId}-42!`;
        await reviewerPage.getByLabel('Create password').fill(reviewerPassword);
        await reviewerPage.getByLabel('Confirm password').fill(reviewerPassword);
        await reviewerPage.getByRole('button', { name: 'Create account and join' }).click();

        await page.goto(firstStudentUrl);
        await page.getByLabel('Proposed active institution account').selectOption({
            label: `Reconciliation Candidate ${runId} · ${candidateEmail}`,
        });
        await page.getByLabel('Review basis').selectOption('corrected_link');
        await page
            .getByLabel('Factual review note')
            .fill('The institution verified that this active account belongs to the registered learner.');
        const identityCorrectionForm = page
            .locator('form')
            .filter({ hasText: 'Request a student identity correction' });
        await identityCorrectionForm.getByRole('button', { name: 'Submit for independent review' }).click();
        await expect(page.getByRole('status')).toContainText(
            'Identity correction request recorded for independent review',
        );

        await reviewerPage.goto(firstStudentUrl);
        await expect(
            reviewerPage.getByRole('heading', { name: 'Identity correction review history' }),
        ).toBeVisible();
        await expect(reviewerPage.getByText('Pending independent review')).toBeVisible();
        await expect(reviewerPage.getByRole('button', { name: 'Record independent decision' })).toBeVisible();
        await reviewerPage.getByLabel('Independent decision').selectOption('approve');
        await reviewerPage
            .getByLabel('Decision rationale')
            .fill('Independent review verified the institution identity records and approval authority.');
        await reviewerPage.getByRole('button', { name: 'Record independent decision' }).click();
        await expect(reviewerPage.getByRole('status')).toContainText(
            'Identity correction was independently approved and applied',
        );
        const identityCorrectionHistory = reviewerPage
            .getByRole('heading', { name: 'Identity correction review history' })
            .locator('xpath=..');
        await expect(identityCorrectionHistory.getByText('Approved and applied')).toBeVisible();

        await page.goto(firstStudentUrl);
        const reconciledLinkedAccount = page
            .locator('dl > div')
            .filter({ has: page.getByText('Linked account', { exact: true }) })
            .locator('dd');
        await expect(reconciledLinkedAccount).toContainText(candidateEmail);
        const previousStudentContext = await browser.newContext({ baseURL });
        const previousStudentPage = await previousStudentContext.newPage();
        await signIn(previousStudentPage, studentIdentityEmail, studentPassword);
        expect((await previousStudentContext.request.get(`/t/${tenantSlug}/dashboard`)).status()).toBe(404);
        await previousStudentContext.close();
        await candidateContext.close();
        await reviewerContext.close();
    });

    test('institution administrator creates, publishes, edits, and opens a public admissions intake', async ({
        browser,
    }) => {
        if (!tenantSlug || !runId) throw new Error('The test tenant was not provisioned.');
        const page = await getInstitutionOwnerPage(browser);
        await page.goto(`/t/${tenantSlug}/admissions`);
        await expect(page.getByRole('heading', { name: 'Admissions operations' })).toBeVisible();

        const settings = page
            .locator('section')
            .filter({ has: page.getByRole('heading', { name: 'Admissions access and policy' }) });
        for (const label of ['Admissions enabled', 'Accept public applications', 'Accept public inquiries']) {
            const checkbox = settings.getByLabel(label);
            if (!(await checkbox.isChecked())) await checkbox.check();
        }
        await settings.getByRole('button', { name: 'Save admissions settings' }).click();
        await expect(page.getByRole('status')).toContainText('Admissions settings updated');

        const intakeCode = `AD-${runId}`;
        const intakeName = `Autumn Admissions ${runId}`;
        await page.getByLabel('Intake code').fill(intakeCode);
        await page.getByLabel('Intake name', { exact: true }).fill(intakeName);
        await page.getByLabel('Cycle').fill(`Autumn ${runId}`);
        await page.getByRole('button', { name: 'Create draft intake' }).click();
        await expect(page.getByRole('status')).toContainText(`Intake ${intakeName} created as a draft`);

        let card = page.locator('article').filter({ hasText: intakeCode });
        await card
            .locator('summary')
            .filter({ hasText: /^Application form/ })
            .click();
        await card.getByRole('button', { name: 'Add question' }).click();
        await card.getByLabel('Question label').last().fill(`Applicant goal ${runId}`);
        await card.getByRole('button', { name: 'Show preview' }).click();
        await expect(card.getByText(`Applicant goal ${runId}`).last()).toBeVisible();
        await card.getByRole('button', { name: 'Hide preview' }).click();
        await card.getByRole('button', { name: 'Save draft' }).click();
        await expect(page.getByRole('status')).toContainText('draft application form was saved');
        card = page.locator('article').filter({ hasText: intakeCode });
        await card.getByRole('button', { name: 'Publish new version' }).click();
        await expect(page.getByRole('status')).toContainText('Application form version 1 published');
        card = page.locator('article').filter({ hasText: intakeCode });
        await card.getByRole('button', { name: 'Mark open' }).click();
        await expect(page.getByRole('status')).toContainText(`Intake ${intakeCode} marked open`);

        card = page.locator('article').filter({ hasText: intakeCode });
        await card.getByText('Edit intake details').click();
        await card.getByLabel('Intake name', { exact: true }).fill(`${intakeName} Updated`);
        await card.getByRole('button', { name: 'Save intake changes' }).click();
        await expect(page.getByRole('status')).toContainText('updated');

        const publicPage = await page.request.get(`/admissions/${tenantSlug}/intakes/${intakeCode}`);
        expect(publicPage.status()).toBe(200);
        await page.goto(`/admissions/${tenantSlug}/intakes/${intakeCode}`);
        await expect(page.getByRole('heading', { name: `${intakeName} Updated` })).toBeVisible();

        await page.setViewportSize({ width: 390, height: 844 });
        const widths = await page.evaluate(() => ({
            document: document.documentElement.scrollWidth,
            viewport: innerWidth,
        }));
        expect(widths.document).toBeLessThanOrEqual(widths.viewport);
        const accessibility = await new AxeBuilder({ page }).analyze();
        expect(accessibility.violations).toEqual([]);

        const inquiryEmail = `inquiry-${runId}@example.test`;
        await page.goto(`/admissions/${tenantSlug}`);
        await page.getByLabel('Your name').fill(`Admissions Inquiry ${runId}`);
        await page.getByLabel('Email address').fill(inquiryEmail);
        await page.getByLabel('Question').fill('Please share the intake requirements.');
        await page.getByLabel('I agree the admissions office may use these details to respond.').check();
        await page.getByRole('button', { name: 'Send inquiry' }).click();
        await expect(page.getByRole('status')).toContainText('Your inquiry was recorded');

        await page.goto(`/t/${tenantSlug}/admissions`);
        const inquiries = page
            .locator('section')
            .filter({ has: page.getByRole('heading', { name: 'Admissions inquiries' }) });
        const inquiry = inquiries.locator('article').filter({ hasText: inquiryEmail });
        await expect(inquiry).toBeVisible();
        await inquiry.getByLabel('Next status').selectOption('contacted');
        await inquiry.getByRole('button', { name: 'Save status' }).click();
        await expect(inquiry).toContainText('contacted');
        await inquiry.getByLabel('Next status').selectOption('qualified');
        await inquiry.getByRole('button', { name: 'Save status' }).click();
        await expect(inquiry).toContainText('qualified');
    });

    test('minor applicant completes guardian verification, submits, and tests access revocation', async ({
        browser,
    }) => {
        test.setTimeout(60_000);
        if (!tenantSlug || !runId) throw new Error('The test tenant was not provisioned.');
        const applicantEmail = process.env.E2E_APPLICANT_EMAIL;
        const applicantPassword = process.env.E2E_APPLICANT_PASSWORD;
        if (!applicantEmail || !applicantPassword) {
            throw new Error('The isolated applicant browser credentials were not provisioned.');
        }

        const context = await browser.newContext({ baseURL });
        const page = await context.newPage();
        await signIn(page, applicantEmail, applicantPassword);
        await page.goto(`/admissions/${tenantSlug}/intakes/AD-${runId}`);
        await expect(
            page.getByRole('heading', { name: `Autumn Admissions ${runId} Updated`, exact: true }),
        ).toBeVisible();
        await page.getByLabel('Given name').fill('Morgan');
        await page.getByLabel('Family name').fill('Applicant');
        await page.getByLabel('Date of birth').fill('2012-04-12');
        await page.getByRole('button', { name: 'Continue securely' }).click();
        await expect(page).toHaveURL(new RegExp(`/admissions/${tenantSlug}/applications/APP-`));

        await page
            .getByLabel('Why are you interested in this program?')
            .fill('I am ready to submit my application.');
        await page.getByLabel(/Applicant goal/).fill('Complete a verified application journey.');
        await page.getByLabel('I confirm the information supplied is accurate.').check();
        await page.getByRole('button', { name: 'Save draft' }).click();
        await expect(page.getByRole('status')).toContainText('Draft saved');
        const applicationPath = new URL(page.url()).pathname;
        await expect(page.getByRole('heading', { name: 'Guardian authorization' })).toBeVisible();
        const submitUrl = `${applicationPath}/submit`;
        const blockedSubmitResponsePromise = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' && new URL(response.url()).pathname === submitUrl,
            { timeout: 20_000 },
        );
        await page.getByRole('button', { name: 'Review and submit' }).click({ timeout: 10_000 });
        const blockedSubmitResponse = await blockedSubmitResponsePromise;
        expect(blockedSubmitResponse.status()).toBeLessThan(400);
        await expect(page).toHaveURL(applicationPath);
        await expect(page.getByRole('heading', { name: 'Guardian authorization' })).toBeVisible();
        await expect(page.getByText('draft', { exact: true }).first()).toBeVisible();

        const guardianEmail = process.env.E2E_GUARDIAN_EMAIL;
        const guardianPassword = process.env.E2E_GUARDIAN_PASSWORD;
        if (!guardianEmail || !guardianPassword) {
            throw new Error('The isolated guardian browser credentials were not provisioned.');
        }
        await page.getByLabel('Guardian name').fill(`Guardian ${runId}`);
        await page.getByLabel('Guardian email').fill(guardianEmail);
        const guardianInvitationResponsePromise = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                new URL(response.url()).pathname.endsWith('/guardians'),
            { timeout: 20_000 },
        );
        await page.getByRole('button', { name: 'Send verification request' }).click();
        const guardianInvitationResponse = await guardianInvitationResponsePromise;
        expect(guardianInvitationResponse.status()).toBeLessThan(400);
        const guardianInvitationUrl = await guardianInvitationResponse.headerValue(
            'x-e2e-guardian-invitation-url',
        );
        expect(guardianInvitationUrl).toBeTruthy();

        const guardianContext = await browser.newContext({ baseURL });
        const guardianPage = await guardianContext.newPage();
        await signIn(guardianPage, guardianEmail, guardianPassword);
        await guardianPage.goto(guardianInvitationUrl!);
        await expect(guardianPage.getByRole('heading', { name: 'Review guardian request' })).toBeVisible();
        const guardianAcceptResponsePromise = guardianPage.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                new URL(response.url()).pathname.endsWith('/guardian-invitations'),
            { timeout: 20_000 },
        );
        await guardianPage.getByRole('button', { name: 'Verify and accept request' }).click();
        const guardianAcceptResponse = await guardianAcceptResponsePromise;
        expect(guardianAcceptResponse.status()).toBeLessThan(400);

        const applicationNumber = applicationPath.split('/').at(-1);
        if (!applicationNumber) throw new Error('The applicant application reference was not available.');
        const owner = await getInstitutionOwnerPage(browser);
        await owner.goto(`/t/${tenantSlug}/admissions`);
        const draftRow = owner.locator('tbody tr').filter({ hasText: applicationNumber });
        await expect(draftRow).toBeVisible();
        const reviewHref = await draftRow.getByRole('link', { name: 'Review' }).getAttribute('href');
        if (!reviewHref) throw new Error('The admissions queue did not provide a guardian review link.');
        await owner.goto(reviewHref);
        await expect(owner.getByRole('heading', { name: `Application ${applicationNumber}` })).toBeVisible();
        await expect(owner.getByText(guardianEmail, { exact: false })).toBeVisible();
        await expect(owner.getByText(/guardian verified/)).toBeVisible();
        await owner.getByLabel('Verification method').selectOption('document_review');
        await owner.getByRole('button', { name: 'Verify relationship' }).click();
        await expect(owner.getByRole('status')).toContainText('Guardian relationship verification recorded');
        await expect(owner.locator('p').filter({ hasText: guardianEmail })).toContainText('active');

        await page.goto(applicationPath);
        await expect(page.getByText('A guardian relationship is active.', { exact: false })).toBeVisible();
        const submitResponsePromise = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' && new URL(response.url()).pathname === submitUrl,
            { timeout: 20_000 },
        );
        await page.getByRole('button', { name: 'Review and submit' }).click();
        const submitResponse = await submitResponsePromise;
        expect(submitResponse.status()).toBeLessThan(400);
        await expect(page.getByRole('status').filter({ hasText: /Application submitted/ })).toBeVisible();
        await expect(page.getByText('submitted', { exact: true }).first()).toBeVisible();
        submittedApplicationNumber = applicationNumber;

        await guardianPage.goto(applicationPath);
        await expect(guardianPage.getByRole('heading', { name: 'Guardian access' })).toBeVisible();
        await expect(
            guardianPage.getByText('You have read-only access as a verified guardian.'),
        ).toBeVisible();
        expect(
            (
                await guardianPage.request.get(
                    `/admissions/${foreignTenantSlug}/applications/${applicationNumber}`,
                )
            ).status(),
        ).toBe(404);
        const revokeResponsePromise = guardianPage.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                new URL(response.url()).pathname.endsWith('/revoke'),
            { timeout: 20_000 },
        );
        await guardianPage.getByRole('button', { name: 'Revoke my access' }).click();
        const revokeResponse = await revokeResponsePromise;
        expect(revokeResponse.status()).toBeLessThan(400);
        expect((await guardianPage.request.get(applicationPath)).status()).toBe(404);

        await page.goto(applicationPath);
        await expect(page.getByText(guardianEmail, { exact: false })).toBeVisible();
        await expect(page.locator('p').filter({ hasText: guardianEmail })).toContainText('revoked');

        await page.goto(`/admissions/${tenantSlug}/my-applications`);
        await expect(page.getByRole('heading', { name: 'My applications' })).toBeVisible();
        await expect(page.getByText(/APP-[A-Z0-9]+/).first()).toBeVisible();
        await guardianContext.close();
        await context.close();
    });

    test('institution owner configures an academic year and period through the workspace', async ({
        browser,
    }) => {
        if (!tenantSlug || !runId) throw new Error('The test tenant was not provisioned.');
        const page = await getInstitutionOwnerPage(browser);
        await page.goto(`/t/${tenantSlug}/academics`);
        await expect(page.getByRole('heading', { name: 'Academic administration' })).toBeVisible();
        await expect(page.getByText('Training program', { exact: true })).toBeVisible();

        await page.getByRole('link', { name: 'Academic calendar' }).first().click();
        await expect(page.getByRole('heading', { name: 'Academic calendar' })).toBeVisible();
        const yearName = `Academic Year ${runId}`;
        const yearCode = `AY-${runId}`;
        const yearForm = page.locator('form').filter({ has: page.getByLabel('Academic model') });
        await yearForm.getByLabel('Name').fill(yearName);
        await yearForm.getByLabel('Code').fill(yearCode);
        await yearForm.getByLabel('Starts on').fill('2026-08-01');
        await yearForm.getByLabel('Ends on').fill('2027-06-30');
        await yearForm.getByRole('button', { name: 'Create draft year' }).click();

        let yearCard = page.locator('article').filter({ hasText: yearCode });
        await expect(yearCard.getByRole('heading', { name: yearName })).toBeVisible();
        await yearCard.getByText('Edit academic year').click();
        await yearCard.getByLabel('Description').fill('Browser verified institutional calendar.');
        await yearCard.getByRole('button', { name: 'Save year' }).click();
        await expect(page.getByRole('status')).toContainText('Academic year updated');
        yearCard = page.locator('article').filter({ hasText: yearCode });
        await yearCard.getByRole('button', { name: 'Activate' }).click();
        await expect(page.getByRole('status')).toContainText(`Academic year marked active`);
        await yearCard.getByRole('button', { name: 'Set current' }).click();
        await expect(page.getByRole('status')).toContainText('Current academic year updated');
        await expect(yearCard.getByText('CURRENT', { exact: true })).toBeVisible();

        await yearCard.getByText('Add period or copy calendar').click();
        const periodDetails = yearCard.locator('details').filter({ hasText: 'Add period or copy calendar' });
        const periodForm = periodDetails.locator('form').first();
        await periodForm.getByLabel('Name').fill(`Autumn ${runId}`);
        await periodForm.getByLabel('Code').fill(`AUT-${runId}`);
        await periodForm.getByLabel('Starts on').fill('2026-08-01');
        await periodForm.getByLabel('Ends on').fill('2026-12-20');
        await periodForm.getByRole('button', { name: 'Create draft period' }).click();
        await expect(page.getByRole('status')).toContainText('created as a draft');
        yearCard = page.locator('article').filter({ hasText: yearCode });
        const periodRow = yearCard.locator('li').filter({ hasText: `AUT-${runId}` });
        await expect(periodRow).toContainText(`Autumn ${runId}`);
        await periodRow.getByText('Edit period').click();
        await periodRow.getByLabel('Name').fill(`Autumn Term ${runId}`);
        await periodRow.getByRole('button', { name: 'Save period' }).click();
        await expect(page.getByRole('status')).toContainText('Academic period updated');
        yearCard = page.locator('article').filter({ hasText: yearCode });
        const updatedPeriod = yearCard.locator('li').filter({ hasText: `AUT-${runId}` });
        await updatedPeriod.getByRole('button', { name: 'Activate' }).click();
        await expect(page.getByRole('status')).toContainText('Academic period marked active');
        await updatedPeriod.getByRole('button', { name: 'Set current' }).click();
        await expect(page.getByRole('status')).toContainText('Current academic period updated');
        await expect(updatedPeriod.getByText('CURRENT', { exact: true })).toBeVisible();

        await page.setViewportSize({ width: 375, height: 812 });
        const widths = await page.evaluate(() => ({
            document: document.documentElement.scrollWidth,
            viewport: innerWidth,
            overflowingElements: Array.from(document.querySelectorAll<HTMLElement>('*'))
                .map((element) => {
                    const bounds = element.getBoundingClientRect();

                    return {
                        element: `${element.tagName.toLowerCase()}${Array.from(element.classList)
                            .map((name) => `.${name}`)
                            .join('')}`,
                        left: Math.round(bounds.left),
                        right: Math.round(bounds.right),
                        scrollWidth: element.scrollWidth,
                        text: element.innerText?.trim().replace(/\s+/g, ' ').slice(0, 80) ?? '',
                    };
                })
                .filter((element) => element.left < -1 || element.right > innerWidth + 1)
                .sort((left, right) => right.right - left.right)
                .slice(0, 12),
            calendarLayout: (() => {
                const layout: Array<Record<string, string | number>> = [];
                let element: HTMLElement | null = document.querySelector(
                    '.academic-calendar-create-year-form input',
                );

                while (element && layout.length < 8) {
                    const style = getComputedStyle(element);
                    const bounds = element.getBoundingClientRect();

                    layout.push({
                        tag: element.tagName.toLowerCase(),
                        className: element.className,
                        width: Math.round(bounds.width),
                        left: Math.round(bounds.left),
                        right: Math.round(bounds.right),
                        minWidth: style.minWidth,
                        maxWidth: style.maxWidth,
                        gridTemplateColumns: style.gridTemplateColumns,
                        display: style.display,
                        overflowX: style.overflowX,
                    });
                    element = element.parentElement;
                }

                return layout;
            })(),
        }));
        expect(
            widths.document,
            JSON.stringify({ offenders: widths.overflowingElements, layout: widths.calendarLayout }),
        ).toBeLessThanOrEqual(widths.viewport);
        const accessibility = await new AxeBuilder({ page }).analyze();
        expect(accessibility.violations).toEqual([]);
    });

    test('institution owner builds linked academic structures, catalog, credit policy, outcomes, and quality objective', async ({
        browser,
    }) => {
        test.setTimeout(150_000);
        if (!tenantSlug || !runId) throw new Error('The test tenant was not provisioned.');
        const page = await getInstitutionOwnerPage(browser);
        const root = `/t/${tenantSlug}/academics`;
        const suffix = `Browser ${runId}`;
        await page.setViewportSize({ width: 1280, height: 900 });

        await page.goto(`${root}/calendar`);
        const settingsForm = page.locator('form').filter({
            has: page.getByRole('button', { name: 'Save academic settings' }),
        });
        for (const capability of ['credits', 'quality']) {
            const checkbox = settingsForm.getByLabel(capability, { exact: true });
            if (!(await checkbox.isChecked())) await checkbox.check();
        }
        await settingsForm.getByRole('button', { name: 'Save academic settings' }).click();
        await expect(page.getByRole('status')).toContainText('Academic configuration updated');

        await page.goto(`${root}/structure`);
        const unitSection = page
            .locator('section')
            .filter({ has: page.getByRole('heading', { name: 'Academic units', exact: true }) });
        const unitForm = unitSection.locator('form').first();
        await unitForm.getByLabel('Name').fill(`Science ${suffix}`);
        await unitForm.getByLabel('Code').fill(`SCI-${runId}`);
        await unitForm.getByRole('button', { name: 'Create unit' }).click();
        await expect(unitSection.locator('tbody tr').filter({ hasText: `Science ${suffix}` })).toBeVisible();
        await expect(
            unitSection
                .getByRole('navigation', { name: 'Academic unit hierarchy' })
                .getByText(`Science ${suffix}`),
        ).toBeVisible();

        const levelSection = page
            .locator('section')
            .filter({ has: page.getByRole('heading', { name: 'Levels', exact: true }) });
        const levelForm = levelSection.locator('form').first();
        await levelForm.getByLabel('Name').fill(`Foundation ${suffix}`);
        await levelForm.getByLabel('Code').fill(`FND-${runId}`);
        await levelForm.getByLabel('Category').fill('foundation');
        await levelForm.getByLabel('Sequence').fill('1');
        await levelForm.getByRole('button', { name: 'Create level' }).click();
        await expect(levelSection.locator('ul li').filter({ hasText: `Foundation ${suffix}` })).toBeVisible();

        const programForm = page
            .locator('form')
            .filter({ has: page.getByRole('button', { name: 'Create draft program' }) });
        await programForm.getByLabel('Program title').fill(`Science Program ${suffix}`);
        await programForm.getByLabel('Code').fill(`SP-${runId}`);
        await programForm.getByLabel('Academic level').selectOption({ label: `Foundation ${suffix}` });
        await programForm.getByLabel('Academic unit').selectOption({ label: `Science ${suffix}` });
        await programForm.getByLabel(`Main Campus ${runId}`).check();
        await programForm.getByRole('button', { name: 'Create draft program' }).click();
        const programCard = page
            .locator('article')
            .filter({ has: page.getByRole('heading', { name: `Science Program ${suffix}` }) });
        await expect(programCard).toBeVisible();
        await programCard.getByRole('button', { name: 'Activate' }).click();
        await expect(page.getByRole('status')).toContainText(`Program Science Program ${suffix} activated`);

        const classForm = page
            .locator('form')
            .filter({ has: page.getByRole('button', { name: 'Create academic group' }) });
        await classForm.getByLabel('Name').fill(`Grade One ${suffix}`);
        await classForm.getByLabel('Code').fill(`G1-${runId}`);
        await classForm.getByLabel('Level').selectOption({ label: `Foundation ${suffix}` });
        await classForm.getByLabel('Academic year').selectOption({ label: `Academic Year ${runId}` });
        await classForm.getByLabel('Campus').selectOption({ label: `Main Campus ${runId}` });
        await classForm.getByLabel('Program').selectOption({ label: `Science Program ${suffix}` });
        await classForm.getByRole('button', { name: 'Create academic group' }).click();
        const classCard = page.locator('article').filter({ hasText: `Grade One ${suffix}` });
        await expect(classCard).toBeVisible();
        const sectionForm = classCard.locator('form').filter({ has: page.getByLabel('Section code') });
        await sectionForm.getByLabel('Section code').fill(`G1A-${runId}`);
        await sectionForm.getByLabel('Section name').fill(`Section A ${suffix}`);
        await sectionForm.getByRole('button', { name: 'Add section' }).click();
        await expect(classCard.getByText(`Section A ${suffix}`, { exact: true })).toBeVisible();

        await classForm.getByLabel('Name').fill(`Grade Two ${suffix}`);
        await classForm.getByLabel('Code').fill(`G2-${runId}`);
        await classForm.getByLabel('Level').selectOption({ label: `Foundation ${suffix}` });
        await classForm.getByLabel('Academic year').selectOption({ label: `Academic Year ${runId}` });
        await classForm.getByLabel('Campus').selectOption({ label: `Main Campus ${runId}` });
        await classForm.getByLabel('Program').selectOption({ label: `Science Program ${suffix}` });
        await classForm.getByRole('button', { name: 'Create academic group' }).click();
        const destinationClassCard = page.locator('article').filter({ hasText: `Grade Two ${suffix}` });
        await expect(destinationClassCard).toBeVisible();
        const destinationSectionForm = destinationClassCard
            .locator('form')
            .filter({ has: page.getByLabel('Section code') });
        await destinationSectionForm.getByLabel('Section code').fill(`G2B-${runId}`);
        await destinationSectionForm.getByLabel('Section name').fill(`Section B ${suffix}`);
        await destinationSectionForm.getByRole('button', { name: 'Add section' }).click();
        await expect(destinationClassCard.getByText(`Section B ${suffix}`, { exact: true })).toBeVisible();

        const initialPlacementDate = new Date(Date.now() - 86_400_000).toISOString().slice(0, 10);
        const placeStudent = async (studentName: string) => {
            await page.goto(`/t/${tenantSlug}/sis/students`);
            await page.getByRole('link', { name: new RegExp(studentName) }).click();
            const placementForm = page
                .locator('form')
                .filter({ has: page.getByRole('button', { name: 'Preview change' }) });
            await placementForm.getByLabel('Action').selectOption('placement');
            await placementForm.getByLabel('Effective date').fill(initialPlacementDate);
            await placementForm
                .getByLabel('Class or cohort')
                .selectOption({ label: `Grade One ${suffix} · G1-${runId}` });
            await placementForm.getByLabel('Reason code').fill('browser_initial_placement');
            await placementForm.getByRole('button', { name: 'Preview change' }).click();
            await expect(page.getByRole('button', { name: 'Confirm change' })).toBeVisible();
            await page.getByRole('button', { name: 'Confirm change' }).click();
            const placements = page
                .locator('section')
                .filter({ has: page.getByRole('heading', { name: 'Academic placements' }) });
            await expect(placements.locator('li').filter({ hasText: `Grade One ${suffix}` })).toBeVisible();
        };

        await placeStudent(`Mina${runId} Learner`);
        await placeStudent(`Noor${runId} Learner`);

        await page.goto(`/t/${tenantSlug}/sis/students`);
        const importSection = page.locator('section').filter({
            has: page.getByRole('heading', { name: 'Bulk import' }),
        });
        const mappedImportFile = {
            name: `mapped-students-${runId}.csv`,
            mimeType: 'text/csv',
            buffer: Buffer.from(
                `Given,Family,Admission,Reference\nImported${runId},Learner,2026-09-01,IMP-${runId}\n`,
            ),
        };
        const importInput = importSection.locator('input[type="file"]');
        await importInput.setInputFiles(mappedImportFile);
        await importSection.getByRole('button', { name: 'Preview import' }).click();
        await expect(importSection.getByRole('heading', { name: 'Map CSV columns' })).toBeVisible();
        const importMappingAccessibility = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(importMappingAccessibility.violations).toEqual([]);
        await importSection.getByLabel(/Legal first name/).selectOption('Given');
        await importSection.getByLabel(/Legal family name/).selectOption('Family');
        await importSection.getByLabel(/Admission date/).selectOption('Admission');
        await importSection.getByLabel('Registration reference').selectOption('Reference');
        await importInput.setInputFiles(mappedImportFile);
        await importSection.getByRole('button', { name: 'Preview import' }).click();
        await expect(page.getByRole('status').first()).toContainText('1 valid rows, 0 invalid rows');
        await expect(importSection.getByText(/Mapped fields:/)).toBeVisible();
        await importSection.getByLabel('Confirm after preview').check();
        await importInput.setInputFiles(mappedImportFile);
        await importSection.getByRole('button', { name: 'Confirm import' }).click();
        await expect(page.getByRole('status').first()).toContainText('The import was queued');
        const recentImports = page.locator('section').filter({
            has: page.getByRole('heading', { name: 'Recent student imports' }),
        });
        await expect
            .poll(
                async () => {
                    await page.reload();
                    return recentImports.getByText('completed · 1 of 1 rows processed').count();
                },
                { timeout: 30_000, intervals: [500, 1000, 2000, 3000] },
            )
            .toBe(1);
        await expect(page.getByRole('link', { name: new RegExp(`Imported${runId} Learner`) })).toBeVisible();

        await page.getByLabel(`Select Mina${runId} Learner for bulk promotion`).check();
        await page.getByLabel(`Select Noor${runId} Learner for bulk promotion`).check();
        await page
            .getByLabel('Destination class or cohort')
            .selectOption({ label: `Grade Two ${suffix} (G2-${runId})` });
        await page.getByLabel('Effective date').fill(new Date().toISOString().slice(0, 10));
        await page.getByLabel('Reason code').fill('browser_bulk_promotion');
        await page.getByRole('button', { name: 'Review 2 selected students' }).click();
        await expect(page.getByText(/Review: 2 students will be promoted/)).toBeVisible();
        await page.getByRole('button', { name: 'Confirm bulk promotion' }).click();
        await expect(
            page
                .getByText(/Promotion request #\d+ was submitted for independent approval for 2 students/)
                .first(),
        ).toBeVisible();

        await page.goto(`/t/${tenantSlug}/members`);
        const promotionReviewerEmail = `promotion-reviewer-${runId}@example.test`;
        await page.getByLabel('Email address').fill(promotionReviewerEmail);
        await page.getByLabel('Institution role').selectOption('tenant.admin');
        const promotionReviewerInviteResponse = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                response.url().endsWith(`/t/${tenantSlug}/members/invitations`),
        );
        await page.getByRole('button', { name: 'Send invitation' }).click();
        const promotionReviewerInvitation = await promotionReviewerInviteResponse;
        expect(promotionReviewerInvitation.status()).toBe(302);
        const promotionReviewerInvitationUrl =
            await promotionReviewerInvitation.headerValue('x-e2e-invitation-url');
        expect(promotionReviewerInvitationUrl).toBeTruthy();
        promotionReviewerContext = await browser.newContext({ baseURL });
        const promotionReviewerPage = await promotionReviewerContext.newPage();
        await promotionReviewerPage.goto(promotionReviewerInvitationUrl!);
        await expect(promotionReviewerPage.getByRole('heading', { name: /Join .* Institute/ })).toBeVisible();
        await promotionReviewerPage.getByLabel('Your name').fill(`Promotion Reviewer ${runId}`);
        const promotionReviewerPassword = `Promotion-Reviewer-${runId}-42!`;
        await promotionReviewerPage.getByLabel('Create password').fill(promotionReviewerPassword);
        await promotionReviewerPage.getByLabel('Confirm password').fill(promotionReviewerPassword);
        await promotionReviewerPage.getByRole('button', { name: 'Create account and join' }).click();
        await expect(promotionReviewerPage).toHaveURL(new RegExp(`/t/${tenantSlug}/dashboard$`));
        await expect(
            promotionReviewerPage.getByRole('heading', { name: 'Institution administration' }),
        ).toBeVisible();
        await promotionReviewerPage.goto(`/t/${tenantSlug}/sis/students`);
        const promotionApprovalSection = promotionReviewerPage.locator('section').filter({
            has: promotionReviewerPage.getByRole('heading', { name: 'Promotion requests for review' }),
        });
        await expect(promotionApprovalSection).toBeVisible();
        const promotionRequest = promotionApprovalSection
            .locator('article')
            .filter({ hasText: `Mina${runId}` });
        await expect(promotionRequest).toContainText(`Noor${runId}`);
        await promotionRequest
            .getByLabel('Decision reason')
            .fill('Eligibility and destination capacity were verified.');
        await promotionRequest.getByRole('button', { name: 'Record decision' }).click();
        await expect(
            promotionReviewerPage.getByText('Promotion request approved for 2 students.', { exact: true }),
        ).toBeVisible();

        await page.goto(`/t/${tenantSlug}/sis/students`);
        await expect(page.getByRole('heading', { name: 'Student registry' })).toBeVisible();
        for (const studentName of [`Mina${runId} Learner`, `Noor${runId} Learner`]) {
            await page.getByRole('link', { name: new RegExp(studentName) }).click();
            const placements = page
                .locator('section')
                .filter({ has: page.getByRole('heading', { name: 'Academic placements' }) });
            await expect(placements.locator('li').filter({ hasText: `Grade Two ${suffix}` })).toBeVisible();
            await page.goto(`/t/${tenantSlug}/sis/students`);
        }

        await page.goto(`${root}/catalog`);
        const subjectForm = page
            .locator('form')
            .filter({ has: page.getByRole('button', { name: 'Add subject' }) });
        await subjectForm.getByLabel('Subject name').fill(`Physics ${suffix}`);
        await subjectForm.getByLabel('Code').fill(`PHY-${runId}`);
        await subjectForm.getByLabel('Academic unit').selectOption({ label: `Science ${suffix}` });
        await subjectForm.getByRole('button', { name: 'Add subject' }).click();
        await expect(page.getByRole('heading', { name: `Physics ${suffix}` })).toBeVisible();

        const courseForm = page
            .locator('form')
            .filter({ has: page.getByRole('button', { name: 'Add course' }) });
        await courseForm.getByLabel('Course title').fill(`Applied Physics ${suffix}`);
        await courseForm.getByLabel('Course code').fill(`APH-${runId}`);
        await courseForm.getByLabel('Subject').selectOption({ label: `Physics ${suffix}` });
        await courseForm.getByLabel('Academic level').selectOption({ label: `Foundation ${suffix}` });
        await courseForm.getByRole('button', { name: 'Add course' }).click();
        const courseCard = page.locator('article').filter({ hasText: `Applied Physics ${suffix}` });
        await expect(courseCard).toBeVisible();

        const policyForm = page
            .locator('form')
            .filter({ has: page.getByRole('button', { name: 'Create draft policy version' }) });
        await policyForm.getByLabel('Policy key').fill(`SCI-${runId}`);
        await policyForm.getByLabel('Name').fill(`Science credit policy ${suffix}`);
        await policyForm.getByLabel('Allowed increment').fill('0.5');
        await policyForm.getByLabel('Maximum credits').fill('6');
        await policyForm.getByLabel('Theory contact hours / credit').fill('15');
        await policyForm.getByLabel('Laboratory contact hours / credit').fill('30');
        await policyForm.getByLabel('Separate theory / laboratory credits').check();
        await policyForm.getByRole('button', { name: 'Create draft policy version' }).click();
        await expect(page.getByRole('status')).toContainText('created as a draft');
        const policyRow = page.locator('li').filter({ hasText: `Science credit policy ${suffix}` });
        await policyRow.getByRole('button', { name: 'Activate' }).click();
        await expect(page.getByRole('status')).toContainText('Credit policy activated');

        const outcomeForm = page
            .locator('form')
            .filter({ has: page.getByRole('button', { name: 'Add outcome reference' }) });
        await outcomeForm.getByLabel('Outcome code').fill(`PHY-LO1-${runId}`);
        await outcomeForm.getByLabel('Outcome name').fill(`Explain motion ${suffix}`);
        await outcomeForm.getByLabel('Academic level').selectOption({ label: `Foundation ${suffix}` });
        await outcomeForm.getByLabel('Competency category').fill('scientific reasoning');
        await outcomeForm.getByLabel('Description').fill('Explain motion using observable evidence.');
        await outcomeForm.getByRole('button', { name: 'Add outcome reference' }).click();
        await expect(page.getByText(`Explain motion ${suffix}`, { exact: true })).toBeVisible();

        await courseCard.getByText('Manage versions and learning outcomes').click();
        const versionForm = courseCard.locator('form').filter({ has: page.getByLabel('Version title') });
        await versionForm.getByLabel('Version title').fill(`Applied Physics 1 ${suffix}`);
        const creditBearing = versionForm.getByLabel('Credit-bearing');
        await creditBearing.check();
        await expect(creditBearing).toBeChecked();
        const activePolicy = versionForm.getByLabel('Active credit policy');
        await expect(activePolicy).toBeEnabled();
        await expect(
            activePolicy.locator('option', { hasText: `Science credit policy ${suffix}` }),
        ).toBeAttached();
        await activePolicy.selectOption({
            label: `Science credit policy ${suffix} · 0.500 increment`,
        });
        await versionForm.getByLabel('Theory credits').fill('3');
        await versionForm.getByLabel('Laboratory credits').fill('0.5');
        await versionForm.getByRole('button', { name: 'Create immutable draft version' }).click();
        await expect(page.getByRole('status')).toContainText('created as a draft');
        const publishButton = courseCard.getByRole('button', { name: 'Publish' });
        if (!(await publishButton.isVisible())) {
            await courseCard.getByText('Manage versions and learning outcomes').click();
        }
        await expect(publishButton).toBeVisible();
        page.once('dialog', (dialog) => dialog.accept());
        await publishButton.click();
        await expect(page.getByRole('status')).toContainText('published and set current');
        const outcomeLink = courseCard.getByLabel('Link outcome to current version');
        if (!(await outcomeLink.isVisible())) {
            await courseCard.getByText('Manage versions and learning outcomes').click();
        }
        await outcomeLink.selectOption({ label: `PHY-LO1-${runId} · Explain motion ${suffix}` });
        await courseCard.getByRole('button', { name: 'Link outcome' }).click();
        await expect(page.getByRole('status')).toContainText('Learning outcome linked');

        await page.goto(`${root}/quality`);
        const objectiveForm = page
            .locator('form')
            .filter({ has: page.getByRole('button', { name: 'Create draft objective' }) });
        await objectiveForm.getByLabel('Objective code').fill(`QO-${runId}`);
        await objectiveForm.getByLabel('Name').fill(`Improve science resources ${suffix}`);
        await objectiveForm
            .getByLabel('Description')
            .fill('Review the availability of science learning resources.');
        await objectiveForm
            .getByLabel('Metric definition')
            .fill('Percentage of required resources available.');
        await objectiveForm.getByLabel('Review every (months)').fill('6');
        await objectiveForm.getByRole('button', { name: 'Create draft objective' }).click();
        await expect(page.getByRole('status')).toContainText('created as a draft');
        await expect(
            page.locator('article').filter({ hasText: `Improve science resources ${suffix}` }),
        ).toBeVisible();
    });

    test('institution owner authors and independently publishes a curriculum version', async ({
        browser,
    }) => {
        test.setTimeout(180_000);
        if (!tenantSlug || !runId) throw new Error('The test tenant was not provisioned.');
        const page = await getInstitutionOwnerPage(browser);
        const root = `/t/${tenantSlug}/academics`;
        const suffix = `Browser ${runId}`;
        const frameworkCode = `FW-${runId}`;
        const curriculumCode = `CUR-${runId}`;
        const curriculumTitle = `Science curriculum ${suffix}`;

        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(`${root}/curriculum`);
        const frameworkForm = page.locator('form').filter({
            has: page.getByRole('button', { name: 'Create framework draft' }),
        });
        await frameworkForm.getByLabel('Code').fill(frameworkCode);
        await frameworkForm.getByLabel('Title').fill(`Local framework ${suffix}`);
        await frameworkForm.getByRole('button', { name: 'Create framework draft' }).click();
        await expect(page.getByRole('status')).toContainText(
            `Framework ${frameworkCode} created as a draft.`,
        );
        const frameworkCard = page.locator('li').filter({ hasText: frameworkCode });
        await frameworkCard
            .getByLabel('Learning outcome')
            .selectOption({ label: `PHY-LO1-${runId} · Explain motion ${suffix}` });
        await frameworkCard.getByRole('button', { name: 'Save outcome requirement' }).click();
        await expect(page.getByRole('status')).toContainText('Framework outcome requirement saved.');
        await frameworkCard.getByRole('link', { name: 'View framework history' }).click();
        await expect(page.getByRole('heading', { name: `Local framework ${suffix} history` })).toBeVisible();
        await expect(page.getByText('academic curriculum framework outcome configured')).toBeVisible();
        await page.getByRole('link', { name: 'Back to framework library' }).click();

        const curriculumForm = page.locator('form').filter({
            has: page.getByRole('button', { name: 'Create draft curriculum' }),
        });
        await curriculumForm
            .getByLabel('Academic program')
            .selectOption({ label: `SP-${runId} · Science Program ${suffix}` });
        await curriculumForm
            .getByLabel('Framework')
            .selectOption({ label: `${frameworkCode} · Local framework ${suffix}` });
        await curriculumForm.getByLabel('Plan code').fill(curriculumCode);
        await curriculumForm.getByLabel('Plan title').fill(curriculumTitle);
        await Promise.all([
            page.waitForURL(/\/academics\/curriculum\/curricula\/\d+/),
            curriculumForm.getByRole('button', { name: 'Create draft curriculum' }).click(),
        ]);
        await expect(page.getByRole('heading', { name: curriculumTitle })).toBeVisible();
        const curriculumUrl = page.url();
        const completionRulesSection = page.locator('section[aria-labelledby="completion-rules-heading"]');
        const completionRuleForm = completionRulesSection.locator('form').last();
        await completionRuleForm.getByLabel('Code').fill(`MIN-UNITS-${runId}`);
        await completionRuleForm.getByLabel('Rule name').fill(`Required unit count ${suffix}`);
        await completionRuleForm.getByLabel('Rule type').selectOption('required_units');
        await completionRuleForm.getByLabel('Target value').fill('1');
        await completionRuleForm.getByRole('button', { name: 'Add requirement' }).click();
        await expect(page.getByRole('status')).toContainText('Curriculum completion rule added');
        await expect(completionRulesSection).toContainText('Plan gap');

        await page.getByRole('link', { name: 'Import outline' }).click();
        const invalidOutline = Buffer.from(
            `code,parent_code,type,title,description,is_required,estimated_minutes,workload_unit,period_code,planned_starts_on,planned_ends_on\nDUP-${runId},,unit,Duplicate one,,true,30,minutes,,,\nDUP-${runId},,unit,Duplicate two,,true,30,minutes,,,\n`,
        );
        await page.locator('input[type="file"]').first().setInputFiles({
            name: 'invalid-curriculum-outline.csv',
            mimeType: 'text/csv',
            buffer: invalidOutline,
        });
        await page.getByRole('button', { name: 'Run dry-run preview' }).click();
        await expect(page.getByRole('heading', { name: 'Dry-run preview' })).toBeVisible();
        await expect(page.getByRole('alert')).toContainText('duplicated in the file');
        const errorReportDownload = page.waitForEvent('download');
        await page.getByRole('link', { name: 'Download validation error report' }).click();
        const downloadedErrors = await errorReportDownload;
        expect(downloadedErrors.suggestedFilename()).toBe('curriculum-import-errors.csv');
        const errorStream = await downloadedErrors.createReadStream();
        if (!errorStream) throw new Error('The curriculum validation report was not streamed.');
        const errorChunks: Buffer[] = [];
        for await (const chunk of errorStream) errorChunks.push(Buffer.from(chunk));
        expect(Buffer.concat(errorChunks).toString('utf8')).toContain('duplicated in the file');

        const outlineCsv = Buffer.from(
            `code,parent_code,type,title,description,is_required,estimated_minutes,workload_unit,period_code,planned_starts_on,planned_ends_on\nUNIT-${runId},,unit,Learning unit ${suffix},,true,60,minutes,,,\nTOPIC-${runId},UNIT-${runId},topic,Learning topic ${suffix},,false,30,minutes,,,\n`,
        );
        const outlineFile = { name: 'curriculum-outline.csv', mimeType: 'text/csv', buffer: outlineCsv };
        await page.locator('input[type="file"]').first().setInputFiles(outlineFile);
        await page.getByRole('button', { name: 'Run dry-run preview' }).click();
        await expect(page.getByRole('heading', { name: 'Dry-run preview' })).toBeVisible();
        await expect(page.getByText('2 valid items of 2.')).toBeVisible();
        const importUrl = page.url();
        await page.setViewportSize({ width: 390, height: 844 });
        const importMobileLayout = await page.evaluate(() => ({
            path: location.pathname,
            viewport: innerWidth,
            document: document.documentElement.scrollWidth,
            overflowingElements: Array.from(document.querySelectorAll<HTMLElement>('*'))
                .map((element) => {
                    const bounds = element.getBoundingClientRect();

                    return {
                        tag: element.tagName.toLowerCase(),
                        className: element.className,
                        left: Math.round(bounds.left),
                        right: Math.round(bounds.right),
                        width: Math.round(bounds.width),
                        scrollWidth: element.scrollWidth,
                        clientWidth: element.clientWidth,
                        text: element.innerText?.trim().replace(/\s+/g, ' ').slice(0, 80) ?? '',
                    };
                })
                .filter((element) => element.left < -1 || element.right > innerWidth + 1)
                .sort((left, right) => right.right - left.right)
                .slice(0, 20),
        }));
        expect(importMobileLayout.document, JSON.stringify(importMobileLayout)).toBeLessThanOrEqual(390);
        const importAccessibility = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(importAccessibility.violations).toEqual([]);
        for (const [theme, label] of [
            ['executive-navy', 'Executive Navy'],
            ['academic-emerald', 'Academic Emerald'],
            ['modern-indigo', 'Modern Indigo'],
            ['warm-ivory', 'Warm Ivory'],
            ['graphite-dark', 'Graphite Dark'],
            ['midnight-violet', 'Midnight Violet'],
        ]) {
            const themePicker = page.getByLabel('Choose theme');
            await themePicker.selectOption(theme);
            await expect(themePicker).toHaveValue(theme);
            await expect(page).toHaveURL(importUrl);
            await expect(page.locator('[data-theme]').first()).toHaveAttribute('data-theme', theme);
            const themeAccessibility = await new AxeBuilder({ page })
                .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
                .analyze();
            expect(themeAccessibility.violations, `${label} theme`).toEqual([]);
        }
        await page.locator('input[type="file"]').last().setInputFiles(outlineFile);
        await page.getByRole('button', { name: 'Confirm import of 2 items' }).click();
        await page.setViewportSize({ width: 1280, height: 900 });
        await expect(
            page.getByRole('status').filter({
                hasText: 'Import complete: 2 syllabus items were committed.',
            }),
        ).toBeVisible();
        await page.getByRole('link', { name: 'Back to curriculum draft' }).click();
        await expect(page).toHaveURL(/\/academics\/curriculum\/curricula\/\d+\?version=\d+$/);
        const returnedCurriculumUrl = new URL(page.url());
        expect(returnedCurriculumUrl.pathname).toBe(new URL(curriculumUrl).pathname);
        expect(returnedCurriculumUrl.searchParams.get('version')).toBe('1');
        await expect(completionRulesSection).toContainText('Plan meets target');
        await expect(
            page.getByRole('listitem', { name: new RegExp(`unit: Learning unit ${suffix}`) }),
        ).toBeVisible();

        const outcomeSelect = page.getByLabel(`Learning outcome for Learning unit ${suffix}`);
        await outcomeSelect.selectOption({ label: `PHY-LO1-${runId} · Explain motion ${suffix}` });
        const outcomeForm = outcomeSelect.locator('xpath=ancestor::form[1]');
        const mapOutcomeResponse = page.waitForResponse(
            (response) => response.request().method() === 'POST' && response.url().includes('/outcomes'),
        );
        await outcomeForm.getByRole('button', { name: 'Map outcome' }).click();
        expect((await mapOutcomeResponse).status()).toBe(302);
        await expect(page.getByRole('status')).toContainText('Learning outcome mapped');
        await expect(page).toHaveURL(new RegExp(`/academics/curriculum/curricula/\\d+\\?version=\\d+$`));

        const prerequisiteSelect = page.getByLabel(`Prerequisite for Learning topic ${suffix}`);
        await prerequisiteSelect.selectOption({ label: `Learning unit ${suffix}` });
        const prerequisiteForm = prerequisiteSelect.locator('xpath=ancestor::form[1]');
        await prerequisiteForm.getByRole('button', { name: 'Add' }).click();
        await expect(page.getByRole('status')).toContainText('Learning path dependency saved.');
        await page.getByRole('link', { name: 'Prerequisite explorer' }).click();
        await expect(
            page.getByRole('heading', { name: `${curriculumTitle} prerequisite explorer` }),
        ).toBeVisible();
        const dependencySection = page.getByRole('region', { name: 'Learning dependencies' });
        await expect(dependencySection.getByText(`Learning unit ${suffix}`, { exact: true })).toBeVisible();
        await expect(dependencySection.getByText(`Learning topic ${suffix}`, { exact: true })).toBeVisible();
        const pathwayAccessibility = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(pathwayAccessibility.violations).toEqual([]);
        await page.getByRole('link', { name: 'Back to curriculum plan' }).click();

        const reviewerEmail = `promotion-reviewer-${runId}@example.test`;
        const submitForm = page.locator('form').filter({
            has: page.getByRole('button', { name: 'Submit for review' }),
        });
        const submitForReview = page.getByRole('button', { name: 'Submit for review' });
        await expect(submitForReview).toBeVisible({ timeout: 10_000 });
        const reviewerSelect = submitForm.getByLabel('Independent reviewer');
        await expect(reviewerSelect).toBeVisible({ timeout: 10_000 });
        const reviewerOptionLabels = await reviewerSelect.locator('option').allTextContents();
        expect(reviewerOptionLabels).toContain(`Promotion Reviewer ${runId} · ${reviewerEmail}`);
        await reviewerSelect.selectOption({ label: `Promotion Reviewer ${runId} · ${reviewerEmail}` });
        await submitForm.getByRole('button', { name: 'Submit for review' }).click();
        await expect(page.getByRole('status')).toContainText(
            'Curriculum submitted to its assigned independent reviewer.',
        );

        const reviewerPage = promotionReviewerContext?.pages()[0];
        if (!reviewerPage) throw new Error('The independently authorized reviewer session is unavailable.');
        await reviewerPage.goto(`${root}/curriculum/reviews`);
        await expect(
            reviewerPage.getByRole('heading', { name: 'Assigned curriculum reviews' }),
        ).toBeVisible();
        await expect(reviewerPage.getByText(curriculumTitle, { exact: true })).toBeVisible();
        const reviewQueueAccessibility = await new AxeBuilder({ page: reviewerPage })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(reviewQueueAccessibility.violations).toEqual([]);
        await reviewerPage.getByRole('link', { name: 'Open assigned review' }).click();
        await expect(reviewerPage.getByRole('heading', { name: curriculumTitle })).toBeVisible();
        const reviewForm = reviewerPage.locator('form').filter({
            has: reviewerPage.getByRole('button', { name: 'Record review' }),
        });
        await reviewForm.getByLabel('Review decision').selectOption('request_changes');
        await reviewForm.getByLabel('Reviewer note').fill('Please clarify the learning topic description.');
        await reviewForm.getByRole('button', { name: 'Record review' }).click();
        await expect(reviewerPage.getByRole('status')).toContainText(
            'Curriculum review recorded: request_changes.',
        );

        await page.goto(curriculumUrl);
        const topicTitleField = page.getByLabel(`Title for Learning topic ${suffix}`);
        await expect(topicTitleField).toBeVisible();
        const topicEditForm = topicTitleField.locator('xpath=ancestor::form[1]');
        await topicTitleField.fill(`Learning topic ${suffix} revised`);
        await topicEditForm.getByRole('button', { name: 'Save item' }).click();
        await expect(page.getByRole('status')).toContainText('Syllabus item updated.');
        const resubmitForm = page.locator('form').filter({
            has: page.getByRole('button', { name: 'Submit for review' }),
        });
        await resubmitForm.getByLabel('Independent reviewer').selectOption({
            label: `Promotion Reviewer ${runId} · ${reviewerEmail}`,
        });
        await resubmitForm.getByRole('button', { name: 'Submit for review' }).click();
        await expect(page.getByRole('status')).toContainText(
            'Curriculum submitted to its assigned independent reviewer.',
        );
        await reviewerPage.goto(`${root}/curriculum/reviews`);
        await expect(reviewerPage.getByText(curriculumTitle, { exact: true })).toBeVisible();
        await reviewerPage.getByRole('link', { name: 'Open assigned review' }).click();
        await expect(reviewerPage.getByRole('heading', { name: curriculumTitle })).toBeVisible();
        const approvalForm = reviewerPage.locator('form').filter({
            has: reviewerPage.getByRole('button', { name: 'Record review' }),
        });
        await approvalForm.getByLabel('Review decision').selectOption('approve');
        await approvalForm.getByLabel('Reviewer note').fill('Curriculum structure and mappings reviewed.');
        await approvalForm.getByRole('button', { name: 'Record review' }).click();
        await expect(reviewerPage.getByRole('status')).toContainText('Curriculum review recorded: approve.');

        await page.goto(curriculumUrl);
        const publishCurriculumButton = page.getByRole('button', { name: 'Publish approved version' });
        await expect(publishCurriculumButton).toBeVisible();
        page.once('dialog', (dialog) => dialog.accept());
        await publishCurriculumButton.click();
        await expect(page.getByRole('status')).toContainText(
            'Approved curriculum version published and locked.',
        );
        await expect(page.getByText('Published snapshot is read-only')).toBeVisible();

        await page.getByRole('link', { name: 'Review history' }).click();
        await expect(page.getByRole('heading', { name: `${curriculumTitle} review history` })).toBeVisible();
        await expect(page.getByText('Curriculum structure and mappings reviewed.')).toBeVisible();
        await expect(page.getByText('Please clarify the learning topic description.')).toBeVisible();
        const reviewHistoryAccessibility = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(reviewHistoryAccessibility.violations).toEqual([]);
        await page.goto(curriculumUrl);

        await page.getByRole('link', { name: 'Print published syllabus' }).click();
        await expect(page.getByRole('heading', { name: 'Syllabus and learning plan' })).toBeVisible();
        await expect(page.getByRole('heading', { name: curriculumTitle })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Print or save as PDF' })).toBeVisible();
        const printableAccessibility = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(printableAccessibility.violations).toEqual([]);
        await page.getByRole('link', { name: 'Back to curriculum' }).click();

        const curriculumId = curriculumUrl.match(/curricula\/(\d+)/)?.[1];
        if (!curriculumId) throw new Error('The created curriculum identifier is missing from its route.');
        await page.getByRole('link', { name: 'Outcome matrix' }).click();
        await expect(page.getByRole('heading', { name: 'Outcome mapping matrix' })).toBeVisible();
        await expect(page.getByText(`PHY-LO1-${runId}`, { exact: false })).toBeVisible();
        await expect(page.getByText('introduced', { exact: true })).toBeVisible();
        const matrixAccessibility = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(matrixAccessibility.violations).toEqual([]);

        await page.getByRole('link', { name: 'Open syllabus' }).click();
        await page.getByLabel('Revision note').fill('Test reviewer change comparison');
        await page.getByRole('button', { name: 'Create new revision' }).click();
        await expect(page.getByText('Version 2 · draft')).toBeVisible();
        await page.getByRole('link', { name: 'Outcome matrix' }).click();
        const strengthSelect = page.getByLabel(new RegExp(`Mapping strength for PHY-LO1-${runId}`));
        const strengthResponse = page.waitForResponse(
            (response) => response.request().method() === 'POST' && response.url().includes('/outcomes'),
        );
        await strengthSelect.selectOption('mastered');
        expect((await strengthResponse).status()).toBe(302);
        await expect(strengthSelect).toHaveValue('mastered');

        await page.goto(`${root}/curriculum/curricula/${curriculumId}/compare`);
        await expect(page.getByRole('heading', { name: 'Version comparison' })).toBeVisible();
        await expect(page.getByText('Outcomes:', { exact: false })).toBeVisible();
        await page.goto(`${root}/curriculum/quality`);
        await expect(page.getByRole('heading', { name: 'Curriculum quality' })).toBeVisible();
        await expect(page.getByText(curriculumTitle, { exact: false }).first()).toBeVisible();
        await page.goto(`${root}/curriculum/analytics`);
        await expect(page.getByRole('heading', { name: 'Curriculum analytics' })).toBeVisible();
        await page
            .getByLabel('Academic program')
            .selectOption({ label: `SP-${runId} · Science Program ${suffix}` });
        await page.getByRole('button', { name: 'Apply filters' }).click();
        await expect(page.getByText(curriculumTitle, { exact: false }).first()).toBeVisible();
        await page.setViewportSize({ width: 390, height: 844 });
        await expect(page.getByRole('heading', { name: 'Curriculum analytics' })).toBeVisible();
        const mobileLayout = await page.evaluate(() => {
            const overflow = Array.from(document.querySelectorAll<HTMLElement>('body *'))
                .map((element) => {
                    const bounds = element.getBoundingClientRect();

                    return {
                        tag: element.tagName,
                        className: String(element.className).slice(0, 100),
                        text: element.innerText?.slice(0, 80),
                        left: Math.round(bounds.left),
                        right: Math.round(bounds.right),
                        width: Math.round(bounds.width),
                    };
                })
                .filter((element) => element.left < -1 || element.right > window.innerWidth + 1)
                .slice(0, 12);

            return {
                url: window.location.pathname,
                viewportWidth: window.innerWidth,
                documentWidth: document.documentElement.scrollWidth,
                overflow,
            };
        });
        expect(mobileLayout.documentWidth, JSON.stringify(mobileLayout)).toBeLessThanOrEqual(
            mobileLayout.viewportWidth,
        );

        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(curriculumUrl);
        await page.getByRole('button', { name: 'Share published template' }).click();
        await expect(page.getByRole('status')).toContainText(
            'Published curriculum is now available as a read-only shared template.',
        );
        await page.goto(`${root}/curriculum`);
        const templateCard = page.locator('article').filter({
            has: page.getByRole('heading', { name: curriculumTitle, exact: true }),
        });
        await expect(templateCard).toBeVisible();
        await templateCard
            .getByLabel('Local academic program')
            .selectOption({ label: `SP-${runId} · Science Program ${suffix}` });
        await templateCard.getByLabel('Local curriculum code').fill(`COPY-${runId}`);
        await templateCard.getByLabel('Local curriculum title').fill(`Template copy ${suffix}`);
        await templateCard.getByRole('button', { name: 'Adopt an independent copy' }).click();
        await expect(page.getByRole('status')).toContainText(
            'A new editable copy was created from the shared template.',
        );
        await expect(page.getByRole('heading', { name: `Template copy ${suffix}` })).toBeVisible();
        await expect(
            page.getByRole('listitem', { name: new RegExp(`unit: Learning unit ${suffix}`) }),
        ).toBeVisible();
    });

    test('a member invitation is delivered locally and activates its tenant membership', async ({
        browser,
    }) => {
        if (!tenantSlug || !runId) throw new Error('The test tenant was not provisioned.');
        const page = await getInstitutionOwnerPage(browser);

        await page.goto(`/t/${tenantSlug}/members`);
        const inviteEmail = `invite-${runId}@example.test`;
        await page.getByLabel('Email address').fill(inviteEmail);
        await page.getByLabel('Institution role').selectOption('tenant.admin');
        const invitationResponse = page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                response.url().endsWith(`/t/${tenantSlug}/members/invitations`),
        );
        await page.getByRole('button', { name: 'Send invitation' }).click();
        const response = await invitationResponse;
        expect(response.status()).toBe(302);
        await expect(page.getByRole('status')).toContainText('Invitation sent');
        await expect(page.getByText(inviteEmail, { exact: true })).toBeVisible();
        await expect(page.getByText(/Expires/)).toBeVisible();

        const acceptanceUrl = await response.headerValue('x-e2e-invitation-url');
        expect(acceptanceUrl).toBeTruthy();
        const inviteeContext = await browser.newContext({ baseURL });
        const inviteePage = await inviteeContext.newPage();
        await inviteePage.goto(acceptanceUrl!);
        await expect(inviteePage.getByRole('heading', { name: /Join .* Institute/ })).toBeVisible();
        await inviteePage.getByLabel('Your name').fill(`Invited Member ${runId}`);
        const inviteePassword = `Invite-${runId}-Password-42!`;
        await inviteePage.getByLabel('Create password').fill(inviteePassword);
        await inviteePage.getByLabel('Confirm password').fill(inviteePassword);
        await inviteePage.getByRole('button', { name: 'Create account and join' }).click();
        await expect(inviteePage).toHaveURL(new RegExp(`/t/${tenantSlug}/dashboard$`));
        await expect(inviteePage.getByRole('heading', { name: 'Institution administration' })).toBeVisible();
        invitedMemberPage = inviteePage;
        invitedMemberContext = inviteeContext;
    });

    test('a clean-scanned transfer certificate supports independently reviewed transfer and rejects unsupported uploads', async ({
        browser,
    }) => {
        test.skip(
            !realClamAvE2E,
            'Run with the isolated local ClamAV E2E configuration to exercise scanning.',
        );
        // This journey performs multiple real scanner metadata checks and
        // document scans; leave the scanner's own bounded timeout in control.
        test.setTimeout(240_000);
        if (!tenantSlug || !runId)
            throw new Error('The isolated browser setup did not provision a run identity.');

        // This journey owns its tenant and actors so it also runs reliably when
        // selected by grep or retried independently. It does not rely on state
        // written by the preceding SIS registration or invitation scenarios.
        const transferTenantSlug = `phase12-transfer-${runId.slice(0, 12)}`;
        const transferOwnerEmail = `phase12-transfer-owner-${runId}@example.test`;
        const transferReviewerEmail = `phase12-transfer-reviewer-${runId}@example.test`;
        const platform = await getPlatformAdminPage(browser);
        await platform.goto('/platform/tenants/create');
        await platform.getByLabel('Official institution name').fill(`Phase 12 Transfer Institute ${runId}`);
        await platform.getByLabel('Display name').fill(`Transfer Institute ${runId}`);
        await platform.getByLabel('Preferred workspace slug').fill(transferTenantSlug);
        await platform.getByLabel('Institution type').selectOption('academy');
        await platform.getByLabel('Initial institution owner email').fill(transferOwnerEmail);
        const provisionResponsePromise = platform.waitForResponse(
            (response) =>
                response.request().method() === 'POST' && response.url().endsWith('/platform/tenants'),
        );
        await platform.getByRole('button', { name: 'Provision institution' }).click();
        const provisionResponse = await provisionResponsePromise;
        expect(provisionResponse.status()).toBeLessThan(400);
        const ownerInvitationUrl = await provisionResponse.headerValue('x-e2e-invitation-url');
        expect(ownerInvitationUrl).toBeTruthy();

        transferOwnerContext = await browser.newContext({ baseURL });
        const owner = await transferOwnerContext.newPage();
        const transferOwnerPassword = `Transfer-Owner-${runId}-Password-42!`;
        await owner.goto(ownerInvitationUrl!);
        await expect(owner.getByRole('heading', { name: /Join .* Institute/ })).toBeVisible();
        await owner.getByLabel('Your name').fill(`Transfer Owner ${runId}`);
        await owner.getByLabel('Create password').fill(transferOwnerPassword);
        await owner.getByLabel('Confirm password').fill(transferOwnerPassword);
        await owner.getByRole('button', { name: 'Create account and join' }).click();
        await expect(owner).toHaveURL(new RegExp(`/t/${transferTenantSlug}/dashboard$`));

        await owner.goto(`/t/${transferTenantSlug}/sis/students`);
        await expect(owner.getByRole('heading', { name: 'Student registry' })).toBeVisible();
        await owner.getByRole('button', { name: 'Register student' }).click();
        await owner.getByLabel('Legal first name').fill(`Transfer${runId}`);
        await owner.getByLabel('Legal family name').fill('Learner');
        await owner.getByLabel('Admission date').fill(new Date().toISOString().slice(0, 10));
        await owner.getByRole('button', { name: 'Save student record' }).click();
        await expect(owner).toHaveURL(/\/sis\/students\/\d+$/);
        const studentProfileUrl = new URL(owner.url()).pathname;
        await expect(
            owner.getByRole('heading', { name: new RegExp(`Transfer${runId} Learner`) }),
        ).toBeVisible();

        const certificateName = `transfer-certificate-${runId}.pdf`;
        await owner.getByLabel('Document category').selectOption('transfer_certificate');
        await owner.getByLabel(/PDF, JPEG or PNG/).setInputFiles({
            name: certificateName,
            mimeType: 'application/pdf',
            buffer: Buffer.from('%PDF-1.7\nIndependent Phase 12 transfer fixture\n'),
        });
        const certificateUpload = owner.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                new URL(response.url()).pathname.endsWith('/private-documents'),
        );
        await owner.getByRole('button', { name: 'Upload to quarantine' }).click();
        expect((await certificateUpload).status()).toBeLessThan(400);
        const documents = owner
            .locator('section')
            .filter({ has: owner.getByRole('heading', { name: 'Documents' }) });
        await expect(documents.getByRole('link', { name: 'Download' })).toHaveCount(0);
        await expect
            .poll(
                async () => {
                    await owner.reload();
                    return documents.getByText(/scan clean/).count();
                },
                { timeout: 180_000, intervals: [1_000, 3_000, 5_000] },
            )
            .toBe(1);
        await expect(documents.getByRole('link', { name: 'Download' })).toHaveCount(1);

        const reviewer = await inviteAndAcceptMember(
            browser,
            owner,
            transferTenantSlug,
            runId,
            transferReviewerEmail,
            'tenant.admin',
            `Independent Transfer Reviewer ${runId}`,
        );
        // The invitation helper uses the owner's page to send the invite.
        // Return to the student record before exercising transfer controls.
        await owner.goto(studentProfileUrl);
        const transferSection = owner.locator('section').filter({
            has: owner.getByRole('heading', { name: 'External transfer history' }),
        });
        await expect(transferSection.getByLabel('Direction')).toBeVisible({ timeout: 15_000 });
        await transferSection.getByLabel('Direction').selectOption('outgoing');
        await transferSection.getByLabel('Receiving institution').fill(`Verified Destination ${runId}`);
        await transferSection.getByLabel('Institution reference').fill(`DEST-${runId}`);
        await transferSection.getByLabel('Country code').fill('GB');
        // Tenant fixtures default to UTC; use the institution's configured
        // timezone for the date-only effective field so it is not tomorrow
        // from the server's perspective near midnight in another timezone.
        const effectiveDate = new Intl.DateTimeFormat('en-CA', { timeZone: 'UTC' }).format(new Date());
        await transferSection.getByLabel('Effective date').fill(effectiveDate);
        await transferSection
            .getByLabel('Reason for transfer')
            .fill('The learner is moving to another institution.');
        await transferSection
            .getByLabel('Clean transfer certificate')
            .selectOption({ label: certificateName });
        await transferSection.getByRole('button', { name: 'Submit for independent review' }).click();
        await expect(owner.getByRole('status')).toContainText('Transfer request submitted');

        const transferCard = transferSection
            .locator('li')
            .filter({ hasText: `Verified Destination ${runId}` });
        await expect(transferCard).toContainText('pending review');
        await reviewer.goto(studentProfileUrl);
        const reviewerTransferCard = reviewer
            .locator('section')
            .filter({
                has: reviewer.getByRole('heading', { name: 'External transfer history' }),
            })
            .locator('li')
            .filter({ hasText: `Verified Destination ${runId}` });
        await reviewerTransferCard.getByLabel('Independent decision').selectOption('approved');
        await reviewerTransferCard
            .getByLabel('Review note')
            .fill('Transfer evidence and request details were reviewed.');
        await reviewerTransferCard.getByRole('button', { name: 'Record decision' }).click();
        await expect(reviewer.getByRole('status')).toContainText('Transfer decision recorded');
        await expect(reviewerTransferCard).toContainText('approved');

        await owner.goto(studentProfileUrl);
        const approvedTransferCard = owner
            .locator('section')
            .filter({
                has: owner.getByRole('heading', { name: 'External transfer history' }),
            })
            .locator('li')
            .filter({ hasText: `Verified Destination ${runId}` });
        await approvedTransferCard
            .getByLabel('Completion record')
            .fill('Student transfer completed after effective date.');
        await approvedTransferCard.getByRole('button', { name: 'Record transfer completion' }).click();
        await expect(owner.getByRole('status')).toContainText('Approved transfer completed');
        await expect(approvedTransferCard).toContainText('completed');
        await expect(owner.getByText('transferred', { exact: true }).first()).toBeVisible();

        await owner.getByLabel('Document category').selectOption('other');
        await owner.getByLabel(/PDF, JPEG or PNG/).setInputFiles({
            name: `unsupported-${runId}.exe`,
            mimeType: 'application/octet-stream',
            buffer: Buffer.from('Unsupported executable upload fixture.'),
        });
        const unsupportedUpload = owner.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                new URL(response.url()).pathname.endsWith('/private-documents'),
        );
        await owner.getByRole('button', { name: 'Upload to quarantine' }).click();
        expect([302, 303]).toContain((await unsupportedUpload).status());
        await expect(owner.getByRole('alert')).toContainText(/file/i);
        await expect(documents.locator('li').filter({ hasText: `unsupported-${runId}.exe` })).toHaveCount(0);
    });

    test('staff reviews, independently approves, issues and accepts an offer, then queues the SIS handoff', async ({
        browser,
    }) => {
        test.setTimeout(90_000);
        if (!tenantSlug || !submittedApplicationNumber || !invitedMemberPage) {
            throw new Error('The applicant and independent approver fixtures were not provisioned.');
        }

        const owner = await getInstitutionOwnerPage(browser);
        await owner.goto(`/t/${tenantSlug}/admissions`);
        const applicationRow = owner.locator('tbody tr').filter({ hasText: submittedApplicationNumber });
        await expect(applicationRow).toBeVisible();
        const reviewHref = await applicationRow.getByRole('link', { name: 'Review' }).getAttribute('href');
        if (!reviewHref) throw new Error('The admissions queue did not provide a review link.');
        staffApplicationPath = reviewHref;
        await owner.goto(reviewHref);
        const applicationPath = staffApplicationPath;
        await expect(
            owner.getByRole('heading', { name: `Application ${submittedApplicationNumber}` }),
        ).toBeVisible();

        const moveTo = owner.getByLabel('Move to');
        await moveTo.selectOption('under_review');
        await owner.getByRole('button', { name: 'Update status' }).click();
        await expect(owner.getByText('under review', { exact: true }).first()).toBeVisible();
        await owner.getByLabel('Move to').selectOption('decision_pending');
        await owner.getByRole('button', { name: 'Update status' }).click();
        await expect(owner.getByText('decision pending', { exact: true }).first()).toBeVisible();

        await owner.getByLabel('Decision').selectOption('admit');
        await owner
            .getByLabel('Applicant-facing reason')
            .fill('The application meets the published admission criteria.');
        await owner.getByLabel('Private reason').fill('Independent browser verification recommendation.');
        await owner.getByRole('button', { name: 'Submit recommendation' }).click();
        await expect(owner.getByText('admit · awaiting approval', { exact: true })).toBeVisible();
        await expect(owner.getByRole('button', { name: 'Approve recommendation' })).toHaveCount(0);

        await invitedMemberPage.goto(applicationPath);
        await expect(invitedMemberPage.getByRole('button', { name: 'Approve recommendation' })).toBeVisible();
        await invitedMemberPage.getByRole('button', { name: 'Approve recommendation' }).click();
        await expect(invitedMemberPage.getByText('admit · approved', { exact: true })).toBeVisible();

        await owner.goto(applicationPath);
        await owner.getByLabel('Conditions').fill('Confirm enrollment documents before the stated deadline.');
        await owner.getByRole('button', { name: 'Issue offer' }).click();
        await expect(owner.getByText(/Offer issued and the seat reserved transactionally/)).toBeVisible();

        const applicantEmail = process.env.E2E_APPLICANT_EMAIL;
        const applicantPassword = process.env.E2E_APPLICANT_PASSWORD;
        if (!applicantEmail || !applicantPassword) throw new Error('The applicant account is unavailable.');
        const applicantContext = await browser.newContext({ baseURL });
        const applicantPage = await applicantContext.newPage();
        await signIn(applicantPage, applicantEmail, applicantPassword);
        await applicantPage.goto(`/admissions/${tenantSlug}/applications/${submittedApplicationNumber}`);
        await expect(applicantPage.getByRole('heading', { name: 'Admission offer' })).toBeVisible();
        await applicantPage.getByRole('button', { name: 'Accept offer' }).click();
        await expect(applicantPage.getByText(/Your offer response was recorded/)).toBeVisible();
        await expect(applicantPage.getByText('Status: accepted', { exact: true })).toBeVisible();
        await applicantContext.close();

        await owner.goto(applicationPath);
        owner.once('dialog', (dialog) => void dialog.accept());
        await owner.getByRole('button', { name: 'Queue SIS handoff' }).click();
        await expect(owner.getByText(/SIS handoff .* is queued for Phase 05/)).toBeVisible();
        await expect(owner.getByText(/SIS handoff: pending/)).toBeVisible();
    });

    test('custom role creation accepts the selected scoped permissions', async ({ browser }) => {
        if (!tenantSlug || !runId) throw new Error('The test tenant was not provisioned.');
        const page = await getInstitutionOwnerPage(browser);

        await page.goto(`/t/${tenantSlug}/roles`);
        const roleKey = `campus-support-${runId}`;
        const roleName = `Campus Support ${runId}`;
        await page.getByLabel('Role key').fill(roleKey);
        await page.getByLabel('Role name').fill(roleName);
        await page.getByLabel('Description').fill('Browser verification role.');
        await page
            .locator('label')
            .filter({ hasText: 'campus.view' })
            .locator('input[type="checkbox"]')
            .check();
        await page.getByRole('button', { name: 'Create role' }).click();
        await expect(page.getByRole('status')).toContainText(`Role ${roleName} created`);
        await expect(page.getByRole('heading', { name: roleName })).toBeVisible();
    });

    test('branding and user theme choices persist across page loads', async ({ browser }) => {
        test.setTimeout(90_000);
        if (!tenantSlug || !runId) throw new Error('The test tenant was not provisioned.');
        const page = await getInstitutionOwnerPage(browser);

        await page.goto(`/t/${tenantSlug}/branding`);
        const existingUserTheme = await page.locator('[data-theme]').getAttribute('data-theme');
        expect(existingUserTheme).toBeTruthy();
        // Persist a personal choice before changing the tenant default so this
        // assertion proves user preference precedence, not inherited fallback.
        await page.getByLabel('Choose theme').selectOption(existingUserTheme!);
        await expect(page.getByRole('status')).toContainText('Your theme preference is saved.');
        await page.getByLabel('Institution display name').fill(`Brand ${runId}`);
        await page.getByLabel('Institution default theme').selectOption('warm-ivory');
        await page.getByLabel('Primary brand color').fill('#244A72');
        await page.getByLabel('Accent color').fill('#0A8999');
        await page.getByRole('button', { name: 'Save branding' }).click();
        await expect(page.getByRole('status')).toContainText('Branding saved');
        await expect(page.locator(`[data-theme="${existingUserTheme}"]`)).toBeVisible();
        await expect(page.getByLabel('Institution default theme')).toHaveValue('warm-ivory');

        await page.getByLabel('Choose theme').selectOption('midnight-violet');
        await expect(page.locator('[data-theme="midnight-violet"]')).toBeVisible();
        await page.reload();
        await expect(page.locator('[data-theme="midnight-violet"]')).toBeVisible();
        await expect(page.getByLabel('Institution default theme')).toHaveValue('warm-ivory');
        await expect(page.getByLabel('Institution display name')).toHaveValue(`Brand ${runId}`);

        for (const theme of [
            'executive-navy',
            'academic-emerald',
            'modern-indigo',
            'warm-ivory',
            'graphite-dark',
            'midnight-violet',
        ]) {
            await page.getByLabel('Choose theme').selectOption(theme);
            await expect(page.locator(`[data-theme="${theme}"]`)).toBeVisible();
            await page.evaluate(async () => {
                await Promise.allSettled(document.getAnimations().map((animation) => animation.finished));
            });
            const accessibility = await new AxeBuilder({ page }).analyze();
            expect(accessibility.violations, `Theme ${theme} has accessibility violations`).toEqual([]);
        }
    });

    test('audit history records the workflow and a member cannot cross tenant or platform boundaries', async ({
        browser,
    }) => {
        if (!tenantSlug || !runId) throw new Error('The test tenant was not provisioned.');
        const page = await getInstitutionOwnerPage(browser);

        await page.goto(`/t/${tenantSlug}/audit`);
        await expect(page.getByRole('heading', { name: 'Institution audit history' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'membership invited' }).first()).toBeVisible();
        await expect(page.getByText('tenant branding updated', { exact: true })).toBeVisible();
        const campusCreatedEvent = page.getByRole('heading', { name: 'campus created', exact: true });
        const nextAuditPage = page
            .getByRole('navigation', { name: 'Audit pages' })
            .getByRole('link', { name: /Next/ });
        for (let pageIndex = 0; pageIndex < 10; pageIndex++) {
            if (await campusCreatedEvent.first().isVisible()) break;
            if (!(await nextAuditPage.isVisible())) break;
            const nextPageUrl = await nextAuditPage.getAttribute('href');
            if (!nextPageUrl) break;
            await page.goto(nextPageUrl);
        }
        await expect(campusCreatedEvent.first()).toBeVisible();

        if (!invitedMemberPage || !foreignTenantSlug) {
            throw new Error(
                'The test member and second institution were not provisioned by prior browser journeys.',
            );
        }
        const denied = await invitedMemberPage.goto(`/t/${foreignTenantSlug}/dashboard`);
        expect(denied?.status()).toBe(404);
        const platformDenied = await invitedMemberPage.goto('/platform/dashboard');
        expect(platformDenied?.status()).toBe(403);
    });

    test('institution workspace navigation is keyboard accessible and responsive', async ({ browser }) => {
        if (!tenantSlug) throw new Error('The test tenant was not provisioned.');
        const authenticatedPage = await getInstitutionOwnerPage(browser);
        // Start from a fresh browsing context tab so focus begins at the
        // document's first keyboard target, as it does for a real user.
        const page = await authenticatedPage.context().newPage();

        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto(`/t/${tenantSlug}/dashboard`);
        await page.bringToFront();
        const skipLink = page.getByRole('link', { name: 'Skip to content' });
        await skipLink.focus();
        await expect(skipLink).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/#main-content$/);

        await expect(page.getByRole('navigation', { name: 'Workspace' })).toBeHidden();
        await page.getByRole('button', { name: 'Open menu' }).click();
        await expect(page.getByRole('navigation', { name: 'Workspace' })).toBeVisible();
        await expect(
            page
                .getByRole('navigation', { name: 'Workspace' })
                .getByRole('link', { name: 'Campuses', exact: true }),
        ).toBeVisible();

        const widths = await page.evaluate(() => ({
            document: document.documentElement.scrollWidth,
            viewport: window.innerWidth,
        }));
        expect(widths.document).toBeLessThanOrEqual(widths.viewport);

        const accessibility = await new AxeBuilder({ page }).analyze();
        expect(accessibility.violations).toEqual([]);
        await page.close();
    });
});
