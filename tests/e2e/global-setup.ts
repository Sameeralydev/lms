import { mkdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';

export default function globalSetup(): void {
    mkdirSync(resolve(process.cwd(), 'storage/framework/e2e-sessions'), { recursive: true });

    const php = process.env.PHP_BINARY || 'php';
    const e2eDatabase = process.env.E2E_DATABASE;
    if (
        !e2eDatabase ||
        !/^phase06-e2e-[0-9a-f-]{36}\.sqlite$/i.test(e2eDatabase.split(/[\\/]/).at(-1) ?? '')
    ) {
        throw new Error('Playwright requires a unique guarded Phase 06 SQLite test database path.');
    }
    const childEnvironment: NodeJS.ProcessEnv = {
        PATH: process.env.PATH ?? '',
        SystemRoot: process.env.SystemRoot ?? '',
        WINDIR: process.env.WINDIR ?? '',
        TEMP: process.env.TEMP ?? '',
        TMP: process.env.TMP ?? '',
        APP_ENV: 'testing',
        APP_DEBUG: 'false',
        APP_URL: process.env.APP_URL || 'http://127.0.0.1:8017',
        E2E_TEST_MODE: 'true',
        E2E_RUN_ID: process.env.E2E_RUN_ID ?? '',
        E2E_DATABASE: e2eDatabase,
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: e2eDatabase,
        DB_URL: '',
    };
    if (process.env.APP_KEY) childEnvironment.APP_KEY = process.env.APP_KEY;

    const result = spawnSync(php, ['tests/e2e/prepare-database.php'], {
        encoding: 'utf8',
        env: childEnvironment,
    });

    if (result.status !== 0) {
        throw new Error(
            `Playwright database preflight failed: ${result.stderr.trim() || 'setup process failed'}`,
        );
    }

    const payload = result.stdout.trim().split(/\r?\n/).at(-1);
    if (!payload) {
        throw new Error('Playwright database setup did not return test fixture data.');
    }

    const fixtures = JSON.parse(payload) as {
        adminEmail: string;
        adminPassword: string;
        applicantEmail: string;
        applicantPassword: string;
        guardianEmail: string;
        guardianPassword: string;
        tenantSlug: string;
        runId: string;
        registrationTenantSlug: string;
        registrationOwnerEmail: string;
        registrationOwnerPassword: string;
        campusAdminEmail: string;
        campusAdminPassword: string;
        admissionsOfficerEmail: string;
        admissionsOfficerPassword: string;
        registrarEmail: string;
        registrarPassword: string;
        schedulingReviewerEmail: string;
        schedulingReviewerPassword: string;
        examinationControllerEmail: string;
        examinationControllerPassword: string;
        examinationInvigilatorEmail: string;
        examinationInvigilatorPassword: string;
        registrationInstructorEmail: string;
        registrationInstructorPassword: string;
        unassignedInstructorEmail: string;
        unassignedInstructorPassword: string;
        registrationInstructorUserId: number;
        registrationWorkspaceId: number;
        registrationLessonId: number;
        registrationSectionId: number;
        phase14AcademicPeriodId: number;
        phase14SectionId: number;
        assignmentOfferingId: number;
        assignmentSectionId: number;
        assessmentQuizId: number;
        examQuizId: number;
        examSessionId: number;
        examPaperVersionId: number;
        examCandidateId: number;
        registrationStudentEmail: string;
        registrationStudentPassword: string;
        schedulingStudentEmail: string;
        schedulingStudentPassword: string;
        schedulingSectionId: number;
        waitlistStudentEmail: string;
        waitlistStudentPassword: string;
        attendanceOwnerEmail: string;
        attendanceOwnerPassword: string;
        attendanceInstructorEmail: string;
        attendanceInstructorPassword: string;
        attendanceStudentEmail: string;
        attendanceStudentPassword: string;
        attendanceDate: string;
    };

    process.env.E2E_ADMIN_EMAIL = fixtures.adminEmail;
    process.env.E2E_ADMIN_PASSWORD = fixtures.adminPassword;
    process.env.E2E_APPLICANT_EMAIL = fixtures.applicantEmail;
    process.env.E2E_APPLICANT_PASSWORD = fixtures.applicantPassword;
    process.env.E2E_GUARDIAN_EMAIL = fixtures.guardianEmail;
    process.env.E2E_GUARDIAN_PASSWORD = fixtures.guardianPassword;
    process.env.E2E_TENANT_SLUG = fixtures.tenantSlug;
    process.env.E2E_REGISTRATION_TENANT_SLUG = fixtures.registrationTenantSlug;
    process.env.E2E_REGISTRATION_OWNER_EMAIL = fixtures.registrationOwnerEmail;
    process.env.E2E_REGISTRATION_OWNER_PASSWORD = fixtures.registrationOwnerPassword;
    process.env.E2E_CAMPUS_ADMIN_EMAIL = fixtures.campusAdminEmail;
    process.env.E2E_CAMPUS_ADMIN_PASSWORD = fixtures.campusAdminPassword;
    process.env.E2E_ADMISSIONS_OFFICER_EMAIL = fixtures.admissionsOfficerEmail;
    process.env.E2E_ADMISSIONS_OFFICER_PASSWORD = fixtures.admissionsOfficerPassword;
    process.env.E2E_REGISTRAR_EMAIL = fixtures.registrarEmail;
    process.env.E2E_REGISTRAR_PASSWORD = fixtures.registrarPassword;
    process.env.E2E_SCHEDULING_REVIEWER_EMAIL = fixtures.schedulingReviewerEmail;
    process.env.E2E_SCHEDULING_REVIEWER_PASSWORD = fixtures.schedulingReviewerPassword;
    process.env.E2E_EXAMINATION_CONTROLLER_EMAIL = fixtures.examinationControllerEmail;
    process.env.E2E_EXAMINATION_CONTROLLER_PASSWORD = fixtures.examinationControllerPassword;
    process.env.E2E_EXAMINATION_INVIGILATOR_EMAIL = fixtures.examinationInvigilatorEmail;
    process.env.E2E_EXAMINATION_INVIGILATOR_PASSWORD = fixtures.examinationInvigilatorPassword;
    process.env.E2E_REGISTRATION_INSTRUCTOR_EMAIL = fixtures.registrationInstructorEmail;
    process.env.E2E_REGISTRATION_INSTRUCTOR_PASSWORD = fixtures.registrationInstructorPassword;
    process.env.E2E_UNASSIGNED_INSTRUCTOR_EMAIL = fixtures.unassignedInstructorEmail;
    process.env.E2E_UNASSIGNED_INSTRUCTOR_PASSWORD = fixtures.unassignedInstructorPassword;
    process.env.E2E_REGISTRATION_INSTRUCTOR_USER_ID = String(fixtures.registrationInstructorUserId);
    process.env.E2E_REGISTRATION_WORKSPACE_ID = String(fixtures.registrationWorkspaceId);
    process.env.E2E_REGISTRATION_LESSON_ID = String(fixtures.registrationLessonId);
    process.env.E2E_REGISTRATION_SECTION_ID = String(fixtures.registrationSectionId);
    process.env.E2E_PHASE14_ACADEMIC_PERIOD_ID = String(fixtures.phase14AcademicPeriodId);
    process.env.E2E_PHASE14_SECTION_ID = String(fixtures.phase14SectionId);
    process.env.E2E_ASSIGNMENT_OFFERING_ID = String(fixtures.assignmentOfferingId);
    process.env.E2E_ASSIGNMENT_SECTION_ID = String(fixtures.assignmentSectionId);
    process.env.E2E_ASSESSMENT_QUIZ_ID = String(fixtures.assessmentQuizId);
    process.env.E2E_EXAM_QUIZ_ID = String(fixtures.examQuizId);
    process.env.E2E_EXAM_SESSION_ID = String(fixtures.examSessionId);
    process.env.E2E_EXAM_PAPER_VERSION_ID = String(fixtures.examPaperVersionId);
    process.env.E2E_EXAM_CANDIDATE_ID = String(fixtures.examCandidateId);
    process.env.E2E_REGISTRATION_STUDENT_EMAIL = fixtures.registrationStudentEmail;
    process.env.E2E_REGISTRATION_STUDENT_PASSWORD = fixtures.registrationStudentPassword;
    process.env.E2E_SCHEDULING_STUDENT_EMAIL = fixtures.schedulingStudentEmail;
    process.env.E2E_SCHEDULING_STUDENT_PASSWORD = fixtures.schedulingStudentPassword;
    process.env.E2E_SCHEDULING_SECTION_ID = String(fixtures.schedulingSectionId);
    process.env.E2E_WAITLIST_STUDENT_EMAIL = fixtures.waitlistStudentEmail;
    process.env.E2E_WAITLIST_STUDENT_PASSWORD = fixtures.waitlistStudentPassword;
    process.env.E2E_ATTENDANCE_OWNER_EMAIL = fixtures.attendanceOwnerEmail;
    process.env.E2E_ATTENDANCE_OWNER_PASSWORD = fixtures.attendanceOwnerPassword;
    process.env.E2E_ATTENDANCE_INSTRUCTOR_EMAIL = fixtures.attendanceInstructorEmail;
    process.env.E2E_ATTENDANCE_INSTRUCTOR_PASSWORD = fixtures.attendanceInstructorPassword;
    process.env.E2E_ATTENDANCE_STUDENT_EMAIL = fixtures.attendanceStudentEmail;
    process.env.E2E_ATTENDANCE_STUDENT_PASSWORD = fixtures.attendanceStudentPassword;
    process.env.E2E_ATTENDANCE_DATE = fixtures.attendanceDate;
    process.env.E2E_RUN_ID = fixtures.runId;
    process.env.E2E_OWNER_EMAIL = `phase02-institution-owner-${fixtures.runId}@example.test`;
}
