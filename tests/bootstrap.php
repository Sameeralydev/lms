<?php

use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    TestDatabaseGuard::assertSafe(TestDatabaseGuard::currentEnvironment());
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'Test database safety check failed: '.$exception->getMessage().PHP_EOL);
    exit(2);
}
