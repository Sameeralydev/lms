<?php

namespace Tests\Support;

/** Passes only runtime and guarded database settings to isolated MySQL probe processes. */
final class Phase06ProbeEnvironment
{
    /** @return array<string,string> */
    public static function current(): array
    {
        $allowed = [
            'APP_ENV', 'APP_DEBUG', 'APP_KEY', 'APP_LOCALE', 'APP_TIMEZONE',
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'DB_SOCKET', 'DB_CHARSET', 'DB_COLLATION', 'DB_URL',
            'PHASE05_MYSQL_PREFLIGHT_PASSED', 'PHASE06_MYSQL_PREFLIGHT_PASSED', 'PHASE06_TEST_SCHEMA_DISPOSABLE',
            'PHASE07_MYSQL_PREFLIGHT_PASSED', 'PHASE07_TEST_SCHEMA_DISPOSABLE',
            'PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP',
        ];
        $environment = [];
        foreach ($allowed as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = (string) $value;
            }
        }

        return $environment;
    }
}
