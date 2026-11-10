<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

class DatabaseFoundationTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_the_foundation_migrations_run_and_persist_a_user_record(): void
    {
        $user = User::factory()->create([
            'email' => 'foundation@example.test',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'foundation@example.test',
        ]);
        $this->assertDatabaseHas('migrations', [
            'migration' => '0001_01_01_000000_create_users_table',
        ]);
    }

    public function test_the_configured_test_connection_can_execute_a_query(): void
    {
        $this->assertSame(1, (int) DB::connection()->scalar('select 1'));
    }

    public function test_mysql_configuration_does_not_fall_back_to_root_or_a_generic_database(): void
    {
        $this->assertNotSame('root', strtolower((string) config('database.connections.mysql.username')));
        $this->assertNotSame('laravel', strtolower((string) config('database.connections.mysql.database')));
    }

    public function test_the_unique_email_constraint_is_enforced_by_the_database(): void
    {
        User::factory()->create(['email' => 'unique@example.test']);

        $this->expectException(QueryException::class);

        User::factory()->create(['email' => 'unique@example.test']);
    }

    public function test_foundation_user_email_index_is_unique(): void
    {
        $emailIndex = collect(Schema::getIndexes('users'))->firstWhere('name', 'users_email_unique');

        $this->assertIsArray($emailIndex);
        $this->assertTrue($emailIndex['unique']);
        $this->assertSame(['email'], $emailIndex['columns']);
    }

    public function test_a_failed_transaction_rolls_back_foundation_data(): void
    {
        try {
            DB::transaction(function (): void {
                User::factory()->create(['email' => 'rolled-back@example.test']);

                throw new RuntimeException('rollback verification');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback verification', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'rolled-back@example.test']);
    }
}
