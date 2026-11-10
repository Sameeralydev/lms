import AxeBuilder from '@axe-core/playwright';
import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { expect, test, type Page } from '@playwright/test';

function cssColor(hex: string): string {
    const value = hex.replace('#', '');
    return `rgb(${parseInt(value.slice(0, 2), 16)}, ${parseInt(value.slice(2, 4), 16)}, ${parseInt(value.slice(4, 6), 16)})`;
}

const sixThemes = [
    'executive-navy',
    'academic-emerald',
    'modern-indigo',
    'warm-ivory',
    'graphite-dark',
    'midnight-violet',
];

async function auditSixAssessmentThemes(page: Page, screen: string): Promise<void> {
    const observedTextTokens = new Set<string>();
    await page.emulateMedia({ reducedMotion: 'reduce' });

    for (const theme of sixThemes) {
        await page.locator('#theme-choice').selectOption(theme);
        await expect(page.locator('[data-theme]').first()).toHaveAttribute('data-theme', theme);
        await page.evaluate(async () => {
            await Promise.all(
                document
                    .getAnimations()
                    .filter((animation) => animation.playState === 'running')
                    .map((animation) => animation.finished.catch(() => undefined)),
            );
        });
        observedTextTokens.add(
            await page
                .locator('[data-theme]')
                .first()
                .evaluate((element) =>
                    getComputedStyle(element).getPropertyValue('--color-text-primary').trim(),
                ),
        );
        const audit = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(audit.violations, `${screen} accessibility violations with ${theme}`).toEqual([]);
    }

    expect(observedTextTokens.size, `${screen} should use six visibly distinct theme tokens`).toBe(6);
    const viewport = page.viewportSize();
    await page.setViewportSize({ width: 390, height: 844 });
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
        `${screen} should reflow without page-level horizontal overflow on mobile`,
    ).toBe(true);
    await page.setViewportSize(viewport ?? { width: 1280, height: 900 });
    await page.reload();
    await expect(page.locator('#theme-choice')).toHaveValue(sixThemes.at(-1)!);
    await expect(page.locator('[data-theme]').first()).toHaveAttribute('data-theme', sixThemes.at(-1)!);
    await page.keyboard.press('Tab');
    const focusState = await page.evaluate(() => {
        const element = document.activeElement;
        if (!(element instanceof HTMLElement)) return null;
        const style = getComputedStyle(element);

        return {
            isKeyboardFocused: element.matches(':focus-visible'),
            hasVisibleIndicator: style.outlineStyle !== 'none' && style.outlineWidth !== '0px',
        };
    });
    expect(focusState, `${screen} should expose controls to keyboard navigation`).not.toBeNull();
    expect(focusState?.isKeyboardFocused, `${screen} focus should be keyboard-visible`).toBe(true);
    expect(focusState?.hasVisibleIndicator, `${screen} should render a visible focus indicator`).toBe(true);
}

async function signIn(page: Page, email: string | undefined, password: string | undefined) {
    if (!email || !password)
        throw new Error('The isolated browser setup did not provide Phase 12 role credentials.');
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(workspaces|t\/[^/]+\/dashboard)$/, { timeout: 20_000 });
}

function dispatchQuizOutbox(): {
    status: number | null;
    stdout: string;
    error: string | null;
    databaseExists: boolean;
} {
    const database = process.env.E2E_DATABASE ?? '';
    const framework = resolve(process.cwd(), 'storage/framework');
    const absoluteDatabase = resolve(database);
    const databaseExists = existsSync(absoluteDatabase);
    if (
        dirname(absoluteDatabase) !== framework ||
        !/^phase06-e2e-[0-9a-f-]{36}\.sqlite$/i.test(absoluteDatabase.split(/[\\/]/).at(-1) ?? '')
    ) {
        throw new Error(
            'Assessment outbox browser verification requires its unique isolated E2E SQLite file.',
        );
    }
    const env: NodeJS.ProcessEnv = {
        PATH: process.env.PATH ?? '',
        SystemRoot: process.env.SystemRoot ?? '',
        WINDIR: process.env.WINDIR ?? '',
        TEMP: process.env.TEMP ?? '',
        TMP: process.env.TMP ?? '',
        APP_ENV: 'testing',
        APP_DEBUG: 'false',
        APP_URL: 'http://127.0.0.1:8017',
        E2E_TEST_MODE: 'true',
        E2E_DATABASE: absoluteDatabase,
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: absoluteDatabase,
        DB_URL: '',
        CACHE_STORE: 'file',
        QUEUE_CONNECTION: 'database',
    };
    if (process.env.APP_KEY) env.APP_KEY = process.env.APP_KEY;
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['artisan', 'assessments:outbox:dispatch'], {
        cwd: process.cwd(),
        encoding: 'utf8',
        env,
        timeout: 30_000,
        windowsHide: true,
    });

    return {
        status: result.status,
        stdout: result.stdout ?? '',
        error: result.error?.name ?? null,
        databaseExists,
    };
}

