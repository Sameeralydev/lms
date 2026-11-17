<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantPlan;
use App\Models\TenantSubscription;
use App\Models\TenantSubscriptionEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function index(): Response
    {
        $tenants = Tenant::query()->with(['subscription.plan'])->orderBy('name')->paginate(20);

        return Inertia::render('platform/subscriptions/index', [
            'tenants' => $tenants,
            'plans' => TenantPlan::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'key']),
        ]);
    }

    public function assign(Request $request, Tenant $tenant, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('tenant_plans', 'id')->where('is_active', true)],
            'status' => ['required', Rule::in(['active', 'paused'])],
        ]);

        DB::transaction(function () use ($data, $tenant, $request, $audit): void {
            $lockedTenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->getKey());
            abort_if($lockedTenant->status === 'archived', 409, 'Archived tenants cannot receive plan changes.');
            $plan = TenantPlan::query()->where('is_active', true)->lockForUpdate()->findOrFail($data['plan_id']);
            $subscription = TenantSubscription::query()->where('tenant_id', $lockedTenant->getKey())->lockForUpdate()->first();
            $before = $subscription ? ['plan_id' => $subscription->tenant_plan_id, 'status' => $subscription->status] : null;

            if (! $subscription) {
                $subscription = TenantSubscription::query()->create([
                    'tenant_id' => $lockedTenant->getKey(),
                    'tenant_plan_id' => $plan->getKey(),
                    'status' => $data['status'],
                    'started_at' => now(),
                    'assigned_by' => $request->user()->getKey(),
                ]);
            } else {
                $subscription->fill(['tenant_plan_id' => $plan->getKey(), 'status' => $data['status'], 'started_at' => now(), 'ended_at' => null, 'assigned_by' => $request->user()->getKey()])->save();
            }

            TenantSubscriptionEvent::query()->create([
                'tenant_id' => $lockedTenant->getKey(),
                'tenant_subscription_id' => $subscription->getKey(),
                'tenant_plan_id' => $plan->getKey(),
                'actor_user_id' => $request->user()->getKey(),
                'event_type' => $before ? 'plan_changed' : 'plan_assigned',
                'details' => ['before' => $before, 'after' => ['plan_id' => $plan->getKey(), 'status' => $data['status']]],
                'occurred_at' => now(),
            ]);
            $audit->record('platform.tenant_plan_assigned', $request->user(), (int) $lockedTenant->getKey(), $subscription, ['before' => $before, 'after' => ['plan_id' => $plan->getKey(), 'plan_key' => $plan->key, 'status' => $data['status']]]);
        });

        return back()->with('status', 'Tenant plan assignment saved. No payment or external billing action was performed.');
    }
}
