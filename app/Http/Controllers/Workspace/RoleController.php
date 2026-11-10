<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(TenantContext $context): Response
    {
        return Inertia::render('workspace/roles/index', [
            'roles' => Role::query()->where('scope', 'tenant')->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $context->tenant()->getKey()))->with('permissions:id,key,description')->orderBy('name')->get(),
            'permissions' => Permission::query()->where('scope', 'tenant')->get(['id', 'key', 'description']),
            'tenant' => ['slug' => $context->tenant()->slug],
        ]);
    }

    public function store(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        abort_if($request->input('key') === 'tenant.owner', 403);
        $allowed = [];
        foreach ($context->membership()->roles()->with('permissions')->get() as $actorRole) {
            foreach ($actorRole->permissions as $permission) {
                $allowed[] = (int) $permission->getKey();
            }
        }
        $data = $request->validate([
            'key' => ['required', 'string', 'max:80', 'alpha_dash:ascii', Rule::unique('roles', 'key')->where('tenant_id', $context->tenant()->getKey())],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['integer', Rule::in($allowed)],
        ]);
        $role = DB::transaction(function () use ($data, $context, $request, $audit): Role {
            $role = Role::query()->create(['tenant_id' => $context->tenant()->getKey(), 'key' => $data['key'], 'name' => $data['name'], 'scope' => 'tenant', 'description' => $data['description'] ?? null, 'is_system' => false]);
            $role->permissions()->sync($data['permissions'] ?? []);
            $audit->record('role.created', $request->user(), (int) $context->tenant()->getKey(), $role, ['permission_count' => count($data['permissions'] ?? [])]);

            return $role;
        });

        return back()->with('status', "Role {$role->name} created.");
    }
}