test('instructor authors all supported question formats and the editor remains accessible across six themes', async ({
    browser,
}) => {
    // This journey creates and reviews fourteen formats, saves fourteen learner
    // responses, scans one real file response when ClamAV is enabled, and runs
    // six axe/theme passes. Keep a finite bound while allowing the verified
    // scanner's own 120-second hard timeout plus the full workflow.
    test.setTimeout(600_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    if (!tenant) throw new Error('The isolated browser setup did not provide an assessment tenant.');

    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const instructor = await context.newPage();
    await signIn(
        instructor,
        process.env.E2E_REGISTRATION_INSTRUCTOR_EMAIL,
        process.env.E2E_REGISTRATION_INSTRUCTOR_PASSWORD,
    );
    await instructor.goto(`/t/${tenant}/assessments`);

    const bankCard = instructor.locator('article').filter({ hasText: 'E2E Assessment Bank' });
    await expect(bankCard).toBeVisible();
    await bankCard.getByRole('button', { name: 'Add question' }).click();
    const formats = [
        'single_choice',
        'multiple_choice',
        'true_false',
        'matching',
        'ordering',
        'fill_blank',
        'short_text',
        'long_response',
        'numeric',
        'multiple_blanks',
        'case_passage',
        'image_based',
        'file_response',
        'instructor_review',
    ];
    const promptsByFormat = new Map<string, string>();
    for (const [index, format] of formats.entries()) {
        await bankCard.getByLabel('Question type').selectOption(format);
        const prompt = `Browser authored ${format} ${Date.now()}-${index}`;
        promptsByFormat.set(format, prompt);
        await bankCard.getByLabel('Question prompt').fill(prompt);
        if (format === 'case_passage')
            await bankCard.getByLabel('Case or passage').fill('A browser-tested reading passage.');
        if (format === 'numeric') await bankCard.getByLabel('Expected number').fill('42');
        if (format === 'short_text') await bankCard.getByLabel('Accepted short answers').fill('forty two');
        if (format === 'fill_blank')
            await bankCard.getByLabel('Accepted answers for answer').fill('forty two');
        if (format === 'multiple_blanks') {
            await bankCard.getByLabel('Accepted answers for first').fill('four');
            await bankCard.getByLabel('Accepted answers for second').fill('two');
        }
        const saveResponsePromise = instructor.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                /\/assessments\/banks\/\d+\/questions$/.test(new URL(response.url()).pathname),
        );
        await bankCard.getByRole('button', { name: 'Save draft question' }).click();
        const saveResponse = await saveResponsePromise;
        expect(saveResponse.status(), `${format} authoring response`).toBeLessThan(400);
        await expect(bankCard.getByText(prompt, { exact: true })).toBeVisible();
    }
    await auditSixAssessmentThemes(instructor, 'question editor');

    const poolCandidatePrompt = promptsByFormat.get('matching')!;
    const reviewPrompts = [...promptsByFormat.values()];
    for (const prompt of reviewPrompts) {
        const draftQuestionRow = bankCard.locator('div').filter({ hasText: prompt }).last();
        const questionReviewResponse = instructor.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                new URL(response.url()).pathname.endsWith('/submit-review'),
        );
        await draftQuestionRow.getByRole('button', { name: 'Request review' }).click();
        expect((await questionReviewResponse).status()).toBeLessThan(400);
    }

    const reviewerContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const reviewer = await reviewerContext.newPage();
    await signIn(
        reviewer,
        process.env.E2E_REGISTRATION_OWNER_EMAIL,
        process.env.E2E_REGISTRATION_OWNER_PASSWORD,
    );
    await reviewer.goto(`/t/${tenant}/assessments`);
    const questionReviewQueue = reviewer.locator('section').filter({
        has: reviewer.getByRole('heading', { name: 'Question review queue' }),
    });
    for (const prompt of reviewPrompts) {
        const pendingQuestion = questionReviewQueue.locator('article').filter({ hasText: prompt });
        const questionDecision = reviewer.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                new URL(response.url()).pathname.endsWith('/review'),
        );
        await pendingQuestion.getByRole('button', { name: 'Approve' }).click();
        expect((await questionDecision).status()).toBeLessThan(400);
    }

    await instructor.goto(`/t/${tenant}/assessments`);
    const createQuizForm = instructor.locator('form').filter({
        has: instructor.getByRole('button', { name: 'Save quiz draft' }),
    });
    const poolQuizTitle = `Browser all-format assessment ${Date.now()}`;
    await createQuizForm.getByLabel('Quiz title').fill(poolQuizTitle);
    const enrolledSectionId = process.env.E2E_ASSIGNMENT_SECTION_ID;
    if (!enrolledSectionId)
        throw new Error('The isolated browser setup did not provide the enrolled assignment section.');
    await createQuizForm.getByLabel('Course section').selectOption(enrolledSectionId);
    await createQuizForm
        .getByLabel('Instructions')
        .fill('Complete each supported response format in this reviewed assessment.');
    for (const prompt of reviewPrompts.filter((candidate) => candidate !== poolCandidatePrompt)) {
        await createQuizForm.locator('label').filter({ hasText: prompt }).getByRole('checkbox').check();
    }
    const localDateTimeInput = (date: Date) =>
        new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
    await createQuizForm
        .getByLabel('Opens at (institution time)')
        .fill(localDateTimeInput(new Date(Date.now() + 2 * 60 * 60 * 1000)));
    await createQuizForm
        .getByLabel('Closes at (institution time)')
        .fill(localDateTimeInput(new Date(Date.now() + 60 * 60 * 1000)));
    const invalidQuizResponse = instructor.waitForResponse(
        (response) =>
            response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/quizzes'),
    );
    await createQuizForm.getByRole('button', { name: 'Save quiz draft' }).click();
    expect((await invalidQuizResponse).status()).toBeGreaterThanOrEqual(300);
    await expect(instructor.getByRole('alert')).toContainText(
        'The close time must be after the opening time.',
    );
    const validationAccessibility = await new AxeBuilder({ page: instructor })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    expect(validationAccessibility.violations, 'Quiz schedule validation error accessibility').toEqual([]);
    await auditSixAssessmentThemes(instructor, 'quiz schedule validation error');
    await createQuizForm.getByLabel('Quiz title').fill(poolQuizTitle);
    await createQuizForm.getByLabel('Course section').selectOption(enrolledSectionId);
    await createQuizForm
        .getByLabel('Instructions')
        .fill('Complete each supported response format in this reviewed assessment.');
    for (const prompt of reviewPrompts.filter((candidate) => candidate !== poolCandidatePrompt)) {
        await createQuizForm.locator('label').filter({ hasText: prompt }).getByRole('checkbox').check();
    }
    const quizSaveResponse = instructor.waitForResponse(
        (response) =>
            response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/quizzes'),
    );
    await createQuizForm.getByRole('button', { name: 'Save quiz draft' }).click();
    expect((await quizSaveResponse).status()).toBeLessThan(400);
    await expect(instructor.getByRole('heading', { name: poolQuizTitle })).toBeVisible();
    await expect(instructor.getByRole('heading', { name: 'Random question pool' })).toBeVisible();
    await auditSixAssessmentThemes(instructor, 'quiz builder and pool configuration');

    await instructor.goto(`/t/${tenant}/assessments`);
    const poolSection = instructor.locator('section').filter({
        has: instructor.getByRole('heading', { name: 'Random question pool' }),
    });
    await poolSection.getByLabel('Draft quiz').selectOption({ label: poolQuizTitle });
    await poolSection.getByLabel('Pool name').fill('browser-randomized-pool');
    await poolSection.getByLabel('Questions to draw').fill('1');
    await poolSection.getByLabel('Points per selected question').fill('1.00');
    await poolSection.getByRole('checkbox', { name: poolCandidatePrompt }).check();
    const poolSaveResponse = instructor.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            /\/quizzes\/\d+\/pools$/.test(new URL(response.url()).pathname),
    );
    const savePoolButton = poolSection.getByRole('button', { name: 'Save randomized pool' });
    await expect(savePoolButton).toBeEnabled();
    await savePoolButton.scrollIntoViewIfNeeded();
    await savePoolButton.focus();
    await savePoolButton.press('Enter');
    expect((await poolSaveResponse).status()).toBeLessThan(400);
    await expect(
        instructor.getByText('Randomized question pool saved to the draft.', { exact: true }),
    ).toBeVisible();

    await instructor.goto(`/t/${tenant}/assessments`);
    const instructorQuizCard = instructor.locator('article').filter({ hasText: poolQuizTitle });
    const quizReviewResponse = instructor.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname.endsWith('/submit-review'),
    );
    await instructorQuizCard.getByRole('button', { name: 'Request review' }).click();
    expect((await quizReviewResponse).status()).toBeLessThan(400);

    await reviewer.goto(`/t/${tenant}/assessments`);
    const reviewerQuizCard = reviewer.locator('article').filter({ hasText: poolQuizTitle });
    await reviewerQuizCard.getByRole('button', { name: 'Approve' }).click();
    await expect(reviewerQuizCard.getByRole('button', { name: 'Publish' })).toBeVisible();
    await reviewerQuizCard.getByRole('button', { name: 'Publish' }).click();
    await expect(reviewerQuizCard).toContainText('published');

    const studentContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const student = await studentContext.newPage();
    await signIn(
        student,
        process.env.E2E_REGISTRATION_STUDENT_EMAIL,
        process.env.E2E_REGISTRATION_STUDENT_PASSWORD,
    );
    await student.goto(`/t/${tenant}/assessments`);
    const studentQuizCard = student.locator('article').filter({ hasText: poolQuizTitle });
    await expect(studentQuizCard).toBeVisible();
    await studentQuizCard.getByRole('button', { name: 'Start assessment' }).click();
    await expect(student).toHaveURL(/\/assessments\/attempts\/\d+$/);
    await expect(student.getByRole('heading', { name: poolQuizTitle })).toBeVisible();
    await auditSixAssessmentThemes(student, 'all-format learner quiz player');

    const cleanFixture = resolve(process.cwd(), 'storage/framework/phase12-clamav-clean-probe.pdf');
    for (const [index, format] of formats.entries()) {
        const prompt = promptsByFormat.get(format)!;
        const question = student.locator('main > section').filter({ hasText: prompt });
        await expect(question, `${format} learner question should render`).toBeVisible();
        // Autosave starts as soon as the control changes. Arm the response
        // observer before interacting so a fast local request cannot be missed.
        const saveResponse =
            format === 'file_response'
                ? null
                : student.waitForResponse(
                      (response) =>
                          response.request().method() === 'PATCH' &&
                          /\/attempts\/\d+\/questions\/\d+$/.test(new URL(response.url()).pathname),
                  );
        if (format === 'single_choice' || format === 'true_false') {
            await question.getByRole('radio').first().check();
        } else if (format === 'multiple_choice' || format === 'image_based') {
            await question.getByRole('checkbox').first().check();
        } else if (format === 'matching') {
            for (const select of await question.getByRole('combobox').all()) {
                await select.selectOption({ index: 1 });
            }
        } else if (format === 'ordering') {
            await question.getByRole('button', { name: 'Move item 1 down' }).click();
        } else if (format === 'fill_blank' || format === 'multiple_blanks') {
            const inputs = await question.getByRole('textbox').all();
            for (const [inputIndex, input] of inputs.entries()) {
                await input.fill(format === 'fill_blank' ? 'forty two' : inputIndex === 0 ? 'four' : 'two');
            }
        } else if (format === 'numeric') {
            await question.getByRole('textbox').fill('42');
        } else if (format === 'file_response') {
            await expect(question.locator('input[type="file"]')).toBeVisible();
            if (process.env.E2E_CLAMAV_ENABLED === 'true') {
                if (!existsSync(cleanFixture))
                    throw new Error('The verified clean ClamAV fixture is missing.');
                const uploadResponse = student.waitForResponse(
                    (response) =>
                        response.request().method() === 'POST' &&
                        /\/questions\/\d+\/file$/.test(new URL(response.url()).pathname),
                    { timeout: 150_000 },
                );
                await question.locator('input[type="file"]').setInputFiles(cleanFixture);
                expect((await uploadResponse).status()).toBeLessThan(400);
                await expect(question.getByText('Saved response:', { exact: false })).toBeVisible();
                continue;
            }
            // File responses require a clean, scanner-approved artifact. The default
            // browser environment intentionally has no scanner, so it cannot submit
            // this answer and must not wait for the PATCH endpoint below.
            continue;
        } else {
            await question.getByRole('textbox').fill(`Browser response for ${format} question ${index + 1}.`);
        }

        expect((await saveResponse!).status(), `${format} response save`).toBeLessThan(400);
        await expect(question.getByRole('status').filter({ hasText: 'Saved on server' })).toBeVisible();
    }
    await student.reload();
    for (const prompt of reviewPrompts) {
        await expect(student.locator('main').last()).toContainText(prompt);
    }
    await expect(
        student
            .getByRole('navigation', { name: 'Question progress' })
            .getByRole('link')
            .filter({ hasText: 'Saved' }),
    ).toHaveCount(process.env.E2E_CLAMAV_ENABLED === 'true' ? formats.length : formats.length - 1);

    await context.close();
    await reviewerContext.close();
    await studentContext.close();
});

