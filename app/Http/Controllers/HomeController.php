<?php

namespace App\Http\Controllers;

use App\Models\TenantDomain;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $domain = $request->attributes->get('verifiedTenantDomain');

        if ($domain instanceof TenantDomain) {
            if (! $request->user()) {
                return redirect()->route('login');
            }

            $membership = TenantMembership::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $domain->tenant_id)
                ->where('user_id', $request->user()->getKey())
                ->where('status', 'active')
                ->exists();

            abort_unless($membership, 404);

            return redirect()->route('tenant.dashboard', ['tenant' => $domain->tenant->slug]);
        }

        $user = $request->user();
        if ($user) {
            if ($user->hasPlatformPermission('platform.dashboard.view')) {
                return redirect()->route('platform.dashboard');
            }

            return redirect()->route('workspaces.index');
        }

        return Inertia::render('welcome');
    }
}
