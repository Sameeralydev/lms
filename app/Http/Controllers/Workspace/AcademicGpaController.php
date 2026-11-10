<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Grading\GpaCalculationWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicGpaPolicy;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class AcademicGpaController
{
    public function index(Tenant $tenant, TenantContext $context): Response
    {
        $membership = $context->membership();
        abort_unless($membership->hasPermission('academic.records.gpa.manage'), 403);
        $periods = DB::table('academic_periods as p')->join('academic_years as y', function ($join): void {
            $join->on('y.id', '=', 'p.academic_year_id')->on('y.tenant_id', '=', 'p.tenant_id');
        })
            ->where('p.tenant_id', $membership->tenant_id)->where('y.institution_id', $membership->institution_id)
            ->when($membership->campus_id !== null, fn ($q) => $q->where(fn ($scope) => $scope->whereNull('y.campus_id')->orWhere('y.campus_id', $membership->campus_id)))
            ->orderByDesc('p.starts_on')->get(['p.id', 'p.name', 'p.code']);
        $students = DB::table('sis_students')->where('tenant_id', $membership->tenant_id)->where('institution_id', $membership->institution_id)->where('status', 'active')
            ->when($membership->campus_id !== null, fn ($q) => $q->where('home_campus_id', $membership->campus_id))
            ->orderBy('student_number')->limit(500)->get(['id', 'student_number', 'legal_first_name', 'legal_last_name']);
        $policies = AcademicGpaPolicy::query()->with('standingBands')->where('institution_id', $membership->institution_id)->orderByDesc('created_at')->get()
            ->map(fn (AcademicGpaPolicy $policy): array => ['id' => (int) $policy->getKey(), 'name' => $policy->name, 'policy_key' => $policy->policy_key, 'version' => (int) $policy->version, 'repeat_rule' => $policy->repeat_rule, 'status' => $policy->status, 'bands' => $policy->standingBands->map(fn ($band): array => ['label' => $band->label, 'minimum_gpa' => (string) $band->minimum_gpa])->values()])->values();

        return Inertia::render('workspace/academics/gpa/index', ['tenant' => ['slug' => $tenant->slug], 'periods' => $periods, 'students' => $students, 'policies' => $policies]);
    }

    public function createPolicy(Tenant $tenant, Request $request, GpaCalculationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'policy_key' => ['required', 'alpha_dash:ascii', 'max:64'],
            'repeat_rule' => ['required', 'in:highest,latest,all'], 'exclude_pass_fail' => ['required', 'boolean'],
            'bands' => ['required', 'array', 'min:1', 'max:20'], 'bands.*.band_key' => ['required', 'alpha_dash:ascii', 'max:32', 'distinct'],
            'bands.*.label' => ['required', 'string', 'max:80', 'distinct'], 'bands.*.minimum_gpa' => ['required', 'string', 'regex:/^\d{1,2}(?:\.\d{1,3})?$/', 'distinct'],
        ]);
        $policy = $workflow->createPolicy($data, $request->user());

        return back()->with('success', "GPA policy draft created: {$policy->name}.");
    }

    public function activatePolicy(Tenant $tenant, int $policyId, Request $request, GpaCalculationWorkflow $workflow): RedirectResponse
    {
        $policy = $workflow->activatePolicy($policyId, $request->user());

        return back()->with('success', "GPA policy activated: {$policy->name}.");
    }

    public function calculate(Tenant $tenant, Request $request, GpaCalculationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['student_id' => ['required', 'integer', 'min:1'], 'policy_id' => ['required', 'integer', 'min:1'], 'academic_period_id' => ['nullable', 'integer', 'min:1']]);
        $snapshot = $workflow->calculate((int) $data['student_id'], (int) $data['policy_id'], isset($data['academic_period_id']) ? (int) $data['academic_period_id'] : null, $request->user());

        return back()->with('success', "GPA snapshot {$snapshot->snapshot_number} calculated: {$snapshot->gpa}.");
    }

    public function myProgress(Tenant $tenant, Request $request, GpaCalculationWorkflow $workflow): Response
    {
        return Inertia::render('workspace/academics/gpa/my-progress', ['tenant' => ['slug' => $tenant->slug], 'snapshots' => $workflow->studentProgress($request->user())]);
    }
}
