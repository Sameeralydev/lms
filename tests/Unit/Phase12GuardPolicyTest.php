<?php

namespace Tests\Unit;

use Dotenv\Dotenv;
use Tests\Support\Phase12MySqlTestTarget;
use Tests\Support\Phase12SchemaIdentifierPolicy;
use Tests\TestCase;

final class Phase12GuardPolicyTest extends TestCase
{
    public function test_private_mysql_example_matches_the_guarded_schema_and_authorization(): void
    {
        $environment = Dotenv::parse((string) file_get_contents(base_path('.env.phase12.testing.example')));

        self::assertSame(Phase12MySqlTestTarget::DATABASE, $environment['DB_DATABASE']);
        self::assertSame(Phase12MySqlTestTarget::ACCOUNT, $environment['DB_USERNAME']);
        self::assertSame(Phase12MySqlTestTarget::AUTHORIZATION, $environment['PHASE12_TEST_SCHEMA_AUTHORIZATION']);
        self::assertSame('true', $environment['PHASE12_TEST_SCHEMA_DISPOSABLE']);
        self::assertSame('replace-locally', $environment['DB_PASSWORD']);
        self::assertSame('', $environment['DB_URL']);
    }

    public function test_phase12_schema_identifier_inventory_fits_mysql_limits(): void
    {
        Phase12SchemaIdentifierPolicy::assertMySqlIdentifierLengths();

        self::assertNotEmpty(Phase12SchemaIdentifierPolicy::IDENTIFIERS);
        self::assertLessThanOrEqual(64, max(array_map('strlen', Phase12SchemaIdentifierPolicy::IDENTIFIERS)));
    }
}
