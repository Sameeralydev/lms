<?php

namespace App\Domain\Identity;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

final class PermissionCatalog
{
    /** @return array<string, string> */
    public static function tenantPermissions(): array
    {
        return [
            'tenant.view' => 'View institution workspace and profile.',
            'domains.manage' => 'Register and verify institution domains.',
            'institution.update' => 'Update institution profile and settings.',
            'campus.view' => 'View campuses.',
            'campus.create' => 'Create campuses.',
            'campus.update' => 'Update campus information and status.',
            'members.view' => 'View institution memberships.',
            'members.invite' => 'Invite users to the institution.',
            'members.manage' => 'Manage memberships and assign tenant roles.',
            'roles.view' => 'View tenant roles and their permissions.',
            'roles.manage' => 'Create tenant roles from permissions available to the actor.',
            'branding.update' => 'Update institution branding and theme.',
            'settings.update' => 'Update supported institution settings.',
            'audit.view' => 'View institution audit events.',
            'academic.view' => 'View academic configuration and dashboard.',
            'academic.settings.manage' => 'Update the academic operating model and terminology.',
            'academic.calendar.manage' => 'Manage academic years and lifecycle.',
            'academic.periods.manage' => 'Manage teaching periods and dates.',
            'academic.structure.manage' => 'Manage academic units, levels, and qualifications.',
            'academic.programs.manage' => 'Manage academic program definitions.',
            'academic.classes.manage' => 'Manage class, section, and cohort structures.',
            'academic.catalog.manage' => 'Manage subject and course definitions.',
            'academic.credit.manage' => 'Manage versioned credit-hour policies.',
            'academic.regional.manage' => 'Manage optional jurisdiction policy profiles and evidence.',
            'academic.outcomes.manage' => 'Manage learning-outcome references.',
            'academic.quality.manage' => 'Manage educational quality objectives, reviews, and actions.',
            'academic.documents.manage' => 'Manage controlled academic documents.',
            'academic.grades.policy.manage' => 'Create and activate institution-scoped grading policies and weighted categories.',
            'academic.grades.book.view' => 'View gradebooks for assigned sections within campus scope.',
            'academic.grades.book.manage' => 'Enter and submit grades for explicitly assigned course sections.',
            'academic.grades.book.manage-all' => 'Manage gradebooks across the institution or assigned campus scope.',
            'academic.grades.submit' => 'Submit a completed scoped gradebook for independent review.',
            'academic.grades.review' => 'Independently review and approve submitted gradebooks.',
            'academic.grades.publish' => 'Publish approved academic grades to eligible learners.',
            'academic.grades.view-own' => 'View only published grades for the signed-in learner.',
            'academic.records.gpa.manage' => 'Configure GPA calculation policies and calculate immutable academic progress snapshots.',
            'academic.records.issue' => 'Prepare institution-scoped report cards, transcripts, and eligible certificates.',
            'academic.records.review' => 'Independently approve official academic record issuance and correction requests.',
            'academic.records.view-all' => 'Download institution-scoped academic records for authorized registrar administration.',
            'academic.records.view-own' => 'View official academic records issued to the signed-in learner.',
            'academic.records.guardian.view' => 'View a learner’s official records only through verified guardian authority.',
            'academic.policy.approve' => 'Approve academic policy, course, and quality versions.',
            'academic.audit.view' => 'View academic audit history.',
            'exams.view' => 'View examination cycles and schedules within assigned institution scope.',
            'exams.manage' => 'Create examination cycles and draft examination sessions.',
            'exams.approve' => 'Approve examination cycles after review.',
            'exams.publish' => 'Publish approved examination schedules and candidate registration windows.',
            'exams.candidates.manage' => 'Register eligible students for published examination cycles.',
            'exams.paper.manage' => 'Create confidential examination paper drafts and revisions from approved question versions.',
            'exams.paper.review' => 'Independently review examination paper versions.',
            'exams.paper.publish' => 'Publish independently approved examination paper versions.',
            'exams.paper.confidential.view' => 'Preview confidential examination paper content within the institution.',
            'exams.invigilators.manage' => 'Assign and manage examination invigilator duty rosters.',
            'exams.invigilators.view-own' => 'View and respond to examination duties personally assigned to this member.',
            'exams.attendance.record' => 'Record attendance for candidates in assigned examination sessions.',
            'exams.accommodation.manage' => 'Authorize and review confidential candidate examination accommodations.',
            'exams.accommodation.request-own' => 'Request a confidential accommodation for the signed-in student.',
            'exams.eligibility.manage' => 'Configure and review examination eligibility policies and exceptions.',
            'exams.admit.issue' => 'Issue and revoke examination admit documents.',
            'exams.audit.view' => 'View examination governance audit history.',
            'exams.components.manage' => 'Create and schedule practical and oral examination components.',
            'exams.mark' => 'View and mark scripts assigned to the current examiner.',
            'exams.mark.allocate' => 'Allocate component marking and reassign unsubmitted work.',
            'exams.moderate' => 'Review independent markings and submit moderation decisions.',
            'exams.scripts.upload' => 'Upload private examination scripts into scanner quarantine.',
            'exams.scripts.view' => 'View scripts within an assigned component marking scope.',
            'exams.student.view-own' => 'View own examination schedule and completion status without unreleased marks.',
            'exams.results.approve' => 'Approve singly marked examination component results.',
            'exams.results.compile' => 'Compile server-calculated examination result snapshots.',
            'exams.results.release' => 'Release independently approved examination results.',
            'exams.recheck.request' => 'Request a recheck of an own released examination result.',
            'exams.recheck.review' => 'Review and decide assigned examination recheck requests.',
            'exams.policy.manage' => 'Configure examination result weights and recheck windows.',
            'exams.incidents.report' => 'Report an examination incident in an authorized session.',
            'exams.incidents.review' => 'Review reported examination incidents and record a decision.',
            'exams.analytics.view' => 'View scoped examination result and operations analytics.',
            'academic.curriculum.view' => 'View curriculum frameworks, plans, and design coverage.',
            'academic.curriculum.manage' => 'Create and edit draft curriculum frameworks, versions, hierarchy, and mappings.',
            'academic.curriculum.review' => 'Independently review submitted curriculum versions.',
            'academic.curriculum.publish' => 'Publish independently approved curriculum versions.',
            'academic.curriculum.templates.share' => 'Publish explicitly approved curriculum templates for adoption.',
            'academic.curriculum.audit.view' => 'View curriculum governance and revision history.',
            'learning.delivery.view' => 'View course delivery workspaces and published learning content.',
            'learning.delivery.manage' => 'Manage course delivery workspaces and draft learning content.',
            'learning.delivery.teach' => 'Teach and author content in explicitly assigned course workspaces.',
            'learning.delivery.review' => 'Independently review submitted course lessons.',
            'learning.delivery.publish' => 'Publish independently reviewed course lessons.',
            'learning.delivery.learn' => 'Access published lessons explicitly available to the member.',
            'learning.delivery.release-exceptions.approve' => 'Independently approve time-limited learner release exceptions.',
            'admissions.view' => 'View admissions workspace and operational records.',
            'admissions.settings.manage' => 'Configure institution admissions settings and policy.',
            'admissions.intakes.manage' => 'Create and manage admissions campaigns and intakes.',
            'admissions.forms.manage' => 'Build and publish versioned admissions forms.',
            'admissions.inquiries.manage' => 'Review and qualify admissions inquiries.',
            'admissions.applications.view' => 'View applications within assigned tenant and campus scope.',
            'admissions.applications.review' => 'Review assigned admissions applications.',
            'admissions.documents.review' => 'Review and verify submitted admissions documents.',
            'admissions.assessments.manage' => 'Schedule and record admissions assessments.',
            'admissions.eligibility.manage' => 'Evaluate applicant eligibility and record human review.',
            'admissions.merit.manage' => 'Calculate and manage versioned admissions merit lists.',
            'admissions.decisions.recommend' => 'Recommend admissions decisions for independent approval.',
            'admissions.decisions.approve' => 'Approve admissions decisions.',
            'admissions.offers.manage' => 'Issue and manage admissions offers.',
            'admissions.waitlist.manage' => 'Manage waitlist ordering and promotion.',
            'admissions.fees.manage' => 'Manage application fee obligations and authorized verification.',
            'admissions.handoff.manage' => 'Create and process accepted-applicant SIS handoffs.',
            'admissions.audit.view' => 'View admissions audit history.',
            'admissions.guardians.verify' => 'Verify a guardian relationship through the institution process.',
            'sis.view' => 'View student registry and authorized profiles.',
            'sis.students.create' => 'Register students and process approved admissions handoffs.',
            'sis.students.update' => 'Update permitted student profile information.',
            'sis.identity.reconcile.request' => 'Request a reviewed correction to a student identity link.',
            'sis.identity.reconcile.approve' => 'Approve or reject student identity-link reconciliation.',
            'sis.students.status' => 'Change student lifecycle status with a recorded reason.',
            'sis.placement.manage' => 'Place, promote and transfer students within the institution.',
            'sis.promotion.approve' => 'Independently approve or reject promotion decisions when the institution requires review.',
            'sis.promotion.holds.manage' => 'Place and release documented student progression holds.',
            'sis.promotion.correct' => 'Record an authorized compensating correction to a student promotion.',
            'sis.transfers.manage' => 'Submit external student transfer requests and manage their records.',
            'sis.transfers.approve' => 'Independently approve or reject external student transfer requests.',
            'sis.guardians.manage' => 'Manage student guardian relationships and authority.',
            'sis.guardians.verify' => 'Verify institution-managed guardian relationships.',
            'sis.family.manage' => 'Manage verified institution-specific family associations.',
            'sis.custom-fields.manage' => 'Configure institution-specific student fields.',
            'sis.custom-fields.sensitive' => 'Configure and view sensitive custom student attributes.',
            'sis.documents.view' => 'View authorized, scanned student document references.',
            'sis.documents.manage' => 'Upload and replace private student documents.',
            'sis.import' => 'Preview and confirm student registry CSV imports.',
            'sis.export' => 'Export the permitted student directory fields.',
            'sis.audit.view' => 'View student lifecycle and registry history.',
            'sis.settings.manage' => 'Configure institution student labels and registry numbering.',
            'sis.lifecycle.approve' => 'Independently approve or reject student lifecycle transitions.',
            'enrollment.registrar.view' => 'View institution course offerings, enrollment requests and rosters.',
            'enrollment.registrar.manage' => 'Manage enrollment policies, offerings, sections and registrar decisions.',
            'enrollment.instructor.roster.view' => 'View rosters for explicitly assigned course sections.',
            'enrollment.student.view' => 'View course registration options and the member’s own enrollment history.',
            'enrollment.student.register' => 'Register for eligible course sections and manage own registration.',
            'attendance.view' => 'View authorized attendance operations for the institution.',
            'attendance.policy.manage' => 'Configure institution and campus attendance policies.',
            'attendance.register.view' => 'View attendance registers within assigned scope.',
            'attendance.register.mark' => 'Mark attendance for explicitly assigned class occurrences.',
            'attendance.register.submit' => 'Submit an attendance register for review or finalization.',
            'attendance.register.override' => 'Perform authorized administrative attendance overrides and reopenings.',
            'attendance.student.view' => 'View only the signed-in learner’s own attendance record.',
            'attendance.guardian.view' => 'View attendance for a learner with a verified, active guardian relationship and profile access grant.',
            'attendance.justification.create' => 'Submit an absence justification for the signed-in learner.',
            'attendance.justification.review' => 'Review absence justifications within assigned institution scope.',
            'attendance.correction.create' => 'Request a correction to an attendance entry.',
            'attendance.correction.review' => 'Independently approve or reject attendance corrections.',
            'attendance.report.view' => 'View institution attendance summaries and reports.',
            'attendance.export' => 'Export attendance records within assigned scope.',
            'assignments.view' => 'View assignments within enrollment and teaching scope.',
            'assignments.manage' => 'Create and maintain draft assignments.',
            'assignments.publish' => 'Publish approved assignment versions.',
            'assignments.review' => 'Independently review assignments requiring approval.',
            'assignments.submission.view' => 'Review submissions for assigned sections.',
            'assignments.submission.create' => 'Create and submit work for the signed-in learner.',
            'assignments.submission.grade' => 'Mark submissions for assigned sections.',
            'assignments.grade.release' => 'Release assignment grades to learners.',
            'assignments.extension.request' => 'Request an extension for eligible coursework.',
            'assignments.extension.review' => 'Review extension requests within assigned scope.',
            'assignments.integrity.view' => 'View authorized academic integrity cases.',
            'assignments.integrity.report' => 'Report a suspected academic integrity concern for independent review.',
            'assignments.integrity.manage' => 'Record human-reviewed integrity case decisions.',
            'assignments.rubrics.manage' => 'Create and version assignment rubrics.',
            'assignments.groups.manage' => 'Manage eligible assignment groups.',
            'assignments.report.view' => 'View scoped assignment and submission reports.',
            'assessment.bank.view' => 'View question banks within institution and campus scope.',
            'assessment.bank.manage' => 'Create and revise draft question bank content.',
            'assessment.bank.review' => 'Independently review and activate question versions.',
            'assessment.bank.share' => 'Authorize institution-scoped question bank sharing.',
            'assessment.quiz.view' => 'View quizzes within course and section scope.',
            'assessment.quiz.manage' => 'Create and edit draft quizzes.',
            'assessment.quiz.manage-all' => 'Manage quizzes across the assigned institution or campus scope.',
            'assessment.quiz.review' => 'Independently review quiz publication requests.',
            'assessment.quiz.publish' => 'Schedule, publish, close, or archive approved quizzes.',
            'assessment.policy.manage' => 'Configure institution assessment policies.',
            'assessment.attempt.start' => 'Start eligible quizzes for the signed-in learner.',
            'assessment.attempt.respond' => 'Save responses to the signed-in learner attempt.',
            'assessment.attempt.view-own' => 'View own attempts and released results only.',
            'assessment.grade.manage' => 'Manually grade open-ended quiz responses.',
            'assessment.results.release' => 'Release quiz results under the configured policy.',
            'assessment.results.view-own' => 'View only results released to the signed-in learner.',
            'assessment.regrade.request' => 'Request a review of a released quiz score.',
            'assessment.regrade.review' => 'Review and decide quiz regrade requests.',
            'assessment.analytics.view' => 'View privacy-conscious quiz analytics.',
            'assessment.audit.view' => 'View assessment audit history within assigned scope.',
            'assessment.accommodation.manage' => 'Authorize confidential learner assessment accommodations.',
        ];
    }