test('learner submits a quiz, instructor grades and releases it, learner sees only the released result', async ({
    browser,
}, testInfo) => {
    test.setTimeout(180_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    const quizId = process.env.E2E_ASSESSMENT_QUIZ_ID;
    if (!tenant || !quizId) throw new Error('The isolated browser fixture did not provide a Phase 12 quiz.');

    const learnerContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const learner = await learnerContext.newPage();
    await signIn(
        learner,
        process.env.E2E_REGISTRATION_STUDENT_EMAIL,
        process.env.E2E_REGISTRATION_STUDENT_PASSWORD,
    );
    const base = `/t/${tenant}/assessments`;
    const indexResponse = await learner.goto(base);
    expect(indexResponse?.status()).toBe(200);
    await expect(learner.getByRole('heading', { name: 'Assessments', exact: true })).toBeVisible();
    const assessmentCard = learner
        .locator('article')
        .filter({ hasText: 'E2E instructor-reviewed assessment' });
    await assessmentCard.getByRole('button', { name: 'Start assessment' }).click();
    await expect(learner).toHaveURL(new RegExp(`${base}/attempts/\\d+$`));
    const attemptPath = new URL(learner.url()).pathname;
    await expect(learner.getByRole('heading', { name: 'E2E instructor-reviewed assessment' })).toBeVisible();
    await expect(learner.getByRole('timer')).toBeVisible();
    const themes = [
        'executive-navy',
        'academic-emerald',
        'modern-indigo',
        'warm-ivory',
        'graphite-dark',
        'midnight-violet',
    ];
    const themeTextColors: Record<string, string> = {
        'executive-navy': '#14243a',
        'academic-emerald': '#15372d',
        'modern-indigo': '#202044',
        'warm-ivory': '#352a24',
        'graphite-dark': '#e8edf5',
        'midnight-violet': '#f0edfb',
    };
    const themeSurfaceColors: Record<string, string> = {
        'executive-navy': '#ffffff',
        'academic-emerald': '#ffffff',
        'modern-indigo': '#ffffff',
        'warm-ivory': '#fffdf8',
        'graphite-dark': '#1b212b',
        'midnight-violet': '#1d1a2b',
    };
    const playerTextTokens = new Set<string>();
    await learner.emulateMedia({ reducedMotion: 'reduce' });
    for (const theme of themes) {
        await learner.locator('#theme-choice').selectOption(theme);
        await expect(learner.locator('[data-theme]').first()).toHaveAttribute('data-theme', theme);
        await learner.evaluate(async () => {
            const runningTransitions = document
                .getAnimations()
                .filter((animation) => animation.playState === 'running');
            await Promise.all(
                runningTransitions.map((animation) => animation.finished.catch(() => undefined)),
            );
        });
        const contrastSample = learner.locator('.justify-end button').first();
        await expect
            .poll(() => contrastSample.evaluate((element) => getComputedStyle(element).color))
            .toBe(cssColor(themeTextColors[theme]));
        await expect
            .poll(() => contrastSample.evaluate((element) => getComputedStyle(element).backgroundColor))
            .toBe(cssColor(themeSurfaceColors[theme]));
        playerTextTokens.add(
            await learner
                .locator('[data-theme]')
                .first()
                .evaluate((element) =>
                    getComputedStyle(element).getPropertyValue('--color-text-primary').trim(),
                ),
        );
        const themeAudit = await new AxeBuilder({ page: learner })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();
        expect(themeAudit.violations, `Accessibility violations with ${theme} theme`).toEqual([]);
    }
    expect(playerTextTokens.size, 'The quiz player should render six distinct theme token sets').toBe(6);
    expect(
        await learner.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
        'The quiz player should reflow at the mobile viewport without horizontal overflow',
    ).toBe(true);
    await learner.reload();
    await expect(learner.locator('#theme-choice')).toHaveValue('midnight-violet');
    await learner.keyboard.press('Tab');
    expect(
        await learner.evaluate(() => document.activeElement !== document.body),
        'The quiz player should expose controls to keyboard navigation',
    ).toBe(true);
    let releaseFirstSave!: () => void;
    let resolveFirstSave!: () => void;
    let resolveSecondSave!: () => void;
    const firstSaveSeen = new Promise<void>((resolve) => (resolveFirstSave = resolve));
    const firstSaveRelease = new Promise<void>((resolve) => (releaseFirstSave = resolve));
    const secondSaveSeen = new Promise<void>((resolve) => (resolveSecondSave = resolve));
    let heldFirstSave = false;
    await learner.route('**/assessments/attempts/*/questions/*', async (route) => {
        if (route.request().method() === 'PATCH') {
            if (!heldFirstSave) {
                heldFirstSave = true;
                resolveFirstSave();
                await firstSaveRelease;
            } else {
                resolveSecondSave();
            }
        }
        await route.continue();
    });
    const responseControl = learner.getByLabel('Your response');
    await responseControl.fill('An earlier draft answer.');
    await firstSaveSeen;
    await responseControl.fill('This response explains the core reasoning.');
    releaseFirstSave();
    await secondSaveSeen;
    await expect(learner.getByRole('status').filter({ hasText: 'Saved on server' })).toBeVisible();
    await learner.reload();
    await expect(learner.getByLabel('Your response')).toHaveValue(
        'This response explains the core reasoning.',
    );
    let submitRequests = 0;
    learner.on('request', (request) => {
        if (
            request.method() === 'POST' &&
            new URL(request.url()).pathname.endsWith(`/attempts/${attemptPath.split('/').at(-1)}/submit`)
        ) {
            submitRequests++;
        }
    });
    const dismissedSubmitConfirmation = new Promise<void>((resolve, reject) => {
        learner.once('dialog', async (dialog) => {
            try {
                expect(dialog.type()).toBe('confirm');
                expect(dialog.message()).toContain('Submit this assessment?');
                await dialog.dismiss();
                resolve();
            } catch (error) {
                reject(error);
            }
        });
    });
    await learner.getByRole('button', { name: 'Submit assessment' }).click();
    await dismissedSubmitConfirmation;
    expect(submitRequests, 'Dismissing the native confirmation must not submit the attempt').toBe(0);
    await expect(learner.getByLabel('Your response')).toHaveValue(
        'This response explains the core reasoning.',
    );
    learner.once('dialog', (dialog) => dialog.accept());
    const submittedResponse = learner.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname.endsWith(`/attempts/${attemptPath.split('/').at(-1)}/submit`),
    );
    await learner.getByRole('button', { name: 'Submit assessment' }).click();
    expect((await submittedResponse).status()).toBeLessThan(400);
    await expect(learner.getByLabel('Submission receipt')).toContainText('needs manual review');
    await expect(learner.getByText(/score and feedback remain hidden/i)).toBeVisible();
    await learner.screenshot({ path: testInfo.outputPath('phase12-student-submission.png'), fullPage: true });

    const instructorContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const instructor = await instructorContext.newPage();
    await signIn(
        instructor,
        process.env.E2E_REGISTRATION_INSTRUCTOR_EMAIL,
        process.env.E2E_REGISTRATION_INSTRUCTOR_PASSWORD,
    );
    const gradingResponse = await instructor.goto(`${base}/grading`);
    expect(gradingResponse?.status()).toBe(200);
    const responseCard = instructor
        .locator('article')
        .filter({ hasText: 'This response explains the core reasoning.' });
    await expect(responseCard).toBeVisible();
    await auditSixAssessmentThemes(instructor, 'manual grading queue');
    await instructor.goto(`${base}/grading`);
    const refreshedResponseCard = instructor
        .locator('article')
        .filter({ hasText: 'This response explains the core reasoning.' });
    await refreshedResponseCard.getByLabel(/Points \(maximum/).fill('1.00');
    await refreshedResponseCard
        .getByLabel('Feedback for learner')
        .fill('Your reasoning is clear and supported.');
    await refreshedResponseCard.getByRole('button', { name: 'Save grade revision' }).click();
    await expect(
        instructor.locator('article').filter({ hasText: 'This response explains the core reasoning.' }),
    ).toHaveCount(0);

    await instructor.goto(base);
    const quizRow = instructor.locator('article').filter({ hasText: 'E2E instructor-reviewed assessment' });
    const releaseResponsePromise = instructor.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname.endsWith(`/quizzes/${quizId}/release-results`),
    );
    await quizRow.getByRole('button', { name: 'Release results' }).click();
    const releaseResponse = await releaseResponsePromise;
    expect([200, 302, 303]).toContain(releaseResponse.status());

    await learner.goto(new URL(learner.url()).pathname);
    await expect(learner.getByRole('heading', { name: 'Released result' })).toBeVisible();
    await expect(learner.getByText('Your reasoning is clear and supported.')).toBeVisible();

    await learner
        .getByLabel('Request a regrade')
        .fill('Please review the rubric application for this response.');
    const regradeResponsePromise = learner.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname.endsWith(`/attempts/${attemptPath.split('/').at(-1)}/regrade`),
    );
    await learner.getByRole('button', { name: 'Submit regrade request' }).click();
    expect([200, 302, 303]).toContain((await regradeResponsePromise).status());
    await expect(learner.getByRole('status').filter({ hasText: 'Regrade request: open' })).toBeVisible();

    const reviewerContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const reviewer = await reviewerContext.newPage();
    await signIn(
        reviewer,
        process.env.E2E_REGISTRATION_OWNER_EMAIL,
        process.env.E2E_REGISTRATION_OWNER_PASSWORD,
    );
    const regradesResponse = await reviewer.goto(`${base}/regrades`);
    expect(regradesResponse?.status()).toBe(200);
    await auditSixAssessmentThemes(reviewer, 'independent regrade review queue');
    await reviewer.goto(`${base}/regrades`);
    const regradeCard = reviewer.locator('article').filter({ hasText: 'E2E instructor-reviewed assessment' });
    await expect(regradeCard).toContainText('Please review the rubric application for this response.');
    await regradeCard.getByRole('button', { name: 'Approve regrade' }).click();
    await expect(reviewer.getByText('There are no open regrade requests.')).toBeVisible();

    await instructor.goto(`${base}/grading`);
    const revisedResponseCard = instructor
        .locator('article')
        .filter({ hasText: 'This response explains the core reasoning.' });
    await expect(revisedResponseCard).toBeVisible();
    await revisedResponseCard
        .getByLabel('Feedback for learner')
        .fill('The regrade confirms the original rubric score.');
    await revisedResponseCard.getByRole('button', { name: 'Save grade revision' }).click();
    await expect(
        instructor.locator('article').filter({ hasText: 'This response explains the core reasoning.' }),
    ).toHaveCount(0);
    await instructor.goto(base);
    const revisedReleaseCard = instructor
        .locator('article')
        .filter({ hasText: 'E2E instructor-reviewed assessment' });
    const revisedReleasePromise = instructor.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname.endsWith(`/quizzes/${quizId}/release-results`),
    );
    await revisedReleaseCard.getByRole('button', { name: 'Release results' }).click();
    expect([200, 302, 303]).toContain((await revisedReleasePromise).status());
    const outbox = dispatchQuizOutbox();
    expect(
        outbox.status,
        `Assessment outbox dispatch failed: ${outbox.stdout.trim() || outbox.error || 'no process output'}; isolated database present=${outbox.databaseExists}`,
    ).toBe(0);
    expect(outbox.stdout).toMatch(/delivered=[1-9][0-9]*/);

    await learner.goto(attemptPath);
    await expect(learner.getByRole('status').filter({ hasText: 'Regrade request: approved' })).toBeVisible();
    await expect(learner.getByText('The regrade confirms the original rubric score.')).toBeVisible();
    await learner.goto(`${base}/notifications`);
    await expect(learner.getByRole('heading', { name: 'Assessment notifications' })).toBeVisible();
    await expect(learner.getByText('Quiz result released').first()).toBeVisible();
    await expect(learner.getByText('Quiz marking complete').first()).toBeVisible();
    await expect(learner.getByText('Regrade request decided').first()).toBeVisible();

    const axe = await new AxeBuilder({ page: learner })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    expect(axe.violations).toEqual([]);
    const width = await learner.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        content: document.documentElement.scrollWidth,
    }));
    expect(width.content).toBeLessThanOrEqual(width.viewport);
    await learner.goto(attemptPath);
    const deniedGrading = await learner.goto(`${base}/grading`);
    expect(deniedGrading?.status()).toBe(403);
    await instructor.goto(base);
    await instructor.screenshot({
        path: testInfo.outputPath('phase12-instructor-workspace.png'),
        fullPage: true,
    });
    await Promise.all([learnerContext.close(), instructorContext.close(), reviewerContext.close()]);
});

