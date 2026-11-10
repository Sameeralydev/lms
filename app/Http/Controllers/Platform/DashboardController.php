<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Campus;
use App\Models\Institution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('platform/dashboard', [
            'metrics' => [
                'tenants' => Tenant::query()->count(),
                'activeTenants' => Tenant::query()->where('status', 'active')->count(),
                'suspendedTenants' => Tenant::query()->where('status', 'suspended')->count(),
                'institutions' => Institution::query()->withoutGlobalScope('tenant')->count(),
                'campuses' => Campus::query()->withoutGlobalScope('tenant')->count(),
                'activeMemberships' => TenantMembership::query()->withoutGlobalScope('tenant')->where('status', 'active')->count(),
            ],
            'recentEvents' => AuditEvent::query()->with(['tenant', 'actor'])->latest('occurred_at')->limit(8)->get(),
        ]);
    }
}