    /** @return array<string, string> */
    public static function platformPermissions(): array
    {
        return [
            'platform.dashboard.view' => 'View platform operations dashboard.',
            'platform.tenants.view' => 'View tenant directory and tenant records.',
            'platform.tenants.create' => 'Provision a new institution tenant.',
            'platform.tenants.update' => 'Update institution and tenant profile.',
            'platform.tenants.status' => 'Activate or suspend a tenant.',
            'platform.operators.manage' => 'Assign non-superadmin platform operator roles.',
            'platform.audit.view' => 'View platform audit history.',
        ];
    }

    public static function ensure(): void
    {
        foreach (self::tenantPermissions() as $key => $description) {
            Permission::query()->updateOrCreate(['key' => $key], ['description' => $description, 'scope' => 'tenant']);
        }

        foreach (self::platformPermissions() as $key => $description) {
            Permission::query()->updateOrCreate(['key' => $key], ['description' => $description, 'scope' => 'platform']);
        }

    }

    public static function ensurePlatformRoles(): void
    {
        self::ensure();
        self::createPlatformRole('platform.operations_admin', 'Platform Operations Administrator', 'Operate tenant provisioning and status workflows.', ['platform.dashboard.view', 'platform.tenants.view', 'platform.tenants.create', 'platform.tenants.update', 'platform.tenants.status', 'platform.audit.view']);
        self::createPlatformRole('platform.support_operator', 'Platform Support Operator', 'Read platform and tenant operational records.', ['platform.dashboard.view', 'platform.tenants.view', 'platform.audit.view']);
    }

