<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Academic\AcademicDefaults;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicConfiguration;
use App\Models\AcademicCourse;
use App\Models\AcademicLevel;
use App\Models\AcademicPolicyGap;
use App\Models\AcademicProgram;
use App\Models\AcademicUnit;
use App\Models\AcademicYear;
use App\Models\EducationQualityObjective;
use App\Models\Institution;
use Inertia\Inertia;
use Inertia\Response;

class AcademicDashboardController extends Controller
{
    public function __invoke(TenantContext $context): Response
    {
        $institution = Institution::query()->firstOrFail();
        $configuration = AcademicConfiguration::query()->where('institution_id', $institution->getKey())->firstOrFail();
        $features = AcademicDefaults::present($configuration)['enabled_features'];

        return Inertia::render('workspace/academics/index', [
            'tenant' => ['slug' => $context->tenant()->slug],
            'institution' => ['name' => $institution->display_name, 'type' => $institution->type],
            'configuration' => AcademicDefaults::present($configuration),
            'metrics' => [
                'currentYears' => $features['calendar'] ? AcademicYear::query()->where('institution_id', $institution->getKey())->where('is_current', true)->count() : 0,
                'academicUnits' => $features['organization'] ? AcademicUnit::query()->where('institution_id', $institution->getKey())->where('status', 'active')->count() : 0,
                'levels' => $features['organization'] ? AcademicLevel::query()->where('institution_id', $institution->getKey())->where('status', 'active')->count() : 0,
                'programs' => $features['programs'] ? AcademicProgram::query()->where('institution_id', $institution->getKey())->where('status', 'active')->count() : 0,
                'courses' => $features['catalog'] ? AcademicCourse::query()->where('institution_id', $institution->getKey())->where('status', 'active')->count() : 0,
                'openPolicyGaps' => $features['catalog'] ? AcademicPolicyGap::query()->where('status', 'open')->count() : 0,
                'qualityObjectives' => $features['quality'] ? EducationQualityObjective::query()->where('institution_id', $institution->getKey())->where('status', 'active')->count() : 0,
            ],
            'currentYear' => $features['calendar'] ? AcademicYear::query()->with('periods')->where('institution_id', $institution->getKey())->where('is_current', true)->first() : null,
            'recentYears' => $features['calendar'] ? AcademicYear::query()->where('institution_id', $institution->getKey())->orderByDesc('starts_on')->limit(5)->get(['id', 'name', 'code', 'starts_on', 'ends_on', 'status', 'is_current']) : [],
        ]);
    }
}
