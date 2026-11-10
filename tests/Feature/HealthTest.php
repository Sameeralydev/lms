<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    private const DIAGNOSTICS_TOKEN = 'phase-one-test-token-32-characters-minimum';

    public function test_public_liveness_returns_only_a_generic_status(): void
    {
        $this->getJson('/health/live')
            ->assertOk()
            ->assertExactJson(['status' => 'ok'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_detailed_readiness_is_hidden_without_a_valid_token(): void
    {
        config(['health.diagnostics_token' => self::DIAGNOSTICS_TOKEN]);

        $this->getJson('/internal/health/ready')
            ->assertNotFound()
            ->assertDontSee('database');

        $this->withToken('wrong-token')->getJson('/internal/health/ready')->assertNotFound();
    }

    public function test_readiness_reports_only_component_states_to_an_authorized_caller(): void
    {
        config(['health.diagnostics_token' => self::DIAGNOSTICS_TOKEN]);

        $this->withToken(self::DIAGNOSTICS_TOKEN)
            ->getJson('/internal/health/ready')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ready',
                'checks' => ['database' => 'ok', 'cache' => 'ok'],
            ])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_readiness_fails_closed_without_disclosing_database_errors(): void
    {
        config(['health.diagnostics_token' => self::DIAGNOSTICS_TOKEN]);
        DB::shouldReceive('connection')
            ->once()
            ->andThrow(new RuntimeException('Synthetic database connection failure.'));

        $this->withToken(self::DIAGNOSTICS_TOKEN)
            ->getJson('/internal/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('checks.database', 'unavailable')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee(storage_path());
    }

    public function test_readiness_fails_when_the_configured_cache_store_is_unavailable(): void
    {
        config([
            'health.diagnostics_token' => self::DIAGNOSTICS_TOKEN,
            'cache.default' => 'unavailable',
            'cache.stores.unavailable' => ['driver' => 'phase01_unavailable'],
        ]);

        $this->withToken(self::DIAGNOSTICS_TOKEN)
            ->getJson('/internal/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.cache', 'unavailable')
            ->assertDontSee('SQLSTATE');
    }
}
