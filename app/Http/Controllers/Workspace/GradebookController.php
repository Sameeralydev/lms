<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Grading\GradebookAssessmentSynchronizer;
use App\Domain\Grading\GradebookWorkflow;
use App\Domain\Grading\OfficialAcademicRecordWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicGradeOverrideRequest;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class GradebookController
{
    public function index(Tenant $tenant, Request $request, TenantContext $context, GradebookWorkflow $workflow): Response
    {
        $data = $workflow->instructorWorkspace($request->user());
        $membership = $context->membership();
        $periods = DB::table('academic_periods as periods')
            ->join('academic_years as years', function ($join): void {
                $join->on('years.id', '=', 'periods.academic_year_id')->on('years.tenant_id', '=', 'periods.tenant_id');
            })
            ->where('periods.tenant_id', $membership->tenant_id)
            ->where('years.institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($query) => $query->where(function ($scope) use ($membership): void {
                $scope->whereNull('years.campus_id')->orWhere('years.campus_id', $membership->campus_id);
            }))
            ->orderByDesc('periods.starts_on')->get(['periods.id', 'periods.name', 'periods.code', 'periods.starts_on', 'periods.ends_on']);
        $campuses = DB::table('campuses')->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($query) => $query->where('id', $membership->campus_id))
            ->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']);

        return Inertia::render('workspace/academics/grades/index', [
            'tenant' => ['slug' => $tenant->slug],
            'periods' => $periods,
            'campuses' => $campuses,
            'campusScope' => $membership->campus_id === null ? null : (int) $membership->campus_id,
            'sections' => $data['sections']->map(fn ($section): array => ['id' => (int) $section->getKey(), 'code' => $section->section_code, 'course' => $section->offering?->course?->title, 'period_id' => (int) $section->offering?->academic_period_id])->values(),
            'gradebooks' => $data['gradebooks']->map(fn ($book): array => ['id' => (int) $book->getKey(), 'status' => $book->status, 'section' => $book->section?->section_code, 'course' => $book->section?->offering?->course?->title, 'policy' => $book->policy?->name, 'period_id' => (int) $book->academic_period_id])->values(),
            'policies' => $data['policies']->map(fn ($policy): array => ['id' => (int) $policy->getKey(), 'name' => $policy->name, 'status' => $policy->status, 'scale_type' => $policy->scale_type, 'version' => (int) $policy->version, 'period_id' => (int) $policy->academic_period_id])->values(),
            'permissions' => [
                'managePolicy' => $context->membership()->hasPermission('academic.grades.policy.manage'),
                'manageBook' => $context->membership()->hasPermission('academic.grades.book.manage'),
                'manageAll' => $context->membership()->hasPermission('academic.grades.book.manage-all'),
            ],
        ]);
    }

    public function storePolicy(Tenant $tenant, Request $request, GradebookWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'policy_key' => ['required', 'alpha_dash:ascii', 'max:64'],
            'academic_period_id' => ['required', 'integer', 'min:1'], 'campus_id' => ['nullable', 'integer', 'min:1'],
            'scale_type' => ['required', 'in:percentage,numeric,letter,pass_fail'],
            'scale_bands' => ['sometimes', 'array', 'max:20'],
            'scale_bands.*.band_key' => ['required_with:scale_bands', 'alpha_dash:ascii', 'max:32', 'distinct'],
            'scale_bands.*.label' => ['required_with:scale_bands', 'string', 'max:48', 'distinct'],
            'scale_bands.*.minimum_percentage_basis_points' => ['required_with:scale_bands', 'integer', 'between:0,10000', 'distinct'],
            'scale_bands.*.grade_points' => ['nullable', 'required_if:scale_type,letter', 'numeric', 'between:0,20', 'regex:/^\d{1,2}(?:\.\d{1,3})?$/'],
            'scale_bands.*.passing' => ['nullable', 'required_if:scale_type,pass_fail', 'boolean'],
            'rounding_mode' => ['required', 'in:half_up,half_even,down'], 'precision' => ['required', 'integer', 'in:2'],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'categories' => ['required', 'array', 'min:1', 'max:20'],
            'categories.*.category_key' => ['required', 'alpha_dash:ascii', 'max:48', 'distinct'],
            'categories.*.name' => ['required', 'string', 'max:120'],
            'categories.*.weight_basis_points' => ['required', 'integer', 'between:0,10000'],
            'categories.*.required' => ['required', 'boolean'],
        ]);
        $data['effective_until'] ??= null;
        $policy = $workflow->createPolicy($data, $request->user());

        return back()->with('success', 'Grading policy draft created: '.$policy->name);
    }

    public function activatePolicy(Tenant $tenant, int $policyId, Request $request, GradebookWorkflow $workflow): RedirectResponse
    {
        $policy = $workflow->activatePolicy($policyId, $request->user());

        return back()->with('success', 'Grading policy activated: '.$policy->name);
    }

    public function store(Tenant $tenant, Request $request, GradebookWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['section_id' => ['required', 'integer', 'min:1'], 'policy_id' => ['required', 'integer', 'min:1']]);
        $book = $workflow->createGradebook((int) $data['section_id'], (int) $data['policy_id'], $request->user());

        return to_route('tenant.academics.grades.show', ['tenant' => $tenant->slug, 'gradebookId' => $book->getKey()])->with('success', 'Gradebook is ready for assessment items.');
    }

    public function show(Tenant $tenant, int $gradebookId, Request $request, TenantContext $context, GradebookWorkflow $workflow): Response
    {
        $data = $workflow->gradebookWorkspace($gradebookId, $request->user());
        $book = $data['gradebook'];

        return Inertia::render('workspace/academics/grades/show', [
            'tenant' => ['slug' => $tenant->slug],
            'gradebook' => ['id' => (int) $book->getKey(), 'status' => $book->status, 'version' => (int) $book->version, 'section' => $book->section?->section_code, 'course' => $book->section?->offering?->course?->title, 'policy' => ['id' => (int) $book->policy->getKey(), 'name' => $book->policy->name, 'categories' => $book->policy->categories->map(fn ($category): array => ['id' => (int) $category->getKey(), 'name' => $category->name, 'weight_basis_points' => (int) $category->weight_basis_points])->values()], 'items' => $book->items->map(fn ($item): array => ['id' => (int) $item->getKey(), 'item_key' => $item->item_key, 'title' => $item->title, 'category_id' => (int) $item->category_id, 'maximum_points' => (string) $item->maximum_points, 'source_type' => $item->source_type, 'source_key' => $item->source_key])->values()],
            'roster' => $data['roster'],
            'pendingOverrides' => AcademicGradeOverrideRequest::query()->with(['entry.enrollment.student'])
                ->where('tenant_id', $context->membership()->tenant_id)
                ->where('institution_id', $context->membership()->institution_id)
                ->where('gradebook_id', $book->getKey())->where('status', 'pending')->orderBy('created_at')->get()
                ->map(fn (AcademicGradeOverrideRequest $override): array => [
                    'id' => (int) $override->getKey(),
                    'entry_id' => (int) $override->entry_id,
                    'original_points' => (string) $override->original_points,
                    'proposed_points' => (string) $override->proposed_points,
                    'reason' => $override->reason,
                    'student' => trim(($override->entry->enrollment->student->legal_first_name ?? '').' '.($override->entry->enrollment->student->legal_last_name ?? '')),
                    'student_number' => $override->entry->enrollment->student->student_number,
                ])->values(),
            'permissions' => ['edit' => $context->membership()->hasPermission('academic.grades.book.manage'), 'submit' => $context->membership()->hasPermission('academic.grades.submit'), 'review' => $context->membership()->hasPermission('academic.grades.review'), 'publish' => $context->membership()->hasPermission('academic.grades.publish')],
        ]);
    }

    public function storeItem(Tenant $tenant, int $gradebookId, Request $request, GradebookWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['item_key' => ['required', 'alpha_dash:ascii', 'max:64'], 'title' => ['required', 'string', 'max:180'], 'category_id' => ['required', 'integer', 'min:1'], 'maximum_points' => ['required', 'string', 'max:12', 'regex:/^(?:0|[1-9]\d{0,6})(?:\.\d{1,2})?$/'], 'source_type' => ['required', 'in:manual,assignment,quiz,examination'], 'source_key' => ['nullable', 'string', 'max:160']]);
        $data['source_key'] ??= null;
        $workflow->createItem($gradebookId, $data, $request->user());

        return $this->gradebookRedirect($tenant, $gradebookId)->with('success', 'Assessment column added.');
    }

    public function synchronizeItem(Tenant $tenant, int $gradebookId, int $itemId, Request $request, GradebookAssessmentSynchronizer $synchronizer): RedirectResponse
    {
        $counts = $synchronizer->synchronize($gradebookId, $itemId, $request->user());

        return $this->gradebookRedirect($tenant, $gradebookId)->with('success', "Assessment synchronization completed: {$counts['imported']} new, {$counts['updated']} amended, {$counts['unchanged']} unchanged, {$counts['unmatched']} without an active section enrollment.");
    }

    public function saveEntry(Tenant $tenant, int $gradebookId, int $itemId, int $enrollmentId, Request $request, GradebookWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['earned_points' => ['nullable', 'string', 'max:12'], 'entry_status' => ['required', 'in:graded,missing,incomplete,excused'], 'feedback' => ['nullable', 'string', 'max:4000'], 'expected_lock_version' => ['nullable', 'integer', 'min:0'], 'reason' => ['nullable', 'string', 'max:500']]);
        $workflow->saveEntry($gradebookId, $itemId, $enrollmentId, $data, $request->user());

        return $this->gradebookRedirect($tenant, $gradebookId)->with('success', 'Grade entry saved as a versioned record.');
    }

    public function requestOverride(Tenant $tenant, int $gradebookId, int $entryId, Request $request, GradebookWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['proposed_points' => ['required', 'string', 'max:12', 'regex:/^(?:0|[1-9]\\d{0,6})(?:\\.\\d{1,2})?$/'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $workflow->requestOverride($gradebookId, $entryId, $data['proposed_points'], $data['reason'], $request->user());

        return $this->gradebookRedirect($tenant, $gradebookId)->with('success', 'Grade override request sent for independent review.');
    }

    public function decideOverride(Tenant $tenant, int $gradebookId, int $overrideId, Request $request, GradebookWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'decision_reason' => ['required', 'string', 'min:5', 'max:500']]);
        $workflow->decideOverride($overrideId, $data['decision'] === 'approve', $data['decision_reason'], $request->user());

        return $this->gradebookRedirect($tenant, $gradebookId)->with('success', 'Grade override decision recorded.');
    }

    public function transition(Tenant $tenant, int $gradebookId, string $action, Request $request, GradebookWorkflow $workflow): RedirectResponse
    {
        $workflow->transition($gradebookId, $action, $request->user());

        return $this->gradebookRedirect($tenant, $gradebookId)->with('success', 'Gradebook '.$action.' action completed.');
    }

    public function myGrades(Tenant $tenant, Request $request, TenantContext $context, GradebookWorkflow $workflow): Response
    {
        $data = $workflow->studentWorkspace($request->user());

        return Inertia::render('workspace/academics/grades/my-grades', [
            'tenant' => ['slug' => $tenant->slug],
            'entries' => $data['entries']->map(fn ($entry): array => [
                'id' => (int) $entry->getKey(),
                'earned_points' => $entry->earned_points,
                'entry_status' => $entry->entry_status,
                'item' => [
                    'title' => $entry->item->title,
                    'maximum_points' => $entry->item->maximum_points,
                    'category' => ['name' => $entry->item->category->name],
                ],
                'gradebook' => [
                    'status' => $entry->gradebook->status,
                    'section' => [
                        'section_code' => $entry->gradebook->section->section_code,
                        'offering' => ['course' => ['title' => $entry->gradebook->section->offering->course->title]],
                    ],
                    'policy' => ['name' => $entry->gradebook->policy->name],
                ],
            ])->values(),
            'results' => $data['results']->map(fn ($result): array => [
                'id' => (int) $result->getKey(),
                'percentage' => $result->percentage,
                'scale_result' => $result->scale_result ?? ($result->percentage.'%'),
                'grade_points' => $result->grade_points,
                'passed' => $result->passed,
                'publication_number' => (int) $result->publication->publication_number,
                'published_at' => $result->publication->published_at->toIso8601String(),
                'course' => $result->gradebook->section->offering->course->title,
                'section' => $result->gradebook->section->section_code,
            ])->values(),
        ]);
    }

    public function guardianGrades(Tenant $tenant, Request $request, OfficialAcademicRecordWorkflow $workflow): Response
    {
        return Inertia::render('workspace/academics/grades/guardian-grades', [
            'tenant' => ['slug' => $tenant->slug],
            'results' => $workflow->guardianPublishedGrades($request->user()),
        ]);
    }

    private function gradebookRedirect(Tenant $tenant, int $gradebookId): RedirectResponse
    {
        return to_route('tenant.academics.grades.show', ['tenant' => $tenant->slug, 'gradebookId' => $gradebookId]);
    }
}
