import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const screenshotDirectory = resolve(process.cwd(), 'docs/phase-reports/frontend-audit/screenshots');

async function signIn(page: Page, email: string | undefined, password: string | undefined) {
    if (!email || !password)
        throw new Error('The isolated browser setup did not provide Phase 11 assignment role credentials.');
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(workspaces|t\/[^/]+\/dashboard)$/);
}

test('instructor authors a rubric assignment and learner submits, responds and receives independently released feedback', async ({
    browser,
}) => {
    test.setTimeout(150_000);
    const tenant = process.env.E2E_REGISTRATION_TENANT_SLUG;
    const offeringId = process.env.E2E_ASSIGNMENT_OFFERING_ID;
    if (!tenant || !offeringId)
        throw new Error('The isolated E2E setup did not provision the Phase 11 assignment workspace.');

    const instructorContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const instructor = await instructorContext.newPage();
    await signIn(
        instructor,
        process.env.E2E_REGISTRATION_INSTRUCTOR_EMAIL,
        process.env.E2E_REGISTRATION_INSTRUCTOR_PASSWORD,
    );
    await instructor.goto(`/t/${tenant}/assignments/rubrics`);
    await instructor.getByLabel('Rubric name').fill('Browser Writing Rubric');
    await instructor.getByLabel('Total points').fill('100');
    const firstCriterion = instructor.getByRole('group', { name: 'Criterion 1' });
    await firstCriterion.getByLabel('Title').fill('Evidence');
    await firstCriterion.getByLabel('Description').fill('Select relevant sources.');
    await firstCriterion.getByLabel('Points').fill('60');
    await instructor.getByRole('button', { name: 'Add criterion' }).click();
    const secondCriterion = instructor.getByRole('group', { name: 'Criterion 2' });
    await secondCriterion.getByLabel('Title').fill('Reasoning');
    await secondCriterion.getByLabel('Description').fill('Explain the claim clearly.');
    await secondCriterion.getByLabel('Points').fill('40');
    await instructor.getByRole('button', { name: 'Create draft rubric' }).click();
    const draftRubric = instructor.locator('article').filter({ hasText: 'Browser Writing Rubric' });
    await expect(draftRubric).toContainText('Version 1');
    await draftRubric.getByRole('button', { name: 'Publish version' }).click();
    await expect(draftRubric).toContainText('published');

    const suffix = process.env.E2E_RUN_ID ?? 'run';
    const assignmentTitle = `Browser Essay ${suffix}`;
    await instructor.goto(`/t/${tenant}/assignments/create`);
    await instructor.getByLabel('Course offering').selectOption(offeringId);
    await instructor.getByLabel('Section ASGN').check();
    await instructor.getByLabel('Title', { exact: true }).fill(assignmentTitle);
    await instructor.getByLabel('Instructions').fill('Write a short supported argument.');
    await instructor.getByLabel('Maximum attempts').fill('2');
    await instructor
        .getByLabel('Published rubric (optional)')
        .selectOption({ label: 'Browser Writing Rubric · v1 (100 points)' });
    await expect(instructor.getByLabel('Maximum points')).toHaveValue('100');
    await instructor.getByLabel('Require independent review before publication').check();
    await instructor.getByRole('button', { name: 'Save draft' }).click();
    const assignmentRow = instructor.getByRole('row').filter({ hasText: assignmentTitle });
    await expect(assignmentRow).toBeVisible();
    mkdirSync(screenshotDirectory, { recursive: true });
    await instructor.screenshot({
        path: resolve(screenshotDirectory, 'assignment-creation.png'),
        fullPage: true,
    });
    await assignmentRow.getByRole('button', { name: 'Submit for review' }).click();

    const reviewerContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const reviewer = await reviewerContext.newPage();
    await signIn(
        reviewer,
        process.env.E2E_SCHEDULING_REVIEWER_EMAIL,
        process.env.E2E_SCHEDULING_REVIEWER_PASSWORD,
    );
    await expect(reviewer.getByText('Phase 09 E2E Scheduling Reviewer', { exact: true })).toBeVisible();
    await reviewer.goto(`/t/${tenant}/assignments`);
    const reviewRow = reviewer.getByRole('row').filter({ hasText: assignmentTitle });
    await expect(reviewRow).toContainText('under review');
    await reviewRow.getByRole('button', { name: 'Approve' }).click();
    await instructor.goto(`/t/${tenant}/assignments`);
    await instructor
        .getByRole('row')
        .filter({ hasText: assignmentTitle })
        .getByRole('button', { name: 'Publish' })
        .click();

    const studentContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const student = await studentContext.newPage();
    await signIn(
        student,
        process.env.E2E_REGISTRATION_STUDENT_EMAIL,
        process.env.E2E_REGISTRATION_STUDENT_PASSWORD,
    );
    await expect(student.getByText('Phase 08 E2E Student', { exact: true })).toBeVisible();
    await student.goto(`/t/${tenant}/my-assignments`);
    const studentAssignment = student.locator('article').filter({ hasText: assignmentTitle });
    await expect(studentAssignment).toBeVisible();
    await studentAssignment.getByRole('link', { name: 'Open assignment' }).click();
    await student
        .getByLabel('Written response')
        .fill('A concise response with supporting reasoning and evidence.');
    await student.getByRole('button', { name: 'Save draft response' }).click();
    await student.reload();
    await expect(student.getByRole('button', { name: 'Submit work' })).toBeVisible();
    await student.getByRole('button', { name: 'Submit work' }).click();
    await expect(student.getByRole('heading', { name: 'Submission received', exact: true })).toBeVisible();
    await student.screenshot({
        path: resolve(screenshotDirectory, 'assignment-submission.png'),
        fullPage: true,
    });

    await instructor.goto(`/t/${tenant}/assignments`);
    const publishedRow = instructor.getByRole('row').filter({ hasText: assignmentTitle });
    await publishedRow.getByRole('link', { name: 'Review work' }).click();
    await instructor.getByLabel('Evidence points').fill('55');
    await instructor.getByLabel('Reasoning points').fill('35');
    await instructor.getByLabel('Learner feedback').fill('Clear argument with relevant sources.');
    await instructor.getByRole('button', { name: 'Save grade privately' }).click();
    await expect(instructor.getByRole('status')).toContainText('Grade saved as an unreleased draft.');
    await instructor.screenshot({
        path: resolve(screenshotDirectory, 'assignment-grading.png'),
        fullPage: true,
    });
    await instructor
        .getByLabel('Evidence-based summary')
        .fill('Please explain the source attribution used in the submitted essay.');
    await instructor.getByRole('button', { name: 'Open review case' }).click();
    await expect(instructor.getByRole('status')).toContainText(
        'Integrity concern recorded for human review.',
    );
    await expect(instructor.getByText(/Status: awaiting learner/)).toBeVisible();

    await student.goto(`/t/${tenant}/my-assignments`);
    await student
        .locator('article')
        .filter({ hasText: assignmentTitle })
        .getByRole('link', { name: 'Open assignment' })
        .click();
    await student
        .getByRole('textbox', { name: 'Your response' })
        .fill('I used the listed source for its definition and wrote the rest independently.');
    await student.getByRole('button', { name: 'Submit response' }).click();
    await expect(student.getByText('under review')).toBeVisible();

    await reviewer.goto(`/t/${tenant}/assignments`);
    await reviewer
        .getByRole('row')
        .filter({ hasText: assignmentTitle })
        .getByRole('link', { name: 'Review work' })
        .click();
    await expect(reviewer.getByRole('heading', { name: 'Submission inbox', exact: true })).toBeVisible();
    await expect(reviewer.getByText(/wrote the rest independently/)).toBeVisible();
    await reviewer
        .getByLabel('Reasoned review notes')
        .fill('The explanation is consistent with the learner response and the submitted work.');
    await reviewer.getByRole('button', { name: 'Record decision' }).click();
    await expect(reviewer.getByRole('status')).toContainText(
        'Human-reviewed integrity case decision recorded.',
    );
    await expect(reviewer.getByRole('button', { name: 'Release grade and feedback' })).toBeVisible();
    await reviewer.getByRole('button', { name: 'Release grade and feedback' }).click();

    await student.goto(`/t/${tenant}/my-assignments`);
    await expect(student.locator('article').filter({ hasText: assignmentTitle })).toContainText(
        '90 points released',
    );
    const axe = await new AxeBuilder({ page: student })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    expect(axe.violations).toEqual([]);
    const dimensions = await student.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        content: document.documentElement.scrollWidth,
    }));
    expect(dimensions.content).toBeLessThanOrEqual(dimensions.viewport);

    await reviewerContext.close();
    await studentContext.close();
    await instructorContext.close();
});
