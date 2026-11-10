<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Campus memberships may use only controllers that enforce record-level campus
 * ownership. New academic routes stay denied until deliberately added here.
 */
final class RequireAcademicScope
{
    /** @var list<string> */
    private const CAMPUS_SCOPED_ROUTE_PREFIXES = [
        'tenant.sis.',
        'tenant.academics.calendar',
        'tenant.academics.years.',
        'tenant.academics.periods.',
        'tenant.academics.timetables.',
        'tenant.academics.examinations.',
        'tenant.academics.structure',
        'tenant.academics.units.',
        'tenant.academics.programs.',
        'tenant.academics.classes.',
        'tenant.academics.sections.',
        'tenant.academics.levels.',
        'tenant.academics.qualifications.',
        'tenant.academics.records.',
        'tenant.academics.reporting.',
    ];

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->hasMembership() || $this->context->membership()->campus_id === null) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        foreach (self::CAMPUS_SCOPED_ROUTE_PREFIXES as $prefix) {
            if ($routeName === $prefix || ($prefix !== 'tenant.academics.calendar' && str_starts_with((string) $routeName, $prefix))) {
                return $next($request);
            }
        }

        throw new NotFoundHttpException;
    }
}