    public static function createTenantRoleTemplates(int $tenantId): void
    {
        self::ensure();

        $tenantPermissions = array_keys(self::tenantPermissions());
        $admissionsPermissions = array_values(array_filter($tenantPermissions, fn (string $key): bool => str_starts_with($key, 'admissions.') && ! in_array($key, ['admissions.settings.manage', 'admissions.decisions.approve', 'admissions.audit.view', 'admissions.guardians.verify'], true)));
        $sisRegistrarPermissions = ['sis.view', 'sis.students.create', 'sis.students.update', 'sis.identity.reconcile.request', 'sis.students.status', 'sis.placement.manage', 'sis.transfers.manage', 'sis.guardians.manage', 'sis.guardians.verify', 'sis.family.manage', 'sis.custom-fields.manage', 'sis.documents.view', 'sis.documents.manage', 'sis.import', 'sis.export', 'sis.audit.view', 'enrollment.registrar.view', 'enrollment.registrar.manage', 'assessment.quiz.view', 'assessment.analytics.view', 'assessment.audit.view', 'academic.grades.book.view', 'academic.grades.review', 'academic.grades.publish', 'academic.records.gpa.manage', 'academic.records.issue', 'academic.records.review', 'academic.records.view-all'];
        $templates = [
            'tenant.owner' => ['Institution Owner', 'Full access to currently implemented institution administration.', $tenantPermissions],
            'tenant.admin' => ['Institution Administrator', 'Manage currently implemented institution administration.', array_values(array_diff($tenantPermissions, ['domains.manage']))],
            'campus.admin' => ['Campus Administrator', 'Manage assigned campus operations.', ['tenant.view', 'campus.view', 'campus.update', 'assessment.bank.view', 'assessment.quiz.view', 'assessment.analytics.view', 'assessment.audit.view', 'academic.grades.book.view', 'academic.grades.book.manage-all', 'academic.grades.policy.manage', 'academic.grades.review', 'academic.grades.publish']],
            'academic.admin' => ['Academic Administrator', 'Manage currently implemented academic foundation workflows.', array_values(array_filter($tenantPermissions, fn (string $key): bool => str_starts_with($key, 'academic.') || str_starts_with($key, 'exams.') || str_starts_with($key, 'enrollment.registrar.') || str_starts_with($key, 'attendance.') || str_starts_with($key, 'assignments.') || str_starts_with($key, 'assessment.') || in_array($key, ['learning.delivery.view', 'learning.delivery.manage', 'learning.delivery.review', 'learning.delivery.publish'], true)))],
            'exam.invigilator' => ['Examination Invigilator', 'View assigned examination sessions, record attendance, and report incidents.', ['academic.view', 'exams.view', 'exams.invigilators.view-own', 'exams.attendance.record', 'exams.incidents.report']],
            'exam.controller' => ['Examination Controller', 'Manage examination operations, results, release and review queues.', ['academic.view', 'exams.view', 'exams.manage', 'exams.candidates.manage', 'exams.paper.publish', 'exams.invigilators.manage', 'exams.attendance.record', 'exams.accommodation.manage', 'exams.eligibility.manage', 'exams.admit.issue', 'exams.components.manage', 'exams.mark.allocate', 'exams.scripts.upload', 'exams.scripts.view', 'exams.results.approve', 'exams.results.compile', 'exams.results.release', 'exams.recheck.review', 'exams.policy.manage', 'exams.incidents.review', 'exams.analytics.view', 'exams.audit.view']],
            'examiner' => ['Examination Examiner', 'Mark only scripts allocated to this examiner and report session incidents.', ['academic.view', 'exams.view', 'exams.mark', 'exams.scripts.view', 'exams.incidents.report']],
            'exam.moderator' => ['Examination Moderator', 'Independently moderate submitted examination marks and assigned rechecks.', ['academic.view', 'exams.view', 'exams.moderate', 'exams.recheck.review']],
            'teacher' => ['Teacher', 'Teach, assess, and view rosters for explicitly assigned sections; exam marking remains limited to explicit script allocations.', ['academic.view', 'learning.delivery.view', 'learning.delivery.teach', 'enrollment.instructor.roster.view', 'attendance.view', 'attendance.register.view', 'attendance.register.mark', 'attendance.register.submit', 'attendance.correction.create', 'assignments.view', 'assignments.manage', 'assignments.publish', 'assignments.submission.view', 'assignments.submission.grade', 'assignments.extension.review', 'assignments.integrity.report', 'assignments.rubrics.manage', 'assignments.groups.manage', 'assignments.report.view', 'assessment.bank.view', 'assessment.bank.manage', 'assessment.bank.review', 'assessment.quiz.view', 'assessment.quiz.manage', 'assessment.quiz.review', 'assessment.quiz.publish', 'assessment.attempt.view-own', 'assessment.grade.manage', 'assessment.results.release', 'assessment.regrade.review', 'assessment.analytics.view', 'assessment.audit.view', 'academic.grades.book.view', 'academic.grades.book.manage', 'academic.grades.submit', 'exams.view', 'exams.invigilators.view-own', 'exams.mark', 'exams.scripts.view']],
            'student' => ['Student', 'Access published learning content, manage own course registration and request review of released examinations.', ['learning.delivery.view', 'learning.delivery.learn', 'enrollment.student.view', 'enrollment.student.register', 'attendance.student.view', 'attendance.justification.create', 'assignments.view', 'assignments.submission.create', 'assignments.extension.request', 'assessment.quiz.view', 'assessment.attempt.start', 'assessment.attempt.respond', 'assessment.attempt.view-own', 'assessment.results.view-own', 'assessment.regrade.request', 'academic.grades.view-own', 'academic.records.view-own', 'exams.student.view-own', 'exams.accommodation.request-own', 'exams.recheck.request', 'exams.incidents.report']],
            'parent.guardian' => ['Parent / Guardian', 'View attendance and published academic records only for a learner with verified, active guardian authority.', ['attendance.guardian.view', 'academic.records.guardian.view']],
            'finance.manager' => ['Finance Manager', 'Finance role template; financial permissions are defined in later phases.', []],
            'hr.manager' => ['HR Manager', 'HR role template; workforce permissions are defined in later phases.', []],
            'admissions.officer' => ['Admissions Officer', 'Manage assigned admissions workflows; final decision approval remains separately granted.', $admissionsPermissions],
            'sis.registrar' => ['Student Registrar', 'Manage currently implemented student registry workflows.', [...$sisRegistrarPermissions, 'attendance.view', 'attendance.policy.manage', 'attendance.register.view', 'attendance.register.mark', 'attendance.register.submit', 'attendance.register.override', 'attendance.justification.review', 'attendance.correction.review', 'attendance.report.view', 'attendance.export']],
            'auditor' => ['Auditor / Read-only Viewer', 'Read-only access to currently implemented workspace records.', ['tenant.view', 'campus.view', 'members.view', 'roles.view', 'audit.view', 'academic.view', 'academic.audit.view', 'academic.curriculum.view', 'academic.curriculum.audit.view', 'learning.delivery.view', 'admissions.view', 'admissions.audit.view', 'sis.view', 'sis.audit.view', 'attendance.view', 'attendance.register.view', 'attendance.report.view', 'assessment.bank.view', 'assessment.quiz.view', 'assessment.analytics.view', 'assessment.audit.view']],
        ];

        DB::transaction(function () use ($templates, $tenantId): void {
            foreach ($templates as $key => [$name, $description, $permissionKeys]) {
                $role = Role::query()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'key' => $key],
                    ['name' => $name, 'description' => $description, 'scope' => 'tenant', 'is_system' => true],
                );
                $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
                $role->permissions()->sync($permissionIds);
            }
        });
    }

    /** @param list<string> $permissionKeys */
    public static function createPlatformRole(string $key, string $name, string $description, array $permissionKeys): Role
    {
        self::ensure();
        $role = Role::query()->updateOrCreate(
            ['tenant_id' => null, 'key' => $key],
            ['scope' => 'platform', 'name' => $name, 'description' => $description, 'is_system' => true],
        );
        $role->permissions()->sync(Permission::query()->whereIn('key', $permissionKeys)->pluck('id'));

        return $role;
    }
}
