<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Inertia\Inertia;
use Inertia\Response;

final class AcademicAuditController extends Controller
{
    public function __invoke(TenantContext $context): Response
    {
        $events = AuditEvent::query()
            ->with('actor:id,name')
            ->where('tenant_id', $context->tenant()->getKey())
            ->where('event_type', 'like', 'academic.%')
            ->latest('occurred_at')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('workspace/academics/audit', [
            'tenant' => ['slug' => $context->tenant()->slug],
            'events' => $events,
        ]);
    }
}
