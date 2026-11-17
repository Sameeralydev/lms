<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\TenantPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('platform/plans/index', [
            'plans' => TenantPlan::query()->withCount('subscriptions')->orderBy('name')->get(),
            'features' => config('saas.features'),
        ]);
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $request, $audit): void {
            $plan = TenantPlan::query()->create($data);
            $audit->record('platform.plan_created', $request->user(), null, $plan, ['plan_key' => $plan->key, 'feature_count' => count($plan->entitlements)]);
        });

        return back()->with('status', 'Plan created. No pricing or billing was configured.');
    }

    public function update(Request $request, TenantPlan $plan, AuditRecorder $audit): RedirectResponse
    {
        $data = $this->validated($request, $plan);
        DB::transaction(function () use ($plan, $data, $audit, $request): void {
            $lockedPlan = TenantPlan::query()->lockForUpdate()->findOrFail($plan->getKey());
            if ($lockedPlan->subscriptions()->exists()) {
                $configurationChanged = $lockedPlan->key !== $data['key']
                    || $lockedPlan->name !== $data['name']
                    || $lockedPlan->description !== $data['description']
                    || $lockedPlan->entitlements !== $data['entitlements']
                    || $lockedPlan->limits !== $data['limits'];
                abort_if($configurationChanged, 409, 'This plan has tenant assignments. Create a new plan version before changing its features, limits, identity, or description.');
            }
            $before = ['key' => $lockedPlan->key, 'name' => $lockedPlan->name, 'entitlements' => $lockedPlan->entitlements, 'limits' => $lockedPlan->limits, 'is_active' => $lockedPlan->is_active];
            $lockedPlan->fill($data)->save();
            $audit->record('platform.plan_updated', $request->user(), null, $lockedPlan, ['before' => $before, 'after' => ['key' => $lockedPlan->key, 'name' => $lockedPlan->name, 'entitlements' => $lockedPlan->entitlements, 'limits' => $lockedPlan->limits, 'is_active' => $lockedPlan->is_active]]);
        });

        return back()->with('status', 'Plan updated. Existing tenant records and usage were not changed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?TenantPlan $plan = null): array
    {
        $featureKeys = array_keys(config('saas.features', []));
        $keyUnique = Rule::unique('tenant_plans', 'key');
        if ($plan) {
            $keyUnique->ignore($plan->getKey());
        }
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:80', 'alpha_dash', $keyUnique],
            'name' => ['required', 'string', 'max:140'],
            'description' => ['nullable', 'string', 'max:2000'],
            'entitlements' => ['required', 'array'],
            'entitlements.*' => ['required', 'boolean'],
            'limits' => ['sometimes', 'array'],
            'limits.*' => ['integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);

        foreach (array_keys($validated['entitlements']) as $feature) {
            if (! in_array($feature, $featureKeys, true)) {
                abort(422, 'A plan contains an unknown feature key.');
            }
        }
        foreach (array_keys($validated['limits'] ?? []) as $limit) {
            if (! preg_match('/^[a-z][a-z0-9_.-]{1,79}$/', (string) $limit)) {
                abort(422, 'Limit keys must use lowercase letters, numbers, dots, underscores, or hyphens.');
            }
        }

        $entitlements = [];
        foreach ($featureKeys as $key) {
            $entitlements[$key] = (bool) ($validated['entitlements'][$key] ?? false);
        }

        return [
            'key' => $validated['key'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'entitlements' => $entitlements,
            'limits' => $validated['limits'] ?? [],
            'is_active' => (bool) $validated['is_active'],
        ];
    }
}
