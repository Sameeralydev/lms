<?php

namespace Tests\Support;

/** Pass only approved runtime and guarded Phase 10 database settings to workers. */
final class Phase10ProbeEnvironment
{
    /** @return array<string,string> */
    public static function current(): array
    {
        $names = [
            'APP_ENV', 'APP_DEBUG', 'APP_KEY', 'APP_URL', 'APP_LOCALE', 'APP_TIMEZONE',
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'DB_SOCKET', 'DB_CHARSET', 'DB_COLLATION', 'DB_URL',
            'PHASE10_MYSQL_PREFLIGHT_PASSED', 'PHASE10_TEST_SCHEMA_DISPOSABLE', 'PHASE10_TEST_SCHEMA_AUTHORIZATION',
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
