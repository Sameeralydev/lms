<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/** Preserves Laravel RefreshDatabase behavior except for pre-attested phase-specific MySQL schemas. */
trait GuardedRefreshDatabase
{
    use RefreshDatabase {
        refreshTestDatabase as protected refreshTestDatabaseWithLaravelMigrations;
    }

    protected function refreshTestDatabase(): void
    {
        if (config('database.default') === 'mysql'
            && config('database.connections.mysql.database') === Phase06MySqlTestTarget::DATABASE) {
            $connection = DB::connection('mysql');
            Phase06ProbeAuthorization::assertAuthorized($connection->getPdo(), TestDatabaseGuard::currentEnvironment());

            if (! RefreshDatabaseState::$migrated) {
                $this->updateLocalCacheOfInMemoryDatabases();
                RefreshDatabaseState::$migrated = true;
            }

            $this->beginDatabaseTransaction();

            return;
        }

        if (config('database.default') === 'mysql'
            && config('database.connections.mysql.database') === Phase07MySqlTestTarget::DATABASE) {
            $connection = DB::connection('mysql');
            Phase07ProbeAuthorization::assertAuthorized($connection->getPdo(), TestDatabaseGuard::currentEnvironment());

            if (! RefreshDatabaseState::$migrated) {
                $this->updateLocalCacheOfInMemoryDatabases();
                RefreshDatabaseState::$migrated = true;
            }

            $this->beginDatabaseTransaction();

            return;
        }

        if (config('database.default') === 'mysql'
            && config('database.connections.mysql.database') === Phase08MySqlTestTarget::DATABASE) {
            $connection = DB::connection('mysql');
            Phase08ProbeAuthorization::assertAuthorized($connection->getPdo(), TestDatabaseGuard::currentEnvironment());
            if (! RefreshDatabaseState::$migrated) {
                $this->updateLocalCacheOfInMemoryDatabases();
                RefreshDatabaseState::$migrated = true;
            }
            $this->beginDatabaseTransaction();

            return;
        }

        if (config('database.default') === 'mysql'
            && config('database.connections.mysql.database') === Phase09MySqlTestTarget::DATABASE) {
            $connection = DB::connection('mysql');
            Phase09ProbeAuthorization::assertAuthorized($connection->getPdo(), TestDatabaseGuard::currentEnvironment());
            if (! RefreshDatabaseState::$migrated) {
                $this->updateLocalCacheOfInMemoryDatabases();
                RefreshDatabaseState::$migrated = true;
            }
            $this->beginDatabaseTransaction();

            return;
        }

        if (config('database.default') === 'mysql'
            && config('database.connections.mysql.database') === Phase10MySqlTestTarget::DATABASE) {
            $connection = DB::connection('mysql');
            Phase10ProbeAuthorization::assertAuthorized($connection->getPdo(), TestDatabaseGuard::currentEnvironment());
            if (! RefreshDatabaseState::$migrated) {
                $this->updateLocalCacheOfInMemoryDatabases();
                RefreshDatabaseState::$migrated = true;
            }
            $this->beginDatabaseTransaction();

            return;
        }

        if (config('database.default') === 'mysql'
            && config('database.connections.mysql.database') === Phase11MySqlTestTarget::DATABASE) {
            $connection = DB::connection('mysql');
            Phase11ProbeAuthorization::assertAuthorized($connection->getPdo(), TestDatabaseGuard::currentEnvironment());
            if (! RefreshDatabaseState::$migrated) {
                $this->updateLocalCacheOfInMemoryDatabases();
                RefreshDatabaseState::$migrated = true;
            }
            $this->beginDatabaseTransaction();

            return;
        }

        if (config('database.default') === 'mysql'
            && config('database.connections.mysql.database') === Phase12MySqlTestTarget::DATABASE) {
            $connection = DB::connection('mysql');
            Phase12ProbeAuthorization::assertAuthorized($connection->getPdo(), TestDatabaseGuard::currentEnvironment());
            if (! RefreshDatabaseState::$migrated) {
                $this->updateLocalCacheOfInMemoryDatabases();
                RefreshDatabaseState::$migrated = true;
            }
            $this->beginDatabaseTransaction();

            return;
        }

        if (config('database.default') === 'mysql'
            && config('database.connections.mysql.database') === Phase13MySqlTestTarget::DATABASE) {
            $connection = DB::connection('mysql');
            Phase13ProbeAuthorization::assertAuthorized($connection->getPdo(), TestDatabaseGuard::currentEnvironment());
            if (! RefreshDatabaseState::$migrated) {
                $this->updateLocalCacheOfInMemoryDatabases();
                RefreshDatabaseState::$migrated = true;
            }
            $this->beginDatabaseTransaction();

            return;
        }

        if (config('database.default') === 'mysql'
            && config('database.connections.mysql.database') === Phase14MySqlTestTarget::DATABASE) {
            $connection = DB::connection('mysql');
            Phase14ProbeAuthorization::assertAuthorized($connection->getPdo(), TestDatabaseGuard::currentEnvironment());
            if (! RefreshDatabaseState::$migrated) {
                $this->updateLocalCacheOfInMemoryDatabases();
                RefreshDatabaseState::$migrated = true;
            }
            $this->beginDatabaseTransaction();

            return;
        }

        $this->refreshTestDatabaseWithLaravelMigrations();
    }
}
