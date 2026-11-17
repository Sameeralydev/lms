<?php

return [
    // These switches are labels for product capabilities that exist in the current application.
    // They are intentionally not prices or institution policy defaults.
    'features' => [
        'academics' => 'Academic administration',
        'admissions' => 'Admissions',
        'attendance' => 'Attendance',
        'assessment' => 'Assessments and examinations',
        'learning' => 'Course delivery and learning',
        'sis' => 'Student information system',
        'hr' => 'Human resources',
        'library' => 'Library management',
        'white_label' => 'White-label branding and custom domains',
    ],
    'limits' => [
        'campuses' => 'active campus',
        'staff' => 'active staff record',
    ],
    // Route names are mapped centrally so every read and write in a module receives the same gate.
    'route_features' => [
        'public.admissions.' => 'admissions',
        'tenant.academics.examinations.' => 'assessment',
        'tenant.academics.' => 'academics',
        'tenant.admissions.' => 'admissions',
        'tenant.attendance.' => 'attendance',
        'tenant.academic-records.' => 'academics',
        'tenant.assessments.' => 'assessment',
        'tenant.assignments.' => 'learning',
        'tenant.assignment-notifications.' => 'learning',
        'tenant.enrollment.' => 'academics',
        'tenant.exams.' => 'assessment',
        'tenant.hr.staff.' => 'hr',
        'tenant.learning.' => 'learning',
        'tenant.library.' => 'library',
        'tenant.branding.' => 'white_label',
        'tenant.domains.' => 'white_label',
        'tenant.registration.' => 'academics',
        'tenant.sis.' => 'sis',
        'tenant.admissions' => 'admissions',
    ],
];
