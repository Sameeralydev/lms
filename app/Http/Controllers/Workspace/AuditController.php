<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function index(TenantContext $context): Response
    {
        return Inertia::render('workspace/audit/index', [
            'events' => AuditEvent::query()->with('actor:id,name,email')->where('tenant_id', $context->tenant()->getKey())->latest('occurred_at')->paginate(30),
        ]);
    }
}
