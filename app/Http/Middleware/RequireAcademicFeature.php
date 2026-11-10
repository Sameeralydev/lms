<?php

namespace App\Http\Middleware;

use App\Domain\Academic\AcademicDefaults;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicConfiguration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Academic capability switches are enforced on the server; unknown academic
 * routes remain unavailable until they are assigned an explicit capability.
 */
final class RequireAcademicFeature
{
    /** @var array<string, string> */
    private const ROUTE_PREFIX_FEATURES = [
        'tenant.sis.' => 'student_information',
        'tenant.academics.years.' => 'calendar',
        'tenant.academics.periods.' => 'calendar',
        'tenant.academics.timetables.' => 'calendar',
        'tenant.academics.examinations.' => 'calendar',
        'tenant.academics.units.' => 'organization',
        'tenant.academics.levels.' => 'organization',
        'tenant.academics.qualifications.' => 'organization',
        'tenant.academics.programs.' => 'programs',
        'tenant.academics.classes.' => 'classes',
        'tenant.academics.sections.' => 'classes',
        'tenant.academics.subjects.' => 'catalog',
        'tenant.academics.courses.' => 'catalog',
        'tenant.academics.course-versions.' => 'catalog',
        'tenant.academics.outcomes.' => 'catalog',
        'tenant.academics.curriculum.' => 'catalog',
        'tenant.academics.credit-policies.' => 'credits',
        'tenant.academics.policy-' => 'catalog',
        'tenant.academics.policies.' => 'catalog',
        'tenant.academics.quality.' => 'quality',
        'tenant.academics.documents.' => 'quality',
        'tenant.academics.grades.' => 'catalog',
        'tenant.academics.gpa.' => 'catalog',
        'tenant.academics.records.' => 'catalog',
        'tenant.academics.reporting.' => 'catalog',
    ];

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = (string) $request->route()?->getName();
        if (in_array($routeName, [
            'tenant.academics.index',
            'tenant.academics.calendar',
            'tenant.academics.configuration.update',
            'tenant.academics.audit',
            'tenant.academics.audit.index',
        ], true)) {
            return $next($request);
        }

        $feature = $routeName === 'tenant.academics.structure'
            ? null
            : $this->featureForRoute($routeName);

        if ($routeName === 'tenant.academics.structure') {
            $features = $this->enabledFeatures();
            if (! ($features['organization'] || $features['programs'] || $features['classes'])) {
                throw new NotFoundHttpException;
            }

            return $next($request);
        }

        if ($feature === null) {
            throw new NotFoundHttpException;
        }

        if (! ($this->enabledFeatures()[$feature] ?? false)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }

    private function featureForRoute(string $routeName): ?string
    {
        $pageFeature = match ($routeName) {
            'tenant.academics.catalog', 'tenant.academics.policies' => 'catalog',
            'tenant.academics.quality' => 'quality',
            default => null,
        };
        if ($pageFeature !== null) {
            return $pageFeature;
        }

        foreach (self::ROUTE_PREFIX_FEATURES as $prefix => $feature) {
            if (str_starts_with($routeName, $prefix)) {
                return $feature;
            }
        }

        return null;
    }

    /** @return array<string, bool> */
    private function enabledFeatures(): array
    {
        if (! $this->context->hasTenant()) {
            throw new NotFoundHttpException;
        }

        $configuration = AcademicConfiguration::query()->first();
        if (! $configuration) {
            throw new NotFoundHttpException;
        }

        return AcademicDefaults::present($configuration)['enabled_features'];
    }
}
