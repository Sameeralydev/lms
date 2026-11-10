<?php

use App\Domain\Identity\PermissionCatalog;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\AcademicCourse;
use App\Models\AcademicPeriod;
use App\Models\AcademicRoom;
use App\Models\AcademicTimetable;
use App\Models\AcademicTimetableMeeting;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\EnrollmentPolicy;
use App\Models\ExamCandidate;
use App\Models\ExamComponent;
use App\Models\ExamComponentAllocation;
use App\Models\ExamComponentRubric;
use App\Models\ExamCycle;
use App\Models\ExamPaper;
use App\Models\ExamPaperVersion;
use App\Models\ExamSession;
use App\Models\Institution;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\QuizQuestionBank;
use App\Models\QuizQuestionVersion;
use App\Models\QuizVersion;
use App\Models\Role;
use App\Models\SisStudent;
use App\Models\SisStudentGuardian;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserPreference;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\TestDatabaseGuard;

$basePath = dirname(__DIR__, 2);
$e2eDatabase = getenv('E2E_DATABASE') ?: '';
if ($e2eDatabase === '') {
    fwrite(STDERR, "Browser integration tests require a unique E2E_DATABASE path.\n");
    exit(2);
}
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=sqlite');
$_ENV['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_CONNECTION'] = 'sqlite';
putenv('DB_DATABASE='.$e2eDatabase);
$_ENV['DB_DATABASE'] = $e2eDatabase;
$_SERVER['DB_DATABASE'] = $e2eDatabase;
putenv('DB_URL=');
$_ENV['DB_URL'] = '';
$_SERVER['DB_URL'] = '';
putenv('E2E_TEST_MODE=true');
$_ENV['E2E_TEST_MODE'] = 'true';
$_SERVER['E2E_TEST_MODE'] = 'true';
require $basePath.'/vendor/autoload.php';
Dotenv::createImmutable($basePath, '.env.testing')->safeLoad();

