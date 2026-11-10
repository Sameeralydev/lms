<?php

namespace Tests\Support;

/** Passes only the approved runtime and Phase 08 database settings to probe workers. */
final class Phase08ProbeEnvironment
{
    /** @return array<string,string> */
    public static function current(): array
    {
        $names = [
            'APP_ENV', 'APP_DEBUG', 'APP_KEY', 'APP_URL', 'APP_LOCALE', 'APP_TIMEZONE',
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'DB_SOCKET', 'DB_CHARSET', 'DB_COLLATION', 'DB_URL',
            'PHASE08_MYSQL_PREFLIGHT_PASSED', 'PHASE08_TEST_SCHEMA_DISPOSABLE', 'PHASE08_TEST_SCHEMA_AUTHORIZATION',
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
