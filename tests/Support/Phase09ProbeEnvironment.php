<?php

namespace Tests\Support;

/** Pass only guarded database and runtime settings to Phase 09 worker processes. */
final class Phase09ProbeEnvironment
{
    /** @return array<string,string> */
    public static function current(): array
    {
        $names = [
            'APP_ENV', 'APP_DEBUG', 'APP_KEY', 'APP_URL', 'APP_LOCALE', 'APP_TIMEZONE',
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'DB_SOCKET', 'DB_CHARSET', 'DB_COLLATION', 'DB_URL',
            'PHASE09_MYSQL_PREFLIGHT_PASSED', 'PHASE09_TEST_SCHEMA_DISPOSABLE', 'PHASE09_TEST_SCHEMA_AUTHORIZATION',
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