test('institution owner opens the scoped assessment analytics workspace and filters a quiz', async ({
    browser,
}, testInfo) => {
    test.setTimeout(60_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    const quizId = process.env.E2E_ASSESSMENT_QUIZ_ID;
    if (!tenant || !quizId)
        throw new Error('The isolated browser setup did not provide Phase 12 analytics fixtures.');

    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const administrator = await context.newPage();
    await signIn(
        administrator,
        process.env.E2E_REGISTRATION_OWNER_EMAIL,
        process.env.E2E_REGISTRATION_OWNER_PASSWORD,
    );
    const response = await administrator.goto(
        `/t/${tenant}/assessments/analytics?quiz_id=${encodeURIComponent(quizId)}`,
    );
    expect(response?.status()).toBe(200);
    await expect(administrator.getByRole('heading', { name: 'Assessment analytics' })).toBeVisible();
    await auditSixAssessmentThemes(administrator, 'institution assessment oversight');
    await administrator.goto(`/t/${tenant}/assessments/analytics?quiz_id=${encodeURIComponent(quizId)}`);
    await expect(administrator.getByLabel('Quiz')).toHaveValue(quizId);
    await expect(administrator.getByLabel('Quiz').locator('option:checked')).toHaveText(
        'E2E instructor-reviewed assessment',
    );
    const axe = await new AxeBuilder({ page: administrator })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    expect(axe.violations).toEqual([]);
    await administrator.screenshot({
        path: testInfo.outputPath('phase12-institution-assessment-analytics.png'),
        fullPage: true,
    });
    await context.close();
});
