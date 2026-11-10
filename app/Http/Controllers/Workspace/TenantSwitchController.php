<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TenantSwitchController extends Controller
{
    public function index(Request $request): Response
    {
        $memberships = TenantMembership::query()->withoutGlobalScope('tenant')->with([
            'tenant',
            'institution' => fn ($query) => $query->withoutGlobalScope('tenant'),
        ])->where('user_id', $request->user()->getKey())->where('status', 'active')->get();

        return Inertia::render('workspace/tenants', ['workspaces' => $memberships->map(fn (TenantMembership $membership) => ['slug' => $membership->tenant->slug, 'name' => $membership->institution->display_name, 'status' => $membership->tenant->status, 'roles' => $membership->roles()->pluck('name')])]);
    }

    public function switch(Request $request): RedirectResponse
    {
        $data = $request->validate(['tenant' => ['required', 'string', 'max:100']]);
        $membership = TenantMembership::query()->withoutGlobalScope('tenant')->with('tenant')
            ->where('user_id', $request->user()->getKey())->where('status', 'active')
            ->whereHas('tenant', fn ($query) => $query->where('slug', $data['tenant'])->where('status', 'active'))->firstOrFail();

        return redirect()->route('tenant.dashboard', ['tenant' => $membership->tenant->slug]);
    }
}
