<?php

namespace App\Domain\Academic;

use App\Models\AcademicConfiguration;
use App\Models\Institution;
use App\Models\Tenant;

final class AcademicDefaults
{
    /** @return array<string, mixed> */
    public static function present(AcademicConfiguration $configuration): array
    {
        $features = ['calendar' => false, 'organization' => false, 'programs' => false, 'classes' => false, 'catalog' => false, 'credits' => false, 'quality' => false, 'student_information' => false];
        $storedFeaturesAttribute = $configuration->getAttribute('enabled_features');
        $storedFeatures = is_array($storedFeaturesAttribute) ? $storedFeaturesAttribute : [];
        if (array_is_list($storedFeatures)) {
            foreach ($storedFeatures as $feature) {
                if (is_string($feature) && array_key_exists($feature, $features)) {
                    $features[$feature] = true;
                }
            }
        } else {
            foreach ($features as $key => $_) {
                $features[$key] = (bool) ($storedFeatures[$key] ?? false);
            }
        }
        $terminologyAttribute = $configuration->getAttribute('terminology');
        $terminology = is_array($terminologyAttribute) ? $terminologyAttribute : [];
        if (isset($terminology['group']) && ! isset($terminology['class'])) {
            $terminology['class'] = $terminology['group'];
        }
        unset($terminology['group']);
        $operatingModel = match ($configuration->operating_model) {
            'corporate' => 'corporate_learning',
            'hybrid' => 'custom',
            default => $configuration->operating_model,
        };

        return [...$configuration->toArray(), 'operating_model' => $operatingModel, 'enabled_features' => $features, 'terminology' => $terminology];
    }

    /** @return array{model:string,terminology:array<string,string>,features:array<string,bool>} */
    public static function forInstitutionType(string $type): array
    {
        return match ($type) {
            'school' => ['model' => 'k12', 'terminology' => ['level' => 'Grade', 'class' => 'Class', 'subject' => 'Subject', 'period' => 'Term'], 'features' => ['calendar' => true, 'organization' => true, 'programs' => false, 'classes' => true, 'catalog' => true, 'credits' => false, 'student_information' => true]],
            'university', 'college' => ['model' => 'higher_education', 'terminology' => ['level' => 'Academic level', 'unit' => 'Faculty / department', 'program' => 'Program', 'period' => 'Semester', 'course' => 'Course'], 'features' => ['calendar' => true, 'organization' => true, 'programs' => true, 'classes' => false, 'catalog' => true, 'credits' => true, 'student_information' => true]],
            'academy' => ['model' => 'academy', 'terminology' => ['level' => 'Level', 'program' => 'Training program', 'class' => 'Cohort', 'period' => 'Training cycle', 'course' => 'Course'], 'features' => ['calendar' => true, 'organization' => true, 'programs' => true, 'classes' => true, 'catalog' => true, 'credits' => false, 'student_information' => true]],
            'corporate' => ['model' => 'corporate_learning', 'terminology' => ['program' => 'Learning program', 'class' => 'Training cycle', 'period' => 'Training cycle', 'course' => 'Course'], 'features' => ['calendar' => true, 'organization' => true, 'programs' => true, 'classes' => true, 'catalog' => true, 'credits' => false, 'student_information' => true]],
            default => ['model' => 'custom', 'terminology' => ['level' => 'Level', 'program' => 'Program', 'class' => 'Class / cohort', 'period' => 'Period', 'course' => 'Course'], 'features' => ['calendar' => true, 'organization' => true, 'programs' => true, 'classes' => true, 'catalog' => true, 'credits' => false, 'student_information' => true]],
        };
    }

    public static function provision(Tenant $tenant, Institution $institution, int $actorId): AcademicConfiguration
    {
        $defaults = self::forInstitutionType((string) $institution->type);

        return AcademicConfiguration::query()->updateOrCreate(
            ['tenant_id' => $tenant->getKey(), 'institution_id' => $institution->getKey()],
            ['operating_model' => $defaults['model'], 'timezone' => $institution->timezone ?: 'UTC', 'locale' => $institution->locale ?: 'en', 'enabled_features' => $defaults['features'], 'terminology' => $defaults['terminology'], 'updated_by' => $actorId],
        );
    }
}
