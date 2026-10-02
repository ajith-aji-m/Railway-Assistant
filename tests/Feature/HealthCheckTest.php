<?php

namespace Tests\Feature;

use App\Railway\Contracts\RailwayProvider;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_reports_healthy_dependencies(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('railway_provider', config('railway.provider'))
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.cache.status', 'ok')
            ->assertJsonStructure(['app', 'version', 'environment', 'timestamp', 'checks' => ['database' => ['latency_ms']]]);
    }

    public function test_returns_503_when_the_database_is_unreachable(): void
    {
        config([
            'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/database.sqlite'],
            'database.default' => 'broken',
        ]);

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('checks.database.status', 'error')
            ->assertJsonMissingPath('checks.database.message');
    }

    public function test_never_calls_the_railway_provider(): void
    {
        config(['railway.provider' => 'railradar']);
        $this->mock(RailwayProvider::class)->shouldNotReceive()->withAnyArgs();

        $this->getJson('/api/health')->assertOk();
    }

    public function test_does_not_start_a_session(): void
    {
        $this->getJson('/api/health')->assertCookieMissing(config('session.cookie'));
    }
}
