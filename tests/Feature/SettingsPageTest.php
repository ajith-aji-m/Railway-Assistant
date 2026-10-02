<?php

namespace Tests\Feature;

use App\Railway\Contracts\RailwayProvider;
use App\Railway\Mock\MockRailwayProvider;
use App\Railway\RailRadar\RailRadarProvider;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Settings: local preferences only; no API calls, no provider switch. */
class SettingsPageTest extends TestCase
{
    private const FAKE_KEY = 'rr_test_fake_key_for_tests';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.railradar.key' => self::FAKE_KEY]);
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_settings_page_renders_in_demo_mode(): void
    {
        $this->get('/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings')
            ->where('dataSource', 'demo')
            ->where('app.name', 'Railway Assistant')
            ->where('app.version', '1.0'));

        Http::assertNothingSent();
    }

    public function test_settings_page_shows_live_data_in_railradar_mode_without_api_calls(): void
    {
        config(['railway.provider' => 'railradar']);

        $this->get('/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings')
            ->where('dataSource', 'live'));

        Http::assertNothingSent();
    }

    public function test_provider_is_not_exposed_or_switchable(): void
    {
        config(['railway.provider' => 'railradar']);

        $response = $this->get('/settings?provider=mock')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('dataSource', 'live') // query string has no effect
            ->missing('provider')
            ->missing('railwayProvider'));

        $html = $response->getContent();
        $this->assertStringNotContainsString('RAILWAY_PROVIDER', $html);
        $props = $this->inertiaProps($html);
        $this->assertArrayHasKey('dataSource', $props, 'page props were parsed');
        $this->assertStringNotContainsString('railradar', strtolower(json_encode($props)));
        $this->assertStringNotContainsString('api.railradar.in', $html);

        // There is no endpoint that writes settings or switches providers.
        $this->post('/settings', ['provider' => 'mock'])->assertStatus(405);
        $this->assertSame('railradar', config('railway.provider'));
        $this->assertInstanceOf(RailRadarProvider::class, app(RailwayProvider::class));
    }

    public function test_api_key_never_appears(): void
    {
        config(['railway.provider' => 'railradar']);

        $this->get('/settings')->assertDontSee(self::FAKE_KEY);
        $this->get('/settings', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request())])
            ->assertDontSee(self::FAKE_KEY);
    }

    public function test_mock_mode_unchanged(): void
    {
        $this->assertSame('mock', config('railway.provider'));
        $this->assertInstanceOf(MockRailwayProvider::class, app(RailwayProvider::class));
        $this->get('/settings')->assertInertia(fn (Assert $page) => $page->where('liveRefresh.station', 60)->where('liveRefresh.train', 30)->where('liveRefresh.map', 15));
    }

    public function test_railradar_mode_unchanged(): void
    {
        config(['railway.provider' => 'railradar']);

        $this->get('/settings')->assertInertia(fn (Assert $page) => $page->where('liveRefresh.station', 0)->where('liveRefresh.train', 300)->where('liveRefresh.map', 300));
        $this->assertInstanceOf(RailRadarProvider::class, app(RailwayProvider::class));
    }

    /** @return array<string, mixed> */
    private function inertiaProps(string $html): array
    {
        preg_match('/<script data-page="app" type="application\/json">(.*?)<\/script>/s', $html, $m);

        return json_decode(html_entity_decode($m[1] ?? '{}'), true)['props'] ?? [];
    }
}
