<?php

namespace Tests\Unit;

use Dotenv\Dotenv;
use Tests\Support\Phase12SchemaIdentifierPolicy;
use Tests\TestCase;

final class Phase12GuardPolicyTest extends TestCase
{
    public function test_central_example_keeps_mysql_credentials_and_owner_authorization_blank(): void
    {
        $environment = Dotenv::parse((string) file_get_contents(base_path('.env.testing.example')));

        self::assertSame(':memory:', $environment['DB_DATABASE']);
        self::assertSame('', $environment['TESTING_MYSQL_PHASE12_USERNAME']);
        self::assertSame('', $environment['TESTING_MYSQL_PHASE12_PASSWORD']);
        self::assertSame('', $environment['TESTING_MYSQL_PHASE12_DISPOSABLE']);
        self::assertSame('', $environment['TESTING_MYSQL_PHASE12_AUTHORIZATION']);
        self::assertSame('', $environment['DB_URL']);
    }

    public function test_phase12_schema_identifier_inventory_fits_mysql_limits(): void
    {
        Phase12SchemaIdentifierPolicy::assertMySqlIdentifierLengths();

        self::assertNotEmpty(Phase12SchemaIdentifierPolicy::IDENTIFIERS);
        self::assertLessThanOrEqual(64, max(array_map('strlen', Phase12SchemaIdentifierPolicy::IDENTIFIERS)));
    }
}
