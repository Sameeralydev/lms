<?php

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantProvisioner;
use App\Models\Institution;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

final class Phase13ExaminationRouteAuthorizationMatrixTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_fixed_authorized_route_manifest_matches_the_complete_live_inventory(): void
    {
        $manifestPath = base_path('docs/phase-reports/phase-13/PHASE13_AUTHORIZED_ROUTE_MANIFEST.json');
        self::assertFileExists($manifestPath);
        $manifestJson = (string) file_get_contents($manifestPath);
        $manifestJson = preg_replace('/^\xEF\xBB\xBF/', '', $manifestJson) ?? $manifestJson;
        $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ! is_array($manifest['routeRows'] ?? null)) {
            throw new \RuntimeException('The fixed authorized route manifest is malformed.');
        }
        self::assertSame(73, $manifest['routeCount'] ?? null);
        self::assertCount(73, $manifest['routeRows']);

        $liveRoutes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'tenant.academics.examinations.')
                || str_starts_with((string) $route->getName(), 'tenant.exams.'))
            ->keyBy(fn (Route $route): string => (string) $route->getName());
        $manifestRows = [];
        foreach ($manifest['routeRows'] as $manifestRow) {
            if (! is_array($manifestRow) || ! is_string($manifestRow['routeName'] ?? null)) {
                throw new \RuntimeException('The fixed authorized route manifest contains a malformed row.');
            }
            $manifestRows[$manifestRow['routeName']] = $manifestRow;
        }

        self::assertCount(73, $liveRoutes);
        self::assertSame([], array_values(array_diff($liveRoutes->keys()->all(), array_keys($manifestRows))), 'A live Phase 13 route is missing from the fixed authorization manifest.');
        self::assertSame([], array_values(array_diff(array_keys($manifestRows), $liveRoutes->keys()->all())), 'The fixed authorization manifest contains a route no longer registered.');

        $verifiedPositiveEvidence = [
            'tenant.academics.examinations.index' => 'tests/Feature/ExaminationOperationsTest.php::test_institution_owner_can_create_review_and_publish_a_cycle_and_register_eligible_students_idempotently',
            'tenant.academics.examinations.accommodation-requests.decision' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_student_requests_receive_independent_review_and_admit_documents_are_private_revocable_and_verifiable',
            'tenant.academics.examinations.admit-documents.issue' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_student_requests_receive_independent_review_and_admit_documents_are_private_revocable_and_verifiable',
            'tenant.academics.examinations.admit-documents.revoke' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_student_requests_receive_independent_review_and_admit_documents_are_private_revocable_and_verifiable',
            'tenant.academics.examinations.appeals.transition' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.academics.examinations.appeals.evidence.store-review' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.academics.examinations.candidate-governance' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_student_requests_receive_independent_review_and_admit_documents_are_private_revocable_and_verifiable',
            'tenant.academics.examinations.candidate-governance.policy' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_student_requests_receive_independent_review_and_admit_documents_are_private_revocable_and_verifiable',
            'tenant.academics.examinations.components.allocations.store' => 'tests/Feature/ExaminationOperationsTest.php::test_practical_component_supports_independent_marking_moderation_and_immutable_result_versions',
            'tenant.academics.examinations.marking.moderate' => 'tests/Feature/ExaminationOperationsTest.php::test_practical_component_supports_independent_marking_moderation_and_immutable_result_versions',
            'tenant.academics.examinations.components.examiners.assign' => 'tests/Feature/ExaminationOperationsTest.php::test_practical_component_supports_independent_marking_moderation_and_immutable_result_versions',
            'tenant.academics.examinations.components.publish' => 'tests/Feature/ExaminationOperationsTest.php::test_practical_component_supports_independent_marking_moderation_and_immutable_result_versions',
            'tenant.academics.examinations.cycles.store' => 'tests/Feature/ExaminationOperationsTest.php::test_campus_scoped_administrator_can_transition_same_campus_cycle_but_not_foreign_campus_cycle',
            'tenant.academics.examinations.candidates.store' => 'tests/Feature/ExaminationOperationsTest.php::test_institution_owner_can_create_review_and_publish_a_cycle_and_register_eligible_students_idempotently',
            'tenant.academics.examinations.candidates.accommodations.store' => 'tests/Feature/ExaminationOperationsTest.php::test_invigilator_assignments_attendance_revisions_and_confidential_accommodations_are_scoped_and_audited',
            'tenant.academics.examinations.date-sheets.revisions.store' => 'tests/Feature/ExaminationOperationsTest.php::test_date_sheet_revision_requires_independent_review_and_preserves_published_snapshot',
            'tenant.academics.examinations.sessions.store' => 'tests/Feature/ExaminationOperationsTest.php::test_institution_owner_can_create_review_and_publish_a_cycle_and_register_eligible_students_idempotently',
            'tenant.academics.examinations.cycles.status' => 'tests/Feature/ExaminationOperationsTest.php::test_campus_scoped_administrator_can_transition_same_campus_cycle_but_not_foreign_campus_cycle',
            'tenant.academics.examinations.date-sheets.revisions.decision' => 'tests/Feature/ExaminationOperationsTest.php::test_date_sheet_revision_requires_independent_review_and_preserves_published_snapshot',
            'tenant.academics.examinations.date-sheets.revisions.publish' => 'tests/Feature/ExaminationOperationsTest.php::test_date_sheet_revision_requires_independent_review_and_preserves_published_snapshot',
            'tenant.academics.examinations.date-sheets.revisions.submit' => 'tests/Feature/ExaminationOperationsTest.php::test_date_sheet_revision_requires_independent_review_and_preserves_published_snapshot',
            'tenant.academics.examinations.eligibility-exceptions.decision' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_eligibility_exception_is_limited_to_a_failed_allowed_rule_and_requires_expiring_independent_decision',
            'tenant.academics.examinations.eligibility-exceptions.revoke' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_eligibility_exception_is_limited_to_a_failed_allowed_rule_and_requires_expiring_independent_decision',
            'tenant.academics.examinations.incidents.evidence.download' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_incident_evidence_stays_quarantined_until_clean_scan_and_is_private_tenant_scoped_and_audited',
            'tenant.academics.examinations.incidents.store' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_incident_evidence_stays_quarantined_until_clean_scan_and_is_private_tenant_scoped_and_audited',
            'tenant.academics.examinations.incidents.evidence.upload' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_incident_evidence_stays_quarantined_until_clean_scan_and_is_private_tenant_scoped_and_audited',
            'tenant.academics.examinations.incidents.review' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_incident_evidence_stays_quarantined_until_clean_scan_and_is_private_tenant_scoped_and_audited',
            'tenant.academics.examinations.incidents.transition' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_incident_assignment_escalation_investigation_closure_and_reopening_are_authorized_and_immutable',
            'tenant.academics.examinations.invigilators.respond' => 'tests/Feature/ExaminationOperationsTest.php::test_invigilator_assignments_attendance_revisions_and_confidential_accommodations_are_scoped_and_audited',
            'tenant.academics.examinations.invigilators.assign' => 'tests/Feature/ExaminationOperationsTest.php::test_invigilator_assignments_attendance_revisions_and_confidential_accommodations_are_scoped_and_audited',
            'tenant.academics.examinations.marking.index' => 'tests/Feature/ExaminationOperationsTest.php::test_practical_component_supports_independent_marking_moderation_and_immutable_result_versions',
            'tenant.academics.examinations.marking.draft' => 'tests/Feature/ExaminationOperationsTest.php::test_practical_component_supports_independent_marking_moderation_and_immutable_result_versions',
            'tenant.academics.examinations.marking.submit' => 'tests/Feature/ExaminationOperationsTest.php::test_practical_component_supports_independent_marking_moderation_and_immutable_result_versions',
            'tenant.academics.examinations.marking.approve' => 'tests/Feature/ExaminationOperationsTest.php::test_practical_component_supports_independent_marking_moderation_and_immutable_result_versions',
            'tenant.academics.examinations.operations.export' => 'tests/Feature/ExaminationOperationsTest.php::test_result_policy_rejects_non_session_components_and_student_result_is_not_visible_before_release',
            'tenant.academics.examinations.papers.preview' => 'tests/Feature/ExaminationOperationsTest.php::test_exam_paper_is_an_encrypted_immutable_snapshot_with_independent_review_and_publication',
            'tenant.academics.examinations.papers.publish' => 'tests/Feature/ExaminationOperationsTest.php::test_exam_paper_is_an_encrypted_immutable_snapshot_with_independent_review_and_publication',
            'tenant.academics.examinations.papers.review' => 'tests/Feature/ExaminationOperationsTest.php::test_exam_paper_is_an_encrypted_immutable_snapshot_with_independent_review_and_publication',
            'tenant.academics.examinations.papers.submit-review' => 'tests/Feature/ExaminationOperationsTest.php::test_exam_paper_is_an_encrypted_immutable_snapshot_with_independent_review_and_publication',
            'tenant.academics.examinations.rechecks.approve-amendment' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.academics.examinations.rechecks.assign' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.academics.examinations.rechecks.decision' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.academics.examinations.results.index' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.academics.examinations.results.export' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.academics.examinations.results.approve' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.academics.examinations.results.grade-contract' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.academics.examinations.results.release' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.academics.examinations.scripts.download' => 'tests/Feature/ExaminationOperationsTest.php::test_answer_scripts_are_quarantined_scanned_and_authorized_download_is_private',
            'tenant.academics.examinations.seating.decision' => 'tests/Feature/ExaminationOperationsTest.php::test_seating_plan_is_capacity_checked_independently_reviewed_and_published_as_immutable_history',
            'tenant.academics.examinations.seating.publish' => 'tests/Feature/ExaminationOperationsTest.php::test_seating_plan_is_capacity_checked_independently_reviewed_and_published_as_immutable_history',
            'tenant.academics.examinations.seating.submit' => 'tests/Feature/ExaminationOperationsTest.php::test_seating_plan_is_capacity_checked_independently_reviewed_and_published_as_immutable_history',
            'tenant.academics.examinations.attendance.store' => 'tests/Feature/ExaminationOperationsTest.php::test_invigilator_assignments_attendance_revisions_and_confidential_accommodations_are_scoped_and_audited',
            'tenant.academics.examinations.components.store' => 'tests/Feature/ExaminationOperationsTest.php::test_practical_component_supports_independent_marking_moderation_and_immutable_result_versions',
            'tenant.academics.examinations.papers.store' => 'tests/Feature/ExaminationOperationsTest.php::test_exam_paper_is_an_encrypted_immutable_snapshot_with_independent_review_and_publication',
            'tenant.academics.examinations.results.policy.update' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.academics.examinations.results.compile' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.academics.examinations.scripts.upload' => 'tests/Feature/ExaminationOperationsTest.php::test_answer_scripts_are_quarantined_scanned_and_authorized_download_is_private',
            'tenant.academics.examinations.seating.store' => 'tests/Feature/ExaminationOperationsTest.php::test_seating_plan_is_capacity_checked_independently_reviewed_and_published_as_immutable_history',
            'tenant.academics.examinations.seats.store' => 'tests/Feature/ExaminationOperationsTest.php::test_institution_owner_can_create_review_and_publish_a_cycle_and_register_eligible_students_idempotently',
            'tenant.exams.appeals.evidence.download' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.exams.appeals.evidence.store' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.exams.invigilators.mine' => 'tests/e2e/examination-operations.spec.ts::exam controller builds a campus-scoped seating plan for review and independent publication',
            'tenant.exams.invigilators.respond-own' => 'tests/e2e/examination-operations.spec.ts::exam controller builds a campus-scoped seating plan for review and independent publication',
            'tenant.exams.student.governance' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_student_requests_receive_independent_review_and_admit_documents_are_private_revocable_and_verifiable',
            'tenant.exams.student.admit.show' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_student_requests_receive_independent_review_and_admit_documents_are_private_revocable_and_verifiable',
            'tenant.exams.student.accommodations.request' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_student_requests_receive_independent_review_and_admit_documents_are_private_revocable_and_verifiable',
            'tenant.exams.student.eligibility-exceptions.request' => 'tests/Feature/ExaminationCandidateGovernanceTest.php::test_eligibility_exception_is_limited_to_a_failed_allowed_rule_and_requires_expiring_independent_decision',
            'tenant.exams.results.mine' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.exams.results.rechecks.store' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.exams.student.schedule' => 'tests/Feature/ExaminationOperationsTest.php::test_examination_results_are_compiled_approved_and_released_with_student_ownership_and_audit',
            'tenant.exams.delivery.index' => 'tests/e2e/examination-delivery.spec.ts::controller binds a published exam and student completes a recoverable online attempt',
            'tenant.exams.delivery.start' => 'tests/e2e/examination-delivery.spec.ts::controller binds a published exam and student completes a recoverable online attempt',
            'tenant.academics.examinations.delivery.configure' => 'tests/e2e/examination-delivery.spec.ts::controller binds a published exam and student completes a recoverable online attempt',
        ];
        $verifiedCampusEvidence = [
            'tenant.academics.examinations.cycles.store' => $verifiedPositiveEvidence['tenant.academics.examinations.cycles.store'],
            'tenant.academics.examinations.cycles.status' => $verifiedPositiveEvidence['tenant.academics.examinations.cycles.status'],
        ];

        foreach ($liveRoutes as $name => $route) {
            $row = $manifestRows[$name];
            self::assertSame(implode('|', $route->methods()), $row['method'], "Method drift for {$name}.");
            self::assertSame($route->getActionName(), $row['action'], "Action drift for {$name}.");
            self::assertSame($this->permissionMiddleware($route->gatherMiddleware()), $row['requiredPermissions'], "Permission drift for {$name}.");
            self::assertNotEmpty($row['validFixturePlan']['group'], "Missing lifecycle fixture group for {$name}.");
            self::assertNotEmpty($row['expectedResponse'], "Missing successful response contract for {$name}.");
            self::assertNotEmpty($row['expectedDatabaseEffect'], "Missing persistence assertion contract for {$name}.");
            if (isset($verifiedPositiveEvidence[$name])) {
                self::assertSame('WORKFLOW_EVIDENCE_LINKED', $row['positiveExecutionStatus'], "A referenced authorized workflow test is missing for {$name}.");
                self::assertSame($verifiedPositiveEvidence[$name], $row['validFixturePlan']['actualFixture'], "Positive evidence must name the executable fixture test for {$name}.");
            } else {
                self::assertSame('NOT_VERIFIED_ROUTE_BY_ROUTE', $row['positiveExecutionStatus'], "The manifest must not silently promote unexecuted evidence for {$name}.");
                self::assertSame('NOT_BOUND_TO_EXECUTABLE_POSITIVE_CASE', $row['validFixturePlan']['actualFixture'], "Unverified route fixtures must remain explicitly unbound for {$name}.");
            }

            if (isset($verifiedCampusEvidence[$name])) {
                self::assertSame('PASS', $row['campus']['status'], "Same/foreign campus evidence must be bound for {$name}.");
                self::assertSame($verifiedCampusEvidence[$name], $row['campus']['evidence'], "Campus evidence reference changed for {$name}.");
            }

            if ($name === 'tenant.academics.examinations.papers.store') {
                self::assertSame('PASS', $row['ownership']['status'], 'The foreign-owned question rejection must be recorded on the paper-store route.');
                self::assertSame('tests/Feature/ExaminationOperationsTest.php::test_exam_paper_rejects_questions_from_another_institution', $row['ownership']['evidence']);
            }
        }
    }

    public function test_every_registered_examination_and_appeal_route_denies_guest_unprivileged_foreign_and_revoked_members(): void
    {
        [$tenantA, $unprivileged, $unauthorizedRole, $revoked, $tenantB] = $this->authorizationFixture();
        $routes = collect(RouteFacade::getRoutes()->getRoutes())->filter(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'tenant.academics.examinations.')
            || str_starts_with((string) $route->getName(), 'tenant.exams.')
        )->values();

        self::assertCount(73, $routes, 'The Phase 13 route inventory changed; update and review the route matrix.');

        $matrixPath = base_path('docs/phase-reports/phase-13/EXAMINATION_AUTHORIZATION_MATRIX.json');
        self::assertFileExists($matrixPath, 'The reviewed Phase 13 route acceptance matrix is missing.');
        $matrixJson = (string) file_get_contents($matrixPath);
        $matrixJson = preg_replace('/^\xEF\xBB\xBF/', '', $matrixJson) ?? $matrixJson;
        $matrix = $this->decodeRouteMatrix($matrixJson);
        self::assertSame(73, $matrix['routeCount'], 'The reviewed route matrix must enumerate all 73 examination routes.');
        $matrixRoutes = $matrix['routes'];
        self::assertCount(73, $matrixRoutes, 'The reviewed matrix must contain one row per unique live route.');

        foreach ($routes as $route) {
            $name = (string) $route->getName();
            $row = $matrixRoutes[$name] ?? null;
            self::assertNotNull($row, "The live route {$name} is missing from the reviewed matrix.");
            self::assertSame(implode('|', $route->methods()), $row['method'], "Method changed for {$name}; review the matrix.");
            // `route:list --json` intentionally omits the explicit slug binding
            // constraint from `{tenant:slug}`; compare its canonical display form.
            $canonicalUri = preg_replace('/\{tenant:[^}]+\}/', '{tenant}', $route->uri());
            $matrixUri = preg_replace('/\{tenant:[^}]+\}/', '{tenant}', $row['uri']);
            self::assertSame($canonicalUri, $matrixUri, "URI changed for {$name}; review the matrix.");
            self::assertSame($route->getActionName(), $row['action'], "Controller action changed for {$name}; review the matrix.");

            $livePermissions = $this->permissionMiddleware($route->gatherMiddleware());
            $recordedPermissions = $this->permissionMiddleware($row['middleware']);
            self::assertSame($livePermissions, $recordedPermissions, "Permission middleware changed for {$name}; review the matrix.");
            self::assertSame($livePermissions, $row['requiredPermissions'], "Required permissions are not frozen correctly for {$name}.");
            self::assertArrayHasKey('positiveWorkflowEvidence', $row, "Positive workflow evidence disposition is missing for {$name}.");
            self::assertArrayHasKey('campusResourceEvidence', $row, "Campus/resource evidence disposition is missing for {$name}.");
            self::assertSame('PASS', $row['negativeRoleTest']['status'], "Negative role result is missing for {$name}.");
            self::assertSame('PASS', $row['wrongTenantTest']['status'], "Wrong-tenant result is missing for {$name}.");
            self::assertContains($row['wrongCampusTest']['status'], ['PASS', 'PARTIAL', 'N/A'], "Wrong-campus disposition is invalid for {$name}.");
            self::assertContains($row['foreignResourceTest']['status'], ['PASS', 'PARTIAL', 'N/A'], "Foreign-resource disposition is invalid for {$name}.");
            self::assertNotEmpty($row['campusPolicy']);
            self::assertNotEmpty($row['resourceOwner']);
            self::assertNotEmpty($row['expectedState']);
            self::assertStringStartsWith('PARTIAL:', $row['verifiedResult']);
        }

        $liveRouteNames = $routes->map(fn (Route $route): string => (string) $route->getName())->all();
        self::assertSame([], array_values(array_diff(array_keys($matrixRoutes), $liveRouteNames)), 'The matrix contains routes that are no longer in the live inventory.');

        $mutationSentinels = [
            'exam_cycles' => DB::table('exam_cycles')->count(),
            'exam_candidates' => DB::table('exam_candidates')->count(),
            'audit_events' => DB::table('audit_events')->count(),
        ];
        $cases = 0;
        foreach ($routes as $route) {
            $method = $this->requestMethod($route);
            $pathA = $this->pathFor($route, $tenantA);
            $pathB = $this->pathFor($route, $tenantB);

            // Requests traverse the real router and middleware stack. A denial may
            // be auth redirect or an authorization/not-found response, never success.
            $this->app['auth']->forgetGuards();
            $guest = $this->call($method, $pathA);
            self::assertSame(302, $guest->getStatusCode(), "Guest unexpectedly reached {$route->getName()}.");
            $cases++;

            $this->actingAs($unprivileged);
            $denied = $this->call($method, $pathA);
            self::assertContains($denied->getStatusCode(), [403, 404], "Unprivileged tenant member unexpectedly reached {$route->getName()} (HTTP {$denied->getStatusCode()}).");
            $cases++;

            $this->actingAs($unauthorizedRole);
            $wrongRoleResponse = $this->call($method, $pathA);
            self::assertContains($wrongRoleResponse->getStatusCode(), [403, 404], "A role without examination permissions unexpectedly reached {$route->getName()} (HTTP {$wrongRoleResponse->getStatusCode()}).");
            $cases++;

            $foreign = $this->call($method, $pathB);
            self::assertSame(404, $foreign->getStatusCode(), "Foreign-tenant member unexpectedly reached {$route->getName()} (HTTP {$foreign->getStatusCode()}).");
            $cases++;

            $this->actingAs($revoked);
            $revokedResponse = $this->call($method, $pathA);
            self::assertSame(404, $revokedResponse->getStatusCode(), "Revoked member unexpectedly reached {$route->getName()} (HTTP {$revokedResponse->getStatusCode()}).");
            $cases++;
        }

        self::assertSame(365, $cases);
        foreach ($mutationSentinels as $table => $count) {
            self::assertSame($count, DB::table($table)->count(), "Denied requests changed {$table}.");
        }
    }

    /** @return array{Tenant, User, User, User, Tenant} */
    private function authorizationFixture(): array
    {
        $creator = User::factory()->create();
        $ownerA = User::factory()->create();
        $tenantA = $this->provisionTenant($creator, $ownerA, 'matrix-a');
        $ownerB = User::factory()->create();
        $tenantB = $this->provisionTenant($creator, $ownerB, 'matrix-b');
        $unprivileged = User::factory()->create();
        $unauthorizedRole = User::factory()->create();
        $revoked = User::factory()->create();

        app(TenantContext::class)->enterProvisioning($tenantA);
        try {
            $institution = Institution::query()->firstOrFail();
            TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $unprivileged->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $guardianMembership = TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $unauthorizedRole->getKey(),
                'status' => 'active', 'activated_at' => now(),
            ]);
            $guardianRole = Role::query()->where('tenant_id', $tenantA->getKey())->where('key', 'parent.guardian')->firstOrFail();
            $guardianMembership->roles()->attach($guardianRole->getKey(), ['assigned_by' => $creator->getKey(), 'tenant_id' => $tenantA->getKey()]);
            TenantMembership::query()->create([
                'institution_id' => $institution->getKey(), 'user_id' => $revoked->getKey(),
                'status' => 'revoked', 'activated_at' => now(),
            ]);
        } finally {
            app(TenantContext::class)->clear();
        }

        return [$tenantA, $unprivileged, $unauthorizedRole, $revoked, $tenantB];
    }

    private function provisionTenant(User $creator, User $owner, string $suffix): Tenant
    {
        $label = 'Phase 13 Matrix '.Str::random(8);

        return app(TenantProvisioner::class)->provision([
            'slug' => $suffix.'-'.Str::lower(Str::random(8)), 'official_name' => $label, 'display_name' => $label,
            'code' => '', 'type' => 'academy', 'contact_email' => '', 'contact_phone' => '', 'website' => '',
            'country' => '', 'region' => '', 'city' => '', 'address' => '', 'timezone' => 'UTC', 'locale' => 'en',
        ], $creator, $owner->email)->tenant;
    }

    private function requestMethod(Route $route): string
    {
        foreach ($route->methods() as $method) {
            if (! in_array($method, ['HEAD', 'OPTIONS'], true)) {
                return $method;
            }
        }

        return 'GET';
    }

    /** @return array{routeCount:int,routes:array<string,array{name:string,method:string,uri:string,action:string,middleware:list<string>,requiredPermissions:list<string>,positiveWorkflowEvidence:array<string,mixed>,campusResourceEvidence:array<string,mixed>,campusPolicy:array<string,mixed>,resourceOwner:array<string,mixed>,expectedState:array<string,mixed>,negativeRoleTest:array{status:string},wrongTenantTest:array{status:string},wrongCampusTest:array{status:string},foreignResourceTest:array{status:string},verifiedResult:string}>} */
    private function decodeRouteMatrix(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! is_int($decoded['routeCount'] ?? null) || ! is_array($decoded['routes'] ?? null)) {
            throw new \RuntimeException('The Phase 13 authorization matrix has an invalid top-level shape.');
        }

        $routes = [];
        foreach ($decoded['routes'] as $route) {
            if (! is_array($route)
                || ! is_string($route['name'] ?? null)
                || ! is_string($route['method'] ?? null)
                || ! is_string($route['uri'] ?? null)
                || ! is_string($route['action'] ?? null)
                || ! is_array($route['middleware'] ?? null)
                || ! is_array($route['requiredPermissions'] ?? null)
                || ! is_array($route['positiveWorkflowEvidence'] ?? null)
                || ! is_array($route['campusResourceEvidence'] ?? null)
                || ! is_array($route['campusPolicy'] ?? null)
                || ! is_array($route['resourceOwner'] ?? null)
                || ! is_array($route['expectedState'] ?? null)
                || ! is_array($route['negativeRoleTest'] ?? null)
                || ! is_array($route['wrongTenantTest'] ?? null)
                || ! is_array($route['wrongCampusTest'] ?? null)
                || ! is_array($route['foreignResourceTest'] ?? null)
                || ! is_string($route['verifiedResult'] ?? null)
                || ! is_string($route['negativeRoleTest']['status'] ?? null)
                || ! is_string($route['wrongTenantTest']['status'] ?? null)
                || ! is_string($route['wrongCampusTest']['status'] ?? null)
                || ! is_string($route['foreignResourceTest']['status'] ?? null)) {
                throw new \RuntimeException('A Phase 13 authorization matrix route row is incomplete or malformed.');
            }

            foreach ($route['middleware'] as $middleware) {
                if (! is_string($middleware)) {
                    throw new \RuntimeException('A route matrix middleware value must be a string.');
                }
            }
            foreach ($route['requiredPermissions'] as $permission) {
                if (! is_string($permission)) {
                    throw new \RuntimeException('A route matrix permission value must be a string.');
                }
            }

            $routes[$route['name']] = [
                'name' => $route['name'],
                'method' => $route['method'],
                'uri' => $route['uri'],
                'action' => $route['action'],
                'middleware' => array_values($route['middleware']),
                'requiredPermissions' => array_values($route['requiredPermissions']),
                'positiveWorkflowEvidence' => $route['positiveWorkflowEvidence'],
                'campusResourceEvidence' => $route['campusResourceEvidence'],
                'campusPolicy' => $route['campusPolicy'],
                'resourceOwner' => $route['resourceOwner'],
                'expectedState' => $route['expectedState'],
                'negativeRoleTest' => ['status' => $route['negativeRoleTest']['status']],
                'wrongTenantTest' => ['status' => $route['wrongTenantTest']['status']],
                'wrongCampusTest' => ['status' => $route['wrongCampusTest']['status']],
                'foreignResourceTest' => ['status' => $route['foreignResourceTest']['status']],
                'verifiedResult' => $route['verifiedResult'],
            ];
        }

        return ['routeCount' => $decoded['routeCount'], 'routes' => $routes];
    }

    /** @param list<string> $middleware
     * @return list<string>
     */
    private function permissionMiddleware(array $middleware): array
    {
        $permissions = [];
        foreach ($middleware as $entry) {
            if (str_starts_with($entry, 'tenant.permission:')) {
                $permissions[] = substr($entry, strlen('tenant.permission:'));
            }
        }
        sort($permissions);

        return $permissions;
    }

    private function pathFor(Route $route, Tenant $tenant): string
    {
        $uri = preg_replace_callback('/\{([^}:?]+)(?::[^}?]+)?(\?)?\}/', function (array $match) use ($route, $tenant): string {
            $parameter = $match[1];
            if ($parameter === 'tenant') {
                return $tenant->slug;
            }

            $constraint = $route->wheres[$parameter] ?? '';
            if (str_contains($constraint, 'uuid') || str_contains(strtolower($constraint), '[0-9a-f]')) {
                return (string) Str::uuid();
            }

            if (str_contains($constraint, '[0-9]')) {
                return '999999999';
            }

            return 'matrix-missing-resource';
        }, $route->uri());

        return '/'.ltrim((string) $uri, '/');
    }
}
