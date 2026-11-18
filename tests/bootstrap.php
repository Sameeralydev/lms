<?php

use Tests\Support\CentralTestingEnvironment;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    CentralTestingEnvironment::assertNoCachedConfiguration(dirname(__DIR__));
    $central = CentralTestingEnvironment::read(dirname(__DIR__));
    $profile = getenv('TESTING_PROFILE') ?: ($central['TESTING_PROFILE'] ?? 'sqlite');
    if ($profile === 'sqlite') {
        CentralTestingEnvironment::install(CentralTestingEnvironment::sqlite(dirname(__DIR__), $central));
    } elseif ($profile !== 'mysql' || getenv('TESTING_PHASE') === false) {
        throw new RuntimeException('Select the SQLite profile or enter MySQL through its guarded phase runner.');
    }

    TestDatabaseGuard::assertSafe(TestDatabaseGuard::currentEnvironment());
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'Test database safety check failed: '.$exception->getMessage().PHP_EOL);
    exit(2);
}
