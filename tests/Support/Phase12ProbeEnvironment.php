<?php

namespace Tests\Support;

final class Phase12ProbeEnvironment
{
    /** @return array<string,string> */
    public static function current(): array
    {
        $names = [
            'APP_ENV', 'APP_DEBUG', 'APP_KEY', 'APP_URL', 'APP_LOCALE', 'APP_TIMEZONE',
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'DB_SOCKET', 'DB_CHARSET', 'DB_COLLATION', 'DB_URL',
            'ADMISSIONS_DOCUMENT_SCANNER', 'ADMISSIONS_CLAMAV_BINARY', 'ADMISSIONS_CLAMAV_SIGNATURE_DIRECTORY',
            'ADMISSIONS_SIGTOOL_BINARY', 'ADMISSIONS_CLAMAV_TIMEOUT', 'ADMISSIONS_CLAMAV_MAX_SIGNATURE_AGE_HOURS',
            'ADMISSIONS_CLAMAV_TIMEZONE',
            'PHASE12_MYSQL_PREFLIGHT_PASSED', 'PHASE12_TEST_SCHEMA_DISPOSABLE', 'PHASE12_TEST_SCHEMA_AUTHORIZATION',
            'PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP',
        ];
        $environment = [];
        foreach ($names as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = (string) $value;
            }
        }

        return $environment;
    }
}
