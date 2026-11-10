<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\PermissionCatalog;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OperatorController extends Controller
{
    public function index(): Response
    {
        $operators = User::query()->whereHas('platformRoles')->with('platformRoles:id,key,name')->orderBy('name')->paginate(30);

        return Inertia::render('platform/operators/index', ['operators' => $operators, 'roles' => Role::query()->where('scope', 'platform')->where('key', '!=', 'platform.super_admin')->orderBy('name')->get(['id', 'key', 'name'])]);
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        abort_if(strtolower((string) $request->input('role_key')) === 'platform.super_admin', 403);
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'role_key' => ['required', Rule::in(['platform.operations_admin', 'platform.support_operator'])]]);
        abort_if(strtolower($data['email']) === strtolower($request->user()->email), 403, 'Self-assignment of platform access is not allowed.');
        $user = User::query()->where('email', Str::lower($data['email']))->firstOrFail();
        PermissionCatalog::ensurePlatformRoles();
        $role = Role::query()->where('tenant_id', null)->where('key', $data['role_key'])->firstOrFail();

        DB::transaction(function () use ($request, $audit, $user, $role): void {
            $user->platformRoles()->syncWithoutDetaching([$role->getKey() => ['assigned_by' => $request->user()->getKey()]]);
            $audit->record('platform.operator_role_assigned', $request->user(), null, $user, ['role_key' => $role->key]);
        });

        return back()->with('status', 'Platform operator access assigned.');
    }
}
