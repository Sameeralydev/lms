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
            'communications.announcements.view' => 'View published announcements addressed to this membership.',
            'communications.announcements.manage' => 'Create and update institution announcements for authorized roles and campus scope.',
            'communications.messages.view' => 'View direct-message conversations in which this membership is a participant.',
            'communications.messages.send' => 'Send direct messages to eligible active institution members.',
            'communications.preferences.manage-own' => 'Manage personal in-app communication notification preferences.',
            'integrations.view' => 'View configured standards and integration access for this institution.',
            'integrations.manage' => 'Create and revoke institution-scoped integration credentials.',
            'hr.staff.view' => 'View staff directory and employment records within authorized institution and campus scope.',
            'hr.staff.view-own' => 'View the signed-in member’s own staff record.',
            'hr.staff.manage' => 'Create and update staff employment records within authorized scope.',
            'hr.staff.sensitive.view' => 'View sensitive staff contact and emergency-contact fields.',
            'hr.staff.status.manage' => 'Change staff employment status and record the effective lifecycle event.',
            'hr.attendance.manage' => 'View staff attendance records within authorized institution and campus scope.',
            'hr.leave.manage' => 'Manage leave types and review staff leave requests within authorized scope.',
            'library.view' => 'Browse the institution library catalog and scoped circulation records.',
            'library.manage' => 'Manage library catalog resources and physical copy inventory.',
            'library.inventory.manage' => 'Register and adjust physical copies within authorized library locations.',
            'library.circulate' => 'Check out, return, renew, and fulfill library resources.',
            'library.members.manage' => 'Activate or suspend eligible library memberships.',
            'library.policy.manage' => 'Configure library loan and reservation policies.',
            'library.member.view-own' => 'View only the signed-in member’s library loans and reservations.',
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
            'academic.analytics.view' => 'View institution and campus-scoped academic operations metrics.',
            'academic.analytics.drilldown' => 'Inspect privacy-limited source records behind authorized academic report aggregates.',
            'academic.analytics.export' => 'Export aggregate institution and campus-scoped academic reports.',
            'academic.analytics.reports.manage' => 'Create and delete saved academic analytics report configurations.',
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
            'billing.view' => 'View tenant billing operations and scoped financial summaries.',
            'billing.fees.manage' => 'Create, update and activate institution fee heads and structures.',
            'billing.invoices.view' => 'View issued invoices within institution and campus scope.',
            'billing.invoices.issue' => 'Issue immutable, idempotent student invoices from active fee structures.',
            'billing.invoices.cancel' => 'Cancel eligible unpaid invoices with an audited reason.',
            'billing.reports.view' => 'View permitted tenant and campus billing reports.',
            'billing.reports.export' => 'Export authorized tenant and campus billing reports.',
            'billing.reports.drilldown' => 'Inspect authorized billing records behind permitted financial report aggregates.',
            'billing.reconciliation.manage' => 'Configure scoped bank accounts and import statement CSV batches.',
            'billing.reconciliation.review' => 'Review, match, exclude, and close scoped bank reconciliation batches.',
            'billing.concessions.manage' => 'Review and apply authorized student concessions.',
            'billing.adjustments.manage' => 'Record approved auditable billing adjustments.',
            'billing.adjustments.approve' => 'Independently review billing adjustments and credit notes.',
            'billing.adjustments.post' => 'Post independently approved financial adjustments to invoice balances.',
            'billing.assignments.manage' => 'Assign approved fee structures to eligible learners and enrollments.',
            'billing.installments.manage' => 'Create, publish, and manage invoice installment schedules.',
            'billing.concessions.approve' => 'Independently review learner concessions and scholarships.',
            'billing.ledger.view' => 'View tenant-scoped student financial ledgers and statements.',
            'billing.ledger.view-own' => 'View the signed-in learner or verified guardian financial statement.',
            'billing.portal.view-own' => 'View billing documents for the signed-in learner or verified guardian.',
            'billing.payments.view' => 'View payment records and collection activity within institution and campus scope.',
            'billing.payments.collect' => 'Record authorized cash collections and bank-transfer submissions.',
            'billing.payments.verify' => 'Verify or reject pending bank-transfer submissions with an audited decision.',
            'billing.payments.allocate' => 'Allocate confirmed unapplied payment balances to eligible invoices.',
            'billing.payments.reverse.request' => 'Request a reasoned reversal of a confirmed payment.',
            'billing.payments.reverse.approve' => 'Independently approve or reject payment reversal requests.',
            'billing.payments.reverse.execute' => 'Execute an approved payment reversal with invoice balance restoration.',
            'billing.receipts.view' => 'View and print receipts for confirmed payments within institution and campus scope.',
            'billing.refunds.view' => 'View refund requests and executed refund history in authorized financial scope.',
            'billing.refunds.request' => 'Request a reasoned refund against an eligible confirmed payment.',
            'billing.refunds.approve' => 'Independently approve or reject pending refund requests.',
            'billing.refunds.execute' => 'Record a separately executed manual refund with its payout reference.',
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
            'platform.plans.manage' => 'Create and manage tenant feature plans.',
            'platform.subscriptions.manage' => 'Assign and manage tenant plan access.',
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
        self::createPlatformRole('platform.operations_admin', 'Platform Operations Administrator', 'Operate tenant provisioning and status workflows.', ['platform.dashboard.view', 'platform.tenants.view', 'platform.tenants.create', 'platform.tenants.update', 'platform.tenants.status', 'platform.plans.manage', 'platform.subscriptions.manage', 'platform.audit.view']);
        self::createPlatformRole('platform.support_operator', 'Platform Support Operator', 'Read platform and tenant operational records.', ['platform.dashboard.view', 'platform.tenants.view', 'platform.audit.view']);
    }

    public static function createTenantRoleTemplates(int $tenantId): void
    {
        self::ensure();

        $tenantPermissions = array_keys(self::tenantPermissions());
        $admissionsPermissions = array_values(array_unique([...array_filter($tenantPermissions, fn (string $key): bool => str_starts_with($key, 'admissions.') && ! in_array($key, ['admissions.settings.manage', 'admissions.decisions.approve', 'admissions.audit.view', 'admissions.guardians.verify'], true)), 'hr.staff.view-own', 'communications.announcements.view', 'communications.preferences.manage-own']));
        $sisRegistrarPermissions = ['hr.staff.view-own', 'communications.announcements.view', 'communications.announcements.manage', 'communications.messages.view', 'communications.messages.send', 'communications.preferences.manage-own', 'sis.view', 'sis.students.create', 'sis.students.update', 'sis.identity.reconcile.request', 'sis.students.status', 'sis.placement.manage', 'sis.transfers.manage', 'sis.guardians.manage', 'sis.guardians.verify', 'sis.family.manage', 'sis.custom-fields.manage', 'sis.documents.view', 'sis.documents.manage', 'sis.import', 'sis.export', 'sis.audit.view', 'enrollment.registrar.view', 'enrollment.registrar.manage', 'assessment.quiz.view', 'assessment.analytics.view', 'assessment.audit.view', 'academic.analytics.view', 'academic.analytics.drilldown', 'academic.analytics.export', 'academic.analytics.reports.manage', 'academic.grades.book.view', 'academic.grades.review', 'academic.grades.publish', 'academic.records.gpa.manage', 'academic.records.issue', 'academic.records.review', 'academic.records.view-all'];
        $templates = [
            'tenant.owner' => ['Institution Owner', 'Full access to currently implemented institution administration.', $tenantPermissions],
            'tenant.admin' => ['Institution Administrator', 'Manage currently implemented institution administration.', array_values(array_diff($tenantPermissions, ['domains.manage']))],
            'finance.manager' => ['Finance Manager', 'Manage billing and authorized payment collection workflows.', ['tenant.view', 'billing.view', 'billing.fees.manage', 'billing.assignments.manage', 'billing.installments.manage', 'billing.invoices.view', 'billing.invoices.issue', 'billing.invoices.cancel', 'billing.reports.view', 'billing.reports.export', 'billing.reports.drilldown', 'billing.reconciliation.manage', 'billing.reconciliation.review', 'billing.ledger.view', 'billing.concessions.manage', 'billing.concessions.approve', 'billing.adjustments.manage', 'billing.adjustments.approve', 'billing.adjustments.post', 'billing.payments.view', 'billing.payments.collect', 'billing.payments.verify', 'billing.payments.allocate', 'billing.payments.reverse.request', 'billing.payments.reverse.approve', 'billing.payments.reverse.execute', 'billing.receipts.view', 'billing.refunds.view', 'billing.refunds.request', 'billing.refunds.approve', 'billing.refunds.execute', 'hr.staff.view-own']],
            'campus.admin' => ['Campus Administrator', 'Manage assigned campus operations.', ['tenant.view', 'campus.view', 'campus.update', 'communications.announcements.view', 'communications.announcements.manage', 'communications.messages.view', 'communications.messages.send', 'communications.preferences.manage-own', 'hr.staff.view', 'hr.staff.manage', 'hr.staff.status.manage', 'hr.attendance.manage', 'academic.view', 'assessment.bank.view', 'assessment.quiz.view', 'assessment.analytics.view', 'assessment.audit.view', 'academic.analytics.view', 'academic.analytics.drilldown', 'academic.analytics.export', 'academic.analytics.reports.manage', 'enrollment.registrar.view', 'academic.grades.book.view', 'academic.grades.book.manage-all', 'academic.grades.policy.manage', 'academic.grades.review', 'academic.grades.publish']],
            'academic.admin' => ['Academic Administrator', 'Manage currently implemented academic foundation workflows.', array_values(array_filter($tenantPermissions, fn (string $key): bool => str_starts_with($key, 'academic.') || str_starts_with($key, 'exams.') || str_starts_with($key, 'enrollment.registrar.') || str_starts_with($key, 'attendance.') || str_starts_with($key, 'assignments.') || str_starts_with($key, 'assessment.') || str_starts_with($key, 'communications.announcements.') || str_starts_with($key, 'communications.messages.') || in_array($key, ['hr.staff.view', 'communications.preferences.manage-own'], true) || in_array($key, ['learning.delivery.view', 'learning.delivery.manage', 'learning.delivery.review', 'learning.delivery.publish'], true)))],
            'exam.invigilator' => ['Examination Invigilator', 'View assigned examination sessions, record attendance, and report incidents.', ['hr.staff.view-own', 'academic.view', 'exams.view', 'exams.invigilators.view-own', 'exams.attendance.record', 'exams.incidents.report']],
            'exam.controller' => ['Examination Controller', 'Manage examination operations, results, release and review queues.', ['hr.staff.view-own', 'academic.view', 'exams.view', 'exams.manage', 'exams.candidates.manage', 'exams.paper.publish', 'exams.invigilators.manage', 'exams.attendance.record', 'exams.accommodation.manage', 'exams.eligibility.manage', 'exams.admit.issue', 'exams.components.manage', 'exams.mark.allocate', 'exams.scripts.upload', 'exams.scripts.view', 'exams.results.approve', 'exams.results.compile', 'exams.results.release', 'exams.recheck.review', 'exams.policy.manage', 'exams.incidents.review', 'exams.analytics.view', 'exams.audit.view']],
            'examiner' => ['Examination Examiner', 'Mark only scripts allocated to this examiner and report session incidents.', ['hr.staff.view-own', 'academic.view', 'exams.view', 'exams.mark', 'exams.scripts.view', 'exams.incidents.report']],
            'exam.moderator' => ['Examination Moderator', 'Independently moderate submitted examination marks and assigned rechecks.', ['hr.staff.view-own', 'academic.view', 'exams.view', 'exams.moderate', 'exams.recheck.review']],
            'teacher' => ['Teacher', 'Teach, assess, and view rosters for explicitly assigned sections; exam marking remains limited to explicit script allocations.', ['hr.staff.view-own', 'communications.announcements.view', 'communications.announcements.manage', 'communications.messages.view', 'communications.messages.send', 'communications.preferences.manage-own', 'academic.view', 'learning.delivery.view', 'learning.delivery.teach', 'enrollment.instructor.roster.view', 'attendance.view', 'attendance.register.view', 'attendance.register.mark', 'attendance.register.submit', 'attendance.correction.create', 'assignments.view', 'assignments.manage', 'assignments.publish', 'assignments.submission.view', 'assignments.submission.grade', 'assignments.extension.review', 'assignments.integrity.report', 'assignments.rubrics.manage', 'assignments.groups.manage', 'assignments.report.view', 'assessment.bank.view', 'assessment.bank.manage', 'assessment.bank.review', 'assessment.quiz.view', 'assessment.quiz.manage', 'assessment.quiz.review', 'assessment.quiz.publish', 'assessment.attempt.view-own', 'assessment.grade.manage', 'assessment.results.release', 'assessment.regrade.review', 'assessment.analytics.view', 'assessment.audit.view', 'academic.grades.book.view', 'academic.grades.book.manage', 'academic.grades.submit', 'exams.view', 'exams.invigilators.view-own', 'exams.mark', 'exams.scripts.view']],
            'student' => ['Student', 'Access published learning content, own billing, course registration and released academic records.', ['communications.announcements.view', 'communications.messages.view', 'communications.messages.send', 'communications.preferences.manage-own', 'learning.delivery.view', 'learning.delivery.learn', 'enrollment.student.view', 'enrollment.student.register', 'attendance.student.view', 'attendance.justification.create', 'assignments.view', 'assignments.submission.create', 'assignments.extension.request', 'assessment.quiz.view', 'assessment.attempt.start', 'assessment.attempt.respond', 'assessment.attempt.view-own', 'assessment.results.view-own', 'assessment.regrade.request', 'academic.grades.view-own', 'academic.records.view-own', 'billing.portal.view-own', 'billing.ledger.view-own', 'exams.student.view-own', 'exams.accommodation.request-own', 'exams.recheck.request', 'exams.incidents.report']],
            'parent.guardian' => ['Parent / Guardian', 'View learner billing, attendance and published records only through verified active guardian authority and approved profile access.', ['communications.announcements.view', 'communications.messages.view', 'communications.messages.send', 'communications.preferences.manage-own', 'attendance.guardian.view', 'academic.records.guardian.view', 'billing.portal.view-own', 'billing.ledger.view-own']],
            'hr.manager' => ['HR Manager', 'Manage staff employment records and authorized sensitive fields.', ['hr.staff.view', 'hr.staff.view-own', 'hr.staff.manage', 'hr.staff.sensitive.view', 'hr.staff.status.manage', 'hr.attendance.manage', 'hr.leave.manage']],
            'library.manager' => ['Library Manager', 'Manage the institution library catalog, inventory, membership, and circulation.', ['library.view', 'library.manage', 'library.inventory.manage', 'library.circulate', 'library.members.manage', 'library.policy.manage']],
            'admissions.officer' => ['Admissions Officer', 'Manage assigned admissions workflows; final decision approval remains separately granted.', $admissionsPermissions],
            'sis.registrar' => ['Student Registrar', 'Manage currently implemented student registry workflows.', [...$sisRegistrarPermissions, 'attendance.view', 'attendance.policy.manage', 'attendance.register.view', 'attendance.register.mark', 'attendance.register.submit', 'attendance.register.override', 'attendance.justification.review', 'attendance.correction.review', 'attendance.report.view', 'attendance.export']],
            'auditor' => ['Auditor / Read-only Viewer', 'Read-only access to currently implemented workspace records.', ['communications.announcements.view', 'communications.messages.view', 'tenant.view', 'campus.view', 'members.view', 'roles.view', 'audit.view', 'academic.view', 'academic.audit.view', 'academic.curriculum.view', 'academic.curriculum.audit.view', 'learning.delivery.view', 'admissions.view', 'admissions.audit.view', 'sis.view', 'sis.audit.view', 'attendance.view', 'attendance.register.view', 'attendance.report.view', 'assessment.bank.view', 'assessment.quiz.view', 'assessment.analytics.view', 'assessment.audit.view']],
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
