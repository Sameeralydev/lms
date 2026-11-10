<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('platform/audit/index', ['events' => AuditEvent::query()->with(['tenant', 'actor:id,name,email'])->latest('occurred_at')->paginate(30)]);
    }
}