try {
    TestDatabaseGuard::assertSafe(TestDatabaseGuard::currentEnvironment(), allowPhase04E2ESqlite: true);

    $storageDirectory = $basePath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework';
    $expectedDirectory = realpath($storageDirectory);
    $configuredDirectory = realpath(dirname($e2eDatabase));
    if ($expectedDirectory === false || $configuredDirectory !== $expectedDirectory
        || preg_match('/\\Aphase06-e2e-[0-9a-f-]{36}\\.sqlite\\z/iD', basename($e2eDatabase)) !== 1
        || (getenv('E2E_RUN_ID') ?: '') !== substr(basename($e2eDatabase), strlen('phase06-e2e-'), 36)) {
        throw new RuntimeException('The browser-test database path is outside the guarded unique Phase 06 test-file policy.');
    }

    if (! is_dir($storageDirectory) && ! mkdir($storageDirectory, 0770, true) && ! is_dir($storageDirectory)) {
        throw new RuntimeException('The isolated browser test directory could not be created.');
    }

    if (file_exists($e2eDatabase) || is_link($e2eDatabase)) {
        throw new RuntimeException('The unique browser-test database already exists; no file or database was modified.');
    }
    $databaseHandle = @fopen($e2eDatabase, 'x+b');
    if (! is_resource($databaseHandle)) {
        throw new RuntimeException('The unique isolated browser-test database file could not be created.');
    }
    fclose($databaseHandle);

    $app = require $basePath.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (config('app.env') !== 'testing' || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $e2eDatabase) {
        throw new RuntimeException('Browser integration tests require the explicitly isolated Phase 04 SQLite file.');
    }

    $connection = DB::connection();
    if ($connection->getDatabaseName() !== $e2eDatabase) {
        throw new RuntimeException('The browser integration database is not the approved Phase 04 SQLite file.');
    }

    $migrationExit = $app->make(Kernel::class)->call('migrate', ['--force' => true]);
    if ($migrationExit !== 0) {
        throw new RuntimeException('The disposable browser-test database could not be migrated.');
    }

    $connection->statement('CREATE TABLE phase04_e2e_safety_marker (marker VARCHAR(64) NOT NULL)');
    $connection->table('phase04_e2e_safety_marker')->insert(['marker' => 'techmentors-phase04-e2e-only']);

    foreach (['users', 'tenants', 'permissions', 'roles', 'platform_role_user'] as $table) {
        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            throw new RuntimeException('The isolated browser test schema is missing a required migrated table.');
        }
    }

    $runId = Str::lower(Str::random(10));
    $adminEmail = 'phase02-e2e-admin-'.$runId.'@example.test';
    $adminPassword = Str::random(40);

    $admin = User::query()->create([
        'name' => 'Phase 02 Browser Test Operator',
        'email' => $adminEmail,
        'password' => Hash::make($adminPassword),
    ]);
    $admin->forceFill(['email_verified_at' => now()])->save();
    $applicantEmail = 'phase04-e2e-applicant-'.$runId.'@example.test';
    $applicantPassword = Str::random(40);
    $applicant = User::query()->create([
        'name' => 'Phase 04 Browser Test Applicant',
        'email' => $applicantEmail,
        'password' => Hash::make($applicantPassword),
    ]);
    $applicant->forceFill(['email_verified_at' => now()])->save();
    $guardianEmail = 'phase04-e2e-guardian-'.$runId.'@example.test';
    $guardianPassword = Str::random(40);
    $guardian = User::query()->create([
        'name' => 'Phase 04 Browser Test Guardian',
        'email' => $guardianEmail,
        'password' => Hash::make($guardianPassword),
    ]);
    $guardian->forceFill(['email_verified_at' => now()])->save();
    UserPreference::query()->where('user_id', $admin->getKey())->where('key', 'theme')->delete();
    PermissionCatalog::createPlatformRole(
        'platform.super_admin',
        'Platform Super Administrator',
        'Test-only browser verification administrator.',
        array_keys(PermissionCatalog::platformPermissions()),
    );
    $superAdminRole = Role::query()->whereNull('tenant_id')->where('key', 'platform.super_admin')->firstOrFail();
    $admin->platformRoles()->syncWithoutDetaching([$superAdminRole->getKey() => ['assigned_by' => $admin->getKey()]]);

    $registrationOwnerEmail = 'phase08-e2e-owner-'.$runId.'@example.test';
    $registrationOwnerPassword = Str::random(40);
    $registrationOwner = User::query()->create(['name' => 'Phase 08 E2E Registrar', 'email' => $registrationOwnerEmail, 'password' => Hash::make($registrationOwnerPassword)]);
    $registrationOwner->forceFill(['email_verified_at' => now()])->save();
    $campusAdminEmail = 'frontend-audit-campus-'.$runId.'@example.test';
    $campusAdminPassword = Str::random(40);
    $campusAdmin = User::query()->create(['name' => 'Frontend Audit Campus Administrator', 'email' => $campusAdminEmail, 'password' => Hash::make($campusAdminPassword)]);
    $campusAdmin->forceFill(['email_verified_at' => now()])->save();
    $admissionsOfficerEmail = 'frontend-audit-admissions-'.$runId.'@example.test';
    $admissionsOfficerPassword = Str::random(40);
    $admissionsOfficer = User::query()->create(['name' => 'Frontend Audit Admissions Officer', 'email' => $admissionsOfficerEmail, 'password' => Hash::make($admissionsOfficerPassword)]);
    $admissionsOfficer->forceFill(['email_verified_at' => now()])->save();
    $registrarEmail = 'frontend-audit-registrar-'.$runId.'@example.test';
    $registrarPassword = Str::random(40);
    $registrar = User::query()->create(['name' => 'Frontend Audit Academic Registrar', 'email' => $registrarEmail, 'password' => Hash::make($registrarPassword)]);
    $registrar->forceFill(['email_verified_at' => now()])->save();
    $registrationInstructorEmail = 'phase08-e2e-instructor-'.$runId.'@example.test';
    $registrationInstructorPassword = Str::random(40);
    $registrationInstructor = User::query()->create(['name' => 'Phase 08 E2E Instructor', 'email' => $registrationInstructorEmail, 'password' => Hash::make($registrationInstructorPassword)]);
    $registrationInstructor->forceFill(['email_verified_at' => now()])->save();
    $unassignedInstructorEmail = 'phase08-e2e-unassigned-instructor-'.$runId.'@example.test';
    $unassignedInstructorPassword = Str::random(40);
    $unassignedInstructor = User::query()->create(['name' => 'Phase 08 E2E Unassigned Instructor', 'email' => $unassignedInstructorEmail, 'password' => Hash::make($unassignedInstructorPassword)]);
    $unassignedInstructor->forceFill(['email_verified_at' => now()])->save();
    $registrationStudentEmail = 'phase08-e2e-student-'.$runId.'@example.test';
    $registrationStudentPassword = Str::random(40);
    $registrationStudent = User::query()->create(['name' => 'Phase 08 E2E Student', 'email' => $registrationStudentEmail, 'password' => Hash::make($registrationStudentPassword)]);
    $registrationStudent->forceFill(['email_verified_at' => now()])->save();
    $schedulingStudentEmail = 'phase09-e2e-scheduling-student-'.$runId.'@example.test';
    $schedulingStudentPassword = Str::random(40);
    $schedulingStudent = User::query()->create(['name' => 'Phase 09 E2E Scheduling Student', 'email' => $schedulingStudentEmail, 'password' => Hash::make($schedulingStudentPassword)]);
    $schedulingStudent->forceFill(['email_verified_at' => now()])->save();
    $schedulingInstructor = User::query()->create(['name' => 'Phase 09 E2E Scheduling Instructor', 'email' => 'phase09-e2e-scheduling-instructor-'.$runId.'@example.test', 'password' => Hash::make(Str::random(40))]);
    $schedulingInstructor->forceFill(['email_verified_at' => now()])->save();
    $substituteInstructor = User::query()->create(['name' => 'Phase 09 E2E Substitute Instructor', 'email' => 'phase09-e2e-substitute-instructor-'.$runId.'@example.test', 'password' => Hash::make(Str::random(40))]);
    $substituteInstructor->forceFill(['email_verified_at' => now()])->save();
    $waitlistStudentEmail = 'phase08-e2e-waitlist-'.$runId.'@example.test';
    $waitlistStudentPassword = Str::random(40);
    $waitlistStudent = User::query()->create(['name' => 'Phase 08 E2E Waitlist Student', 'email' => $waitlistStudentEmail, 'password' => Hash::make($waitlistStudentPassword)]);
    $waitlistStudent->forceFill(['email_verified_at' => now()])->save();
    $attendanceOwnerEmail = 'phase10-e2e-owner-'.$runId.'@example.test';
    $attendanceOwnerPassword = Str::random(40);
    $attendanceOwner = User::query()->create(['name' => 'Phase 10 E2E Attendance Administrator', 'email' => $attendanceOwnerEmail, 'password' => Hash::make($attendanceOwnerPassword)]);
    $attendanceOwner->forceFill(['email_verified_at' => now()])->save();
    $attendanceInstructorEmail = 'phase10-e2e-instructor-'.$runId.'@example.test';
    $attendanceInstructorPassword = Str::random(40);
    $attendanceInstructor = User::query()->create(['name' => 'Phase 10 E2E Instructor', 'email' => $attendanceInstructorEmail, 'password' => Hash::make($attendanceInstructorPassword)]);
    $attendanceInstructor->forceFill(['email_verified_at' => now()])->save();
    $attendanceStudentEmail = 'phase10-e2e-student-'.$runId.'@example.test';
    $attendanceStudentPassword = Str::random(40);
    $attendanceStudent = User::query()->create(['name' => 'Phase 10 E2E Student', 'email' => $attendanceStudentEmail, 'password' => Hash::make($attendanceStudentPassword)]);
    $attendanceStudent->forceFill(['email_verified_at' => now()])->save();
    $registrationCreator = User::query()->create(['name' => 'Phase 08 E2E Provisioner', 'email' => 'phase08-e2e-provisioner-'.$runId.'@example.test', 'password' => Hash::make(Str::random(40))]);
    $schedulingReviewerEmail = 'phase09-e2e-reviewer-'.$runId.'@example.test';
    $schedulingReviewerPassword = Str::random(40);
    $schedulingReviewer = User::query()->create(['name' => 'Phase 09 E2E Scheduling Reviewer', 'email' => $schedulingReviewerEmail, 'password' => Hash::make($schedulingReviewerPassword)]);
    $schedulingReviewer->forceFill(['email_verified_at' => now()])->save();
    $examinationControllerEmail = 'phase13-e2e-controller-'.$runId.'@example.test';
    $examinationControllerPassword = Str::random(40);
    $examinationController = User::query()->create(['name' => 'Phase 13 E2E Examination Controller', 'email' => $examinationControllerEmail, 'password' => Hash::make($examinationControllerPassword)]);
    $examinationController->forceFill(['email_verified_at' => now()])->save();
    $examinationInvigilatorEmail = 'phase13-e2e-invigilator-'.$runId.'@example.test';
    $examinationInvigilatorPassword = Str::random(40);
    $examinationInvigilator = User::query()->create(['name' => 'Phase 13 E2E Invigilator', 'email' => $examinationInvigilatorEmail, 'password' => Hash::make($examinationInvigilatorPassword)]);
    $examinationInvigilator->forceFill(['email_verified_at' => now()])->save();
    $registrationTenantResult = app(TenantProvisioner::class)->provision([
        'slug' => 'phase08-e2e-'.$runId, 'official_name' => 'Phase 08 Enrollment Test Institution',
        'display_name' => 'Phase 08 Enrollment Test Institution', 'code' => '', 'type' => 'academy',
        'contact_email' => '', 'contact_phone' => '', 'website' => '', 'country' => '', 'region' => '',
        'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
    ], $registrationCreator, $registrationOwnerEmail);
    $registrationTenant = $registrationTenantResult->tenant;
    $registrationContext = app(TenantContext::class);
    $registrationContext->enterProvisioning($registrationTenant);
    try {
        $institution = Institution::query()->firstOrFail();
        $registrationCampus = Campus::query()->create(['institution_id' => $institution->getKey(), 'code' => 'E2E-MAIN', 'name' => 'E2E Main Campus', 'status' => 'active', 'is_primary' => true]);
        $ownerMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $registrationOwner->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $ownerRole = Role::query()->where('tenant_id', $registrationTenant->getKey())->where('key', 'tenant.owner')->firstOrFail();
        $ownerMembership->roles()->attach($ownerRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $campusAdminMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $registrationCampus->getKey(), 'user_id' => $campusAdmin->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $campusAdminRole = Role::query()->where('tenant_id', $registrationTenant->getKey())->where('key', 'campus.admin')->firstOrFail();
        $campusAdminMembership->roles()->attach($campusAdminRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $admissionsMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $admissionsOfficer->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $admissionsRole = Role::query()->where('tenant_id', $registrationTenant->getKey())->where('key', 'admissions.officer')->firstOrFail();
        $admissionsMembership->roles()->attach($admissionsRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $registrarMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $registrationCampus->getKey(), 'user_id' => $registrar->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $registrarRole = Role::query()->where('tenant_id', $registrationTenant->getKey())->where('key', 'sis.registrar')->firstOrFail();
        $registrarMembership->roles()->attach($registrarRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $guardianMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $guardian->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $guardianRole = Role::query()->where('tenant_id', $registrationTenant->getKey())->where('key', 'parent.guardian')->firstOrFail();
        $guardianMembership->roles()->attach($guardianRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $attendanceOwnerMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $attendanceOwner->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $attendanceOwnerMembership->roles()->attach($ownerRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $reviewerMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $schedulingReviewer->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $reviewerRole = Role::query()->where('tenant_id', $registrationTenant->getKey())->where('key', 'academic.admin')->firstOrFail();
        $reviewerMembership->roles()->attach($reviewerRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $controllerMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $examinationController->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $controllerRole = Role::query()->where('tenant_id', $registrationTenant->getKey())->where('key', 'exam.controller')->firstOrFail();
        $controllerMembership->roles()->attach($controllerRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $invigilatorMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $examinationInvigilator->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $examinationInvigilatorRole = Role::query()->where('tenant_id', $registrationTenant->getKey())->where('key', 'exam.invigilator')->firstOrFail();
        $invigilatorMembership->roles()->attach($examinationInvigilatorRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $studentMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $registrationStudent->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $studentRole = Role::query()->where('tenant_id', $registrationTenant->getKey())->where('key', 'student')->firstOrFail();
        $studentMembership->roles()->attach($studentRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $schedulingStudentMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $schedulingStudent->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $schedulingStudentMembership->roles()->attach($studentRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $waitlistMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $waitlistStudent->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $waitlistMembership->roles()->attach($studentRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $instructorMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $registrationInstructor->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $teacherRole = Role::query()->where('tenant_id', $registrationTenant->getKey())->where('key', 'teacher')->firstOrFail();
        $instructorMembership->roles()->attach($teacherRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $unassignedMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $unassignedInstructor->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $unassignedMembership->roles()->attach($teacherRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $schedulingInstructorMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $schedulingInstructor->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $schedulingInstructorMembership->roles()->attach($teacherRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $substituteInstructorMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $substituteInstructor->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $substituteInstructorMembership->roles()->attach($teacherRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $attendanceInstructorMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $attendanceInstructor->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $attendanceInstructorMembership->roles()->attach($teacherRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $invigilatorRole = Role::query()->where('tenant_id', $registrationTenant->getKey())->where('key', 'exam.invigilator')->firstOrFail();
        $attendanceInstructorMembership->roles()->attach($invigilatorRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $attendanceStudentMembership = TenantMembership::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $attendanceStudent->getKey(), 'status' => 'active', 'activated_at' => now()]);
        $attendanceStudentMembership->roles()->attach($studentRole->getKey(), ['assigned_by' => $registrationCreator->getKey(), 'tenant_id' => $registrationTenant->getKey()]);
        $year = AcademicYear::query()->create(['institution_id' => $institution->getKey(), 'name' => 'Current E2E Year', 'code' => 'E2E-YEAR', 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(8), 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
        $period = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Current E2E Term', 'code' => 'E2E-TERM', 'period_type' => 'term', 'sequence' => 1, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'status' => 'active']);
        $course = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'E2E-REG-101', 'title' => 'E2E Course Registration', 'status' => 'active']);
        $now = now();
        $courseVersionId = (int) DB::table('academic_course_versions')->insertGetId([
            'tenant_id' => $registrationTenant->getKey(), 'academic_course_id' => $course->getKey(), 'version_number' => 1,
            'title' => 'E2E Course Registration v1', 'credit_bearing' => false, 'theory_credits' => 0,
            'laboratory_credits' => 0, 'total_credits' => 0, 'delivery_modes' => json_encode(['online'], JSON_THROW_ON_ERROR),
            'status' => 'published', 'is_current' => true, 'created_by' => $registrationCreator->getKey(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $policy = EnrollmentPolicy::query()->create(['institution_id' => $institution->getKey(), 'name' => 'E2E Registration Policy', 'maximum_courses' => 5, 'waitlist_enabled' => true, 'require_withdrawal_approval' => false, 'waitlist_offer_hours' => 48, 'status' => 'active']);
        $offering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $course->getKey(), 'policy_id' => $policy->getKey(), 'offering_code' => 'E2E-REG-101-A', 'status' => 'open']);
        $section = CourseSection::query()->create(['offering_id' => $offering->getKey(), 'campus_id' => $registrationCampus->getKey(), 'section_code' => 'A', 'capacity' => 1, 'reserved_seats' => 0, 'status' => 'open']);
        $schedulingCourse = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'E2E-SCHED-201', 'title' => 'E2E Timetable Practice', 'status' => 'active']);
        $schedulingOffering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $schedulingCourse->getKey(), 'policy_id' => $policy->getKey(), 'offering_code' => 'E2E-SCHED-201-A', 'status' => 'closed']);
        $schedulingSection = CourseSection::query()->create(['offering_id' => $schedulingOffering->getKey(), 'campus_id' => $registrationCampus->getKey(), 'section_code' => 'SCHED', 'capacity' => 20, 'reserved_seats' => 0, 'status' => 'open']);
        DB::table('course_section_instructors')->insert(['tenant_id' => $registrationTenant->getKey(), 'section_id' => $section->getKey(), 'user_id' => $registrationInstructor->getKey(), 'role' => 'instructor', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('course_section_instructors')->insert(['tenant_id' => $registrationTenant->getKey(), 'section_id' => $schedulingSection->getKey(), 'user_id' => $schedulingInstructor->getKey(), 'role' => 'instructor', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('course_section_instructors')->insert(['tenant_id' => $registrationTenant->getKey(), 'section_id' => $schedulingSection->getKey(), 'user_id' => $substituteInstructor->getKey(), 'role' => 'instructor', 'created_at' => now(), 'updated_at' => now()]);
        $workspaceId = (int) DB::table('course_delivery_workspaces')->insertGetId([
            'tenant_id' => $registrationTenant->getKey(), 'institution_id' => $institution->getKey(),
            'academic_course_id' => $course->getKey(), 'academic_course_version_id' => $courseVersionId,
            'public_id' => (string) Str::uuid(), 'code' => 'E2E-REG-DELIVERY', 'title' => 'Enrollment linked learning workspace',
            'delivery_mode' => 'self_paced', 'status' => 'published', 'lock_version' => 1,
            'created_by' => $registrationOwner->getKey(), 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $moduleId = (int) DB::table('course_delivery_modules')->insertGetId([
            'tenant_id' => $registrationTenant->getKey(), 'workspace_id' => $workspaceId,
            'title' => 'Getting started', 'sort_order' => 1, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $lessonId = (int) DB::table('course_delivery_lessons')->insertGetId([
            'tenant_id' => $registrationTenant->getKey(), 'workspace_id' => $workspaceId, 'module_id' => $moduleId,
            'title' => 'Welcome to the course', 'summary' => 'A lesson available after registration.',
            'sort_order' => 1, 'status' => 'published', 'lock_version' => 1, 'created_by' => $registrationOwner->getKey(),
            'draft_author_user_id' => $registrationOwner->getKey(), 'submitted_by' => $registrationOwner->getKey(), 'submitted_at' => $now,
            'reviewer_user_id' => $registrationOwner->getKey(), 'review_decision' => 'approved', 'reviewed_at' => $now,
            'published_by' => $registrationOwner->getKey(), 'published_at' => $now, 'published_version_number' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $snapshot = ['title' => 'Welcome to the course', 'summary' => 'A lesson available after registration.', 'blocks' => [['type' => 'text', 'content' => ['text' => 'Your enrollment unlocks this course lesson.']]]];
        DB::table('course_delivery_lesson_revisions')->insert([
            'tenant_id' => $registrationTenant->getKey(), 'lesson_id' => $lessonId, 'version_number' => 1,
            'lesson_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'content_checksum' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'authored_by' => $registrationOwner->getKey(), 'reviewed_by' => $registrationOwner->getKey(),
            'published_by' => $registrationOwner->getKey(), 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $offering->forceFill(['delivery_workspace_id' => $workspaceId])->save();
        $registrationProfile = SisStudent::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $registrationStudent->getKey(), 'student_number' => 'E2E-'.$runId, 'registration_reference' => 'E2E-REF-'.$runId, 'legal_first_name' => 'Phase 08', 'legal_last_name' => 'Learner', 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
        SisStudentGuardian::query()->create([
            'student_id' => $registrationProfile->getKey(), 'guardian_user_id' => $guardian->getKey(),
            'guardian_name' => $guardian->name, 'guardian_email' => $guardian->email, 'relationship' => 'parent',
            'authority_status' => 'verified', 'can_view_profile' => true, 'verified_at' => now(),
            'verified_by' => $registrationOwner->getKey(),
        ]);
        $assignmentCourse = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'E2E-ASGN-'.$runId, 'title' => 'E2E Assignment Writing', 'status' => 'active']);
        $assignmentOffering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $registrationCampus->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'academic_course_id' => $assignmentCourse->getKey(), 'policy_id' => $policy->getKey(), 'offering_code' => 'E2E-ASGN-'.$runId, 'status' => 'open']);
        $assignmentSection = CourseSection::query()->create(['offering_id' => $assignmentOffering->getKey(), 'campus_id' => $registrationCampus->getKey(), 'section_code' => 'ASGN', 'capacity' => 20, 'reserved_seats' => 0, 'status' => 'open']);
        DB::table('course_section_instructors')->insert(['tenant_id' => $registrationTenant->getKey(), 'section_id' => $assignmentSection->getKey(), 'user_id' => $registrationInstructor->getKey(), 'role' => 'instructor', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('course_enrollments')->insert(['tenant_id' => $registrationTenant->getKey(), 'institution_id' => $institution->getKey(), 'student_id' => $registrationProfile->getKey(), 'offering_id' => $assignmentOffering->getKey(), 'section_id' => $assignmentSection->getKey(), 'status' => 'confirmed', 'registration_source' => 'registrar', 'requested_at' => now()->subDay(), 'confirmed_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $assessmentBank = QuizQuestionBank::query()->create([
            'institution_id' => $institution->getKey(), 'campus_id' => $registrationCampus->getKey(),
            'academic_course_id' => $assignmentCourse->getKey(), 'created_by' => $registrationInstructor->getKey(),
            'code' => 'E2E-ASSESS-'.$runId, 'name' => 'E2E Assessment Bank', 'sharing_scope' => 'campus', 'status' => 'active',
        ]);
        $assessmentQuestion = QuizQuestion::query()->create(['bank_id' => $assessmentBank->getKey(), 'status' => 'active', 'current_version' => 1, 'created_by' => $registrationInstructor->getKey()]);
        $assessmentQuestionVersion = QuizQuestionVersion::query()->create([
            'question_id' => $assessmentQuestion->getKey(), 'version_number' => 1, 'question_type' => 'long_response',
            'topic' => 'Reasoning', 'difficulty' => 'moderate', 'estimated_seconds' => 120, 'default_points' => '1.00',
            'content' => ['prompt' => 'Explain one reason this answer matters.'], 'answer_key' => null, 'scoring_rules' => [],
            'hints' => [], 'language' => 'en', 'status' => 'active', 'created_by' => $registrationInstructor->getKey(),
            'reviewed_by' => $schedulingReviewer->getKey(), 'reviewed_at' => now(), 'activated_at' => now(),
        ]);
        $assessmentQuiz = Quiz::query()->create([
            'institution_id' => $institution->getKey(), 'offering_id' => $assignmentOffering->getKey(),
            'created_by' => $registrationInstructor->getKey(), 'status' => 'published', 'current_version' => 1, 'published_at' => now(),
        ]);
        DB::table('quiz_sections')->insert(['tenant_id' => $registrationTenant->getKey(), 'quiz_id' => $assessmentQuiz->getKey(), 'section_id' => $assignmentSection->getKey(), 'created_at' => now(), 'updated_at' => now()]);
        $assessmentQuizVersion = QuizVersion::query()->create([
            'quiz_id' => $assessmentQuiz->getKey(), 'version_number' => 1, 'title' => 'E2E instructor-reviewed assessment',
            'category' => 'formative', 'mode' => 'graded', 'instructions' => 'Answer the prompt in your own words.',
            'settings' => ['available_at' => null, 'closes_at' => null, 'time_limit_seconds' => 600, 'maximum_attempts' => 2,
                'cooldown_minutes' => 0, 'grading_policy' => 'best', 'feedback_release' => 'manual',
                'result_visibility' => 'score_and_feedback', 'navigation' => 'free'],
            'total_points' => '1.00', 'status' => 'published', 'created_by' => $registrationInstructor->getKey(), 'published_at' => now(),
        ]);
        DB::table('quiz_version_questions')->insert([
            'tenant_id' => $registrationTenant->getKey(), 'quiz_version_id' => $assessmentQuizVersion->getKey(),
            'question_version_id' => $assessmentQuestionVersion->getKey(), 'position' => 1, 'points' => '1.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Keep the formal examination fixture distinct from the standalone
        // assessment fixture. Binding a quiz to an exam intentionally removes
        // it from the ordinary learner assessment list.
        $examQuiz = Quiz::query()->create([
            'institution_id' => $institution->getKey(), 'offering_id' => $assignmentOffering->getKey(),
            'created_by' => $registrationInstructor->getKey(), 'status' => 'published', 'current_version' => 1, 'published_at' => now(),
        ]);
        DB::table('quiz_sections')->insert(['tenant_id' => $registrationTenant->getKey(), 'quiz_id' => $examQuiz->getKey(), 'section_id' => $assignmentSection->getKey(), 'created_at' => now(), 'updated_at' => now()]);
        $examQuizVersion = QuizVersion::query()->create([
            'quiz_id' => $examQuiz->getKey(), 'version_number' => 1, 'title' => 'E2E formal exam delivery quiz',
            'category' => 'summative', 'mode' => 'graded', 'instructions' => 'Complete the formal examination response.',
            'settings' => ['available_at' => null, 'closes_at' => null, 'time_limit_seconds' => 3600, 'maximum_attempts' => 1,
                'cooldown_minutes' => 0, 'grading_policy' => 'latest', 'feedback_release' => 'manual',
                'result_visibility' => 'score_only', 'navigation' => 'sequential'],
            'total_points' => '1.00', 'status' => 'published', 'created_by' => $registrationInstructor->getKey(), 'published_at' => now(),
        ]);
        DB::table('quiz_version_questions')->insert([
            'tenant_id' => $registrationTenant->getKey(), 'quiz_version_id' => $examQuizVersion->getKey(),
            'question_version_id' => $assessmentQuestionVersion->getKey(), 'position' => 1, 'points' => '1.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $examStartsAt = now()->subMinutes(5);
        $examCycle = ExamCycle::query()->create([
            'institution_id' => $institution->getKey(), 'campus_id' => $registrationCampus->getKey(), 'academic_period_id' => $period->getKey(),
            'created_by' => $registrationOwner->getKey(), 'code' => 'E2E-EXAM-'.$runId, 'title' => 'E2E formal exam cycle',
            'category' => 'final', 'status' => 'published', 'starts_on' => today()->toDateString(), 'ends_on' => today()->toDateString(), 'published_at' => now(),
        ]);
        $examSession = ExamSession::query()->create([
            'cycle_id' => $examCycle->getKey(), 'campus_id' => $registrationCampus->getKey(), 'academic_course_id' => $assignmentCourse->getKey(),
            'created_by' => $registrationOwner->getKey(), 'code' => 'E2E-ONLINE-'.$runId, 'title' => 'E2E Online Examination',
            'mode' => 'online', 'status' => 'published', 'starts_at_utc' => $examStartsAt, 'ends_at_utc' => now()->addHours(2),
            'duration_minutes' => 60, 'capacity' => 30,
        ]);
        $examRoom = AcademicRoom::query()->create([
            'institution_id' => $institution->getKey(), 'campus_id' => $registrationCampus->getKey(), 'code' => 'E2E-EXAM-HALL-'.$runId,
            'name' => 'E2E Seating Hall', 'room_type' => 'examination', 'capacity' => 30, 'status' => 'active',
        ]);
        $seatingStartsAt = now()->addHours(3);
        $seatingSession = ExamSession::query()->create([
            'cycle_id' => $examCycle->getKey(), 'campus_id' => $registrationCampus->getKey(), 'academic_course_id' => $assignmentCourse->getKey(),
            'room_id' => $examRoom->getKey(), 'created_by' => $registrationOwner->getKey(), 'code' => 'E2E-SEATING-'.$runId,
            'title' => 'E2E In-person Examination', 'mode' => 'onsite', 'status' => 'published', 'starts_at_utc' => $seatingStartsAt,
            'ends_at_utc' => $seatingStartsAt->copy()->addHour(), 'duration_minutes' => 60, 'capacity' => 30,
        ]);
        $examCandidate = ExamCandidate::query()->create([
            'cycle_id' => $examCycle->getKey(), 'student_id' => $registrationProfile->getKey(), 'registered_by' => $registrationOwner->getKey(),
            'candidate_number' => 'E2E-CAND-'.$runId, 'status' => 'registered', 'registered_at' => now(),
        ]);
        $matrixComponent = ExamComponent::query()->create([
            'session_id' => $seatingSession->getKey(), 'component_type' => 'practical', 'code' => 'MATRIX-'.$runId,
            'title' => 'Phase 13 Theme Matrix Practical', 'instructions' => 'Complete the practical assessment using the retained rubric.',
            'room_id' => $examRoom->getKey(), 'starts_at_utc' => $seatingStartsAt, 'ends_at_utc' => $seatingStartsAt->copy()->addHour(),
            'maximum_marks' => '10.00', 'status' => 'published', 'second_marking_required' => false,
            'created_by' => $registrationOwner->getKey(),
        ]);
        ExamComponentRubric::query()->create([
            'component_id' => $matrixComponent->getKey(), 'criterion_key' => 'execution', 'title' => 'Safe execution',
            'description' => 'Completes the practical procedure safely.', 'maximum_marks' => '10.00', 'position' => 1,
        ]);
        ExamComponentAllocation::query()->create([
            'component_id' => $matrixComponent->getKey(), 'candidate_id' => $examCandidate->getKey(), 'marking_round' => 1,
            'examiner_id' => $registrationInstructor->getKey(), 'status' => 'assigned', 'assigned_by' => $registrationOwner->getKey(), 'assigned_at' => now(),
        ]);
        DB::table('exam_invigilator_assignments')->insert([
            'tenant_id' => $registrationTenant->getKey(), 'session_id' => $seatingSession->getKey(),
            'user_id' => $examinationInvigilator->getKey(), 'room_id' => $examRoom->getKey(), 'duty_role' => 'invigilator',
            'assigned_by' => $registrationOwner->getKey(), 'status' => 'assigned', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $examPaper = ExamPaper::query()->create(['session_id' => $examSession->getKey(), 'created_by' => $registrationOwner->getKey(), 'current_version' => 1, 'current_set_code' => 'A', 'status' => 'published', 'published_at' => now()]);
        $examPaperQuestions = [[
            'question_version_id' => (int) $assessmentQuestionVersion->getKey(), 'position' => 1, 'points' => '1.00',
            'question_type' => 'long_response', 'topic' => 'Reasoning', 'difficulty' => 'moderate', 'content' => $assessmentQuestionVersion->content,
        ]];
        $examPaperBlueprint = ['title' => 'E2E approved exam paper', 'instructions' => 'Complete your response and submit before time expires.', 'duration_minutes' => 60, 'question_count' => 1, 'set_code' => 'A'];
        $examPaperVersion = ExamPaperVersion::query()->create([
            'paper_id' => $examPaper->getKey(), 'version_number' => 1, 'set_code' => 'A', 'title' => $examPaperBlueprint['title'],
            'instructions' => $examPaperBlueprint['instructions'], 'blueprint_snapshot' => $examPaperBlueprint, 'question_snapshot' => $examPaperQuestions,
            'total_marks' => '1.00', 'duration_minutes' => 60,
            'content_hash' => hash('sha256', json_encode(['blueprint' => $examPaperBlueprint, 'questions' => $examPaperQuestions, 'total_marks' => '1.00'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'created_by' => $registrationOwner->getKey(), 'status' => 'published', 'approved_at' => now(), 'published_at' => now(),
        ]);
        $examResultPolicyId = DB::table('exam_result_policies')->insertGetId([
            'tenant_id' => $registrationTenant->getKey(), 'session_id' => $examSession->getKey(), 'version' => 1,
            'written_weight' => 100, 'pass_percent' => 50, 'recheck_window_days' => 30, 'effective_at' => now(),
            'configured_by' => $registrationOwner->getKey(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $examResultSnapshot = ['sources' => [['title' => 'Written paper', 'marks' => '8.00', 'maximum' => '10.00', 'weight' => '100.00']], 'total_marks' => '8.00', 'maximum_marks' => '10.00'];
        $examResultId = DB::table('exam_results')->insertGetId([
            'tenant_id' => $registrationTenant->getKey(), 'session_id' => $examSession->getKey(), 'candidate_id' => $examCandidate->getKey(),
            'policy_id' => $examResultPolicyId, 'version' => 1, 'status' => 'released', 'outcome' => 'pass',
            'total_marks' => '8.00', 'maximum_marks' => '10.00', 'percentage' => '80.00',
            'source_snapshot' => json_encode($examResultSnapshot, JSON_THROW_ON_ERROR), 'source_hash' => hash('sha256', json_encode($examResultSnapshot, JSON_THROW_ON_ERROR)),
            'policy_version' => '1', 'compiled_by' => $registrationOwner->getKey(), 'approved_by' => $registrationOwner->getKey(), 'approved_at' => now(),
            'released_by' => $registrationOwner->getKey(), 'released_at' => now(), 'recheck_until' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('exam_result_versions')->insert([
            'tenant_id' => $registrationTenant->getKey(), 'result_id' => $examResultId, 'version' => 1,
            'snapshot' => json_encode($examResultSnapshot, JSON_THROW_ON_ERROR), 'change_reason' => 'Synthetic isolated browser fixture',
            'created_by' => $registrationOwner->getKey(), 'created_at' => now(),
        ]);
        $examAppealId = DB::table('exam_recheck_requests')->insertGetId([
            'tenant_id' => $registrationTenant->getKey(), 'result_id' => $examResultId, 'result_version' => 1,
            'student_user_id' => $registrationStudent->getKey(), 'request_type' => 'appeal',
            'reason' => 'Synthetic browser appeal fixture for secure evidence testing.', 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $schedulingProfile = SisStudent::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $schedulingStudent->getKey(), 'student_number' => 'E2E-SCHED-'.$runId, 'registration_reference' => 'E2E-SCHED-REF-'.$runId, 'legal_first_name' => 'Phase 09', 'legal_last_name' => 'Learner', 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
        DB::table('course_enrollments')->insert(['tenant_id' => $registrationTenant->getKey(), 'institution_id' => $institution->getKey(), 'student_id' => $schedulingProfile->getKey(), 'offering_id' => $schedulingOffering->getKey(), 'section_id' => $schedulingSection->getKey(), 'status' => 'confirmed', 'registration_source' => 'student', 'requested_at' => now(), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        SisStudent::query()->create(['institution_id' => $institution->getKey(), 'user_id' => $waitlistStudent->getKey(), 'student_number' => 'E2E-WL-'.$runId, 'registration_reference' => 'E2E-WL-REF-'.$runId, 'legal_first_name' => 'Phase 08', 'legal_last_name' => 'Waitlist', 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
        $campusId = (int) $registrationCampus->getKey();
        if ($campusId) {
            $room = AcademicRoom::query()->create(['institution_id' => $institution->getKey(), 'campus_id' => $campusId, 'code' => 'E2E-SCHED', 'name' => 'E2E Scheduling Room', 'room_type' => 'classroom', 'capacity' => 30, 'status' => 'active']);
            $timetable = AcademicTimetable::query()->create(['institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $period->getKey(), 'campus_id' => $campusId, 'name' => 'E2E Published Timetable', 'version' => 1, 'status' => 'published', 'timezone' => 'UTC', 'independent_review_required' => false, 'created_by' => $registrationOwner->getKey(), 'published_by' => $registrationOwner->getKey(), 'published_at' => now()]);
            $occurrenceStart = today()->addDays(1);
            AcademicTimetableMeeting::query()->create(['timetable_id' => $timetable->getKey(), 'section_id' => $section->getKey(), 'instructor_user_id' => $registrationInstructor->getKey(), 'room_id' => $room->getKey(), 'starts_on' => $occurrenceStart->toDateString(), 'ends_on' => $occurrenceStart->copy()->addDays(14)->toDateString(), 'weekdays' => [$occurrenceStart->dayOfWeekIso], 'starts_at_local' => '10:00', 'duration_minutes' => 50, 'delivery_mode' => 'in_person', 'status' => 'scheduled', 'created_by' => $registrationOwner->getKey()]);

            $attendanceCourse = AcademicCourse::query()->create(['institution_id' => $institution->getKey(), 'code' => 'E2E-ATT-'.$runId, 'title' => 'E2E Attendance Biology', 'status' => 'active']);
            $attendancePeriod = AcademicPeriod::query()->create(['academic_year_id' => $year->getKey(), 'name' => 'Attendance E2E Term', 'code' => 'ATT-'.Str::upper($runId), 'period_type' => 'term', 'sequence' => 2, 'starts_on' => today()->subMonth(), 'ends_on' => today()->addMonths(5), 'status' => 'active']);
            $attendanceOffering = CourseOffering::query()->create(['institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $attendancePeriod->getKey(), 'academic_course_id' => $attendanceCourse->getKey(), 'policy_id' => $policy->getKey(), 'offering_code' => 'E2E-ATT-'.$runId, 'status' => 'open']);
            $attendanceSection = CourseSection::query()->create(['offering_id' => $attendanceOffering->getKey(), 'campus_id' => $registrationCampus->getKey(), 'section_code' => 'ATT', 'capacity' => 10, 'reserved_seats' => 0, 'status' => 'open']);
            DB::table('course_section_instructors')->insert(['tenant_id' => $registrationTenant->getKey(), 'section_id' => $attendanceSection->getKey(), 'user_id' => $attendanceInstructor->getKey(), 'role' => 'instructor', 'created_at' => now(), 'updated_at' => now()]);
            $attendanceProfile = SisStudent::query()->create(['institution_id' => $institution->getKey(), 'home_campus_id' => $registrationCampus->getKey(), 'user_id' => $attendanceStudent->getKey(), 'student_number' => 'E2E-ATT-'.$runId, 'registration_reference' => 'E2E-ATT-REF-'.$runId, 'legal_first_name' => 'Attendance', 'legal_last_name' => 'Learner', 'admission_date' => today()->subYear(), 'status' => 'active', 'student_type' => 'standard']);
            SisStudentGuardian::query()->create([
                'student_id' => $attendanceProfile->getKey(), 'guardian_user_id' => $guardian->getKey(),
                'guardian_name' => $guardian->name, 'guardian_email' => $guardian->email, 'relationship' => 'parent',
                'authority_status' => 'verified', 'can_view_profile' => true, 'verified_at' => now(),
                'verified_by' => $registrationOwner->getKey(),
            ]);
            DB::table('course_enrollments')->insert(['tenant_id' => $registrationTenant->getKey(), 'institution_id' => $institution->getKey(), 'student_id' => $attendanceProfile->getKey(), 'offering_id' => $attendanceOffering->getKey(), 'section_id' => $attendanceSection->getKey(), 'status' => 'confirmed', 'registration_source' => 'registrar', 'requested_at' => now(), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $attendanceTimetable = AcademicTimetable::query()->create(['institution_id' => $institution->getKey(), 'academic_year_id' => $year->getKey(), 'academic_period_id' => $attendancePeriod->getKey(), 'campus_id' => $campusId, 'name' => 'E2E Attendance Timetable', 'version' => 1, 'status' => 'published', 'timezone' => 'UTC', 'independent_review_required' => true, 'created_by' => $registrationOwner->getKey(), 'published_by' => $registrationOwner->getKey(), 'published_at' => now()]);
            $attendanceStart = today()->addDays(1);
            AcademicTimetableMeeting::query()->create(['timetable_id' => $attendanceTimetable->getKey(), 'section_id' => $attendanceSection->getKey(), 'instructor_user_id' => $attendanceInstructor->getKey(), 'room_id' => $room->getKey(), 'starts_on' => $attendanceStart->toDateString(), 'ends_on' => $attendanceStart->toDateString(), 'weekdays' => [$attendanceStart->dayOfWeekIso], 'starts_at_local' => '10:00', 'duration_minutes' => 50, 'delivery_mode' => 'in_person', 'status' => 'scheduled', 'created_by' => $registrationOwner->getKey()]);
        }
    } finally {
        $registrationContext->clear();
    }

    echo json_encode([
        'adminEmail' => $adminEmail,
        'adminPassword' => $adminPassword,
        'applicantEmail' => $applicantEmail,
        'applicantPassword' => $applicantPassword,
        'guardianEmail' => $guardianEmail,
        'guardianPassword' => $guardianPassword,
        'tenantSlug' => 'phase02-e2e-'.$runId,
        'registrationTenantSlug' => $registrationTenant->slug,
        'registrationOwnerEmail' => $registrationOwnerEmail,
        'registrationOwnerPassword' => $registrationOwnerPassword,
        'campusAdminEmail' => $campusAdminEmail,
        'campusAdminPassword' => $campusAdminPassword,
        'admissionsOfficerEmail' => $admissionsOfficerEmail,
        'admissionsOfficerPassword' => $admissionsOfficerPassword,
        'registrarEmail' => $registrarEmail,
        'registrarPassword' => $registrarPassword,
        'schedulingReviewerEmail' => $schedulingReviewerEmail,
        'schedulingReviewerPassword' => $schedulingReviewerPassword,
        'examinationControllerEmail' => $examinationControllerEmail,
        'examinationControllerPassword' => $examinationControllerPassword,
        'examinationInvigilatorEmail' => $examinationInvigilatorEmail,
        'examinationInvigilatorPassword' => $examinationInvigilatorPassword,
        'registrationInstructorEmail' => $registrationInstructorEmail,
        'registrationInstructorPassword' => $registrationInstructorPassword,
        'unassignedInstructorEmail' => $unassignedInstructorEmail,
        'unassignedInstructorPassword' => $unassignedInstructorPassword,
        'registrationInstructorUserId' => (int) $registrationInstructor->getKey(),
        'registrationWorkspaceId' => $workspaceId,
        'registrationLessonId' => $lessonId,
        'registrationSectionId' => (int) $section->getKey(),
        'phase14AcademicPeriodId' => (int) $period->getKey(),
        'assignmentOfferingId' => (int) $assignmentOffering->getKey(),
        'assignmentSectionId' => (int) $assignmentSection->getKey(),
        'phase14SectionId' => (int) $assignmentSection->getKey(),
        'assessmentQuizId' => (int) $assessmentQuiz->getKey(),
        'examQuizId' => (int) $examQuiz->getKey(),
        'examSessionId' => (int) $examSession->getKey(),
        'examSeatingSessionId' => (int) $seatingSession->getKey(),
        'examPaperVersionId' => (int) $examPaperVersion->getKey(),
        'examCandidateId' => (int) $examCandidate->getKey(),
        'examAppealId' => (int) $examAppealId,
        'registrationStudentEmail' => $registrationStudentEmail,
        'registrationStudentPassword' => $registrationStudentPassword,
        'schedulingStudentEmail' => $schedulingStudentEmail,
        'schedulingStudentPassword' => $schedulingStudentPassword,
        'schedulingSectionId' => (int) $schedulingSection->getKey(),
        'waitlistStudentEmail' => $waitlistStudentEmail,
        'waitlistStudentPassword' => $waitlistStudentPassword,
        'attendanceOwnerEmail' => $attendanceOwnerEmail,
        'attendanceOwnerPassword' => $attendanceOwnerPassword,
        'attendanceInstructorEmail' => $attendanceInstructorEmail,
        'attendanceInstructorPassword' => $attendanceInstructorPassword,
        'attendanceStudentEmail' => $attendanceStudentEmail,
        'attendanceStudentPassword' => $attendanceStudentPassword,
        'attendanceDate' => today()->addDays(1)->toDateString(),
        'runId' => $runId,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Browser test setup failed: '.$exception->getMessage().PHP_EOL);
    exit(2);
}
