<?php

return [
    'document_scanner' => env('ADMISSIONS_DOCUMENT_SCANNER', 'unavailable'),
    'clamav_binary' => env('ADMISSIONS_CLAMAV_BINARY', ''),
    'clamav_signature_directory' => env('ADMISSIONS_CLAMAV_SIGNATURE_DIRECTORY', ''),
    'clamav_signature_tool_binary' => env('ADMISSIONS_SIGTOOL_BINARY', ''),
    'clamav_timeout' => env('ADMISSIONS_CLAMAV_TIMEOUT', 120),
    'clamav_max_signature_age_hours' => env('ADMISSIONS_CLAMAV_MAX_SIGNATURE_AGE_HOURS', 48),
    'clamav_timezone' => env('ADMISSIONS_CLAMAV_TIMEZONE', env('APP_TIMEZONE', 'UTC')),
];
