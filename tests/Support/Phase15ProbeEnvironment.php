<?php

namespace Tests\Support;

final class Phase15ProbeEnvironment
{
    /** @return array<string,string> */
    public static function current(): array
    {
        $names = ['TESTING_PROFILE', 'TESTING_PHASE', 'TESTING_MYSQL_PHASE15_SERVER_UUID', 'TESTING_MYSQL_PHASE15_DATA_DIRECTORY', 'TESTING_MYSQL_PHASE15_PORT', 'APP_ENV', 'APP_DEBUG', 'APP_KEY', 'APP_URL', 'APP_LOCALE', 'APP_TIMEZONE', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET', 'DB_CHARSET', 'DB_COLLATION', 'DB_URL', 'PHASE15_MYSQL_PREFLIGHT_PASSED', 'PHASE15_TEST_SCHEMA_DISPOSABLE', 'PHASE15_TEST_SCHEMA_AUTHORIZATION', 'PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP'];
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
