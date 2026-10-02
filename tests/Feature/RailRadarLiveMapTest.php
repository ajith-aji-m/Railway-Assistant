<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Railway\Contracts\RailwayProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Live Train Map backed by RailRadar `GET /v1/trains/{number}/live` (HTTP mocked). */
class RailRadarLiveMapTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_KEY = 'rr_test_fake_key_for_tests';

    private const LIVE_URL = 'https://api.railradar.in/v1/trains/12675/live*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'railway.provider' => 'railradar',
            'services.railradar.key' => self::FAKE_KEY,
            'services.railradar.base_url' => 'https://api.railradar.in',
            'services.railradar.cache_seconds' => 60,
        ]);
        Http::preventStrayRequests();
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/railradar/live-12675.json')), true);
    }

    private function fakeLive(?array $fixture = null): void
    {
        Http::fake([self::LIVE_URL => Http::response($fixture ?? $this->fixture())]);
    }

    public function test_live_map_renders_railradar_data(): void
    {
        $this->fakeLive();

        $this->get('/trains/12675/map')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Trains/LiveMap')
                ->where('train.number', '12675')
                ->where('live.status', 'running')
                ->where('live.delayMinutes', 6)
                ->where('live.updatedAt', '2026-10-02T10:42:12+05:30'));
    }

    public function test_train_marker_uses_real_coordinates(): void
    {
        $fixture = $this->fixture();
        $this->fakeLive($fixture);

        $real = $fixture['data']['currentLocation']['coordinates'];

        $this->get('/trains/12675/map')->assertInertia(fn (Assert $page) => $page
            // The marker is drawn at live.position, which is RailRadar's coordinates verbatim.
            ->where('live.position.lat', $real['lat'])
            ->where('live.position.lng', $real['lng'])
            ->where('live.position', ['lat' => 11.875847, 'lng' => 78.15302]));
    }

    public function test_current_location_comes_from_railradar(): void
    {
        $this->fakeLive();

        $this->get('/trains/12675/map')->assertInertia(fn (Assert $page) => $page
            ->where('live.currentLocation.station.code', 'LCR')
            ->where('live.currentLocation.station.name', 'Lokur')
            ->where('live.currentLocation.distanceFromStationKm', 6.8)
            ->where('live.lastStopSequence', 63)   // previous halt: Morappur
            ->where('live.nextStopSequence', 72)); // next halt: Salem Jn
    }

    public function test_route_geometry_and_station_markers(): void
    {
        $fixture = $this->fixture();
        $this->fakeLive($fixture);
        $firstRoutePoint = [$fixture['data']['route'][0]['lat'], $fixture['data']['route'][0]['lng']];

        $this->get('/trains/12675/map')->assertInertia(fn (Assert $page) => $page
            // Route line: every RailRadar route point (halts and pass-through stations).
            ->has('train.route', 15)
            ->where('train.route.0', $firstRoutePoint)
            // Station markers: halts with RailRadar coordinates.
            ->has('live.stops', 12)
            ->where('live.stops.0.station.code', 'MAS')
            ->where('live.stops.0.lat', 13.083847)
            ->where('live.stops.0.lng', 80.275512)
            ->where('live.stops.8.station.code', 'SA')
            ->where('live.stops.8.state', 'next'));
    }

    public function test_gps_active_state(): void
    {
        $this->fakeLive();

        $this->get('/trains/12675/map')->assertInertia(fn (Assert $page) => $page->where('live.gps', 'active'));
    }

    public function test_missing_coordinates_mean_no_marker_and_gps_not_active(): void
    {
        $fixture = $this->fixture();
        unset($fixture['data']['currentLocation']['coordinates']);
        $this->fakeLive($fixture);

        $this->get('/trains/12675/map')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('live.position', null)
            ->where('live.gps', 'lost')
            ->has('live.stops', 12));
    }

    public function test_non_realtime_tracking_is_not_reported_as_active(): void
    {
        $fixture = $this->fixture();
        $fixture['data']['trackingMode'] = 'estimated';
        $this->fakeLive($fixture);

        $this->get('/trains/12675/map')->assertInertia(fn (Assert $page) => $page->where('live.gps', 'lost'));
    }

    public function test_missing_speed_stays_null(): void
    {
        $this->fakeLive();

        $this->get('/trains/12675/map')->assertInertia(fn (Assert $page) => $page->where('live.speedKmh', null));
    }

    public function test_unknown_train(): void
    {
        Http::fake(['https://api.railradar.in/v1/trains/99999/live*' => Http::response(['success' => false, 'error' => ['code' => 'NOT_FOUND']], 404)]);

        $this->get('/trains/99999/map')->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
    }

    public function test_authentication_error(): void
    {
        Http::fake([self::LIVE_URL => Http::response(['success' => false], 401)]);
        $this->assertErrorPage();
    }

    public function test_rate_limit(): void
    {
        Http::fake([self::LIVE_URL => Http::response(['success' => false], 429, ['Retry-After' => '60'])]);
        $this->assertErrorPage()->assertHeader('Retry-After', '60');
    }

    public function test_api_unavailable(): void
    {
        Http::fake([self::LIVE_URL => Http::response('degraded', 503)]);
        $this->assertErrorPage();
    }

    public function test_timeout(): void
    {
        Http::fake([self::LIVE_URL => fn () => throw new ConnectionException('cURL error 28')]);
        $this->assertErrorPage();
    }

    public function test_malformed_response(): void
    {
        Http::fake([self::LIVE_URL => Http::response(['success' => true, 'data' => ['trainNumber' => '12675', 'route' => 'nope']])]);
        $this->assertErrorPage();
    }

    public function test_one_upstream_request_per_visit(): void
    {
        // Even with the cache disabled, `train` and `live` share one request.
        config(['services.railradar.cache_seconds' => 0]);
        $this->fakeLive();

        $this->get('/trains/12675/map')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_cache_reuse(): void
    {
        $this->fakeLive();

        // Map visit, a background refresh and Train Details within 60s → one request.
        $this->get('/trains/12675/map')->assertOk();
        $this->partialReload()->assertOk()->assertJsonPath('props.live.position.lat', 11.875847);
        $this->get('/trains/12675')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_failed_background_refresh_keeps_existing_map(): void
    {
        Http::fake([self::LIVE_URL => Http::sequence()
            ->push($this->fixture())
            ->push(['success' => false], 429)]);
        $this->get('/trains/12675/map')->assertOk()->assertInertia(fn (Assert $page) => $page->has('live.position'));

        // Cache expires, then the next poll hits a rate limit.
        cache()->flush();

        $this->partialReload()
            ->assertStatus(503)
            ->assertHeaderMissing('X-Inertia') // not an Inertia page → client keeps current map
            ->assertJsonMissingPath('component')
            ->assertDontSee(self::FAKE_KEY);
    }

    public function test_mock_map_still_works(): void
    {
        $this->seed();
        app()->forgetInstance(RailwayProvider::class);
        config(['railway.provider' => 'mock']);
        Http::fake();

        $this->get('/trains/12675/map')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Trains/LiveMap')
            ->has('train.route', 8)
            ->has('live.position')
            ->where('live.currentLocation', null)
            ->where('liveRefresh.map', 15));

        Http::assertNothingSent();
    }

    public function test_railradar_map_refresh_interval_is_configurable(): void
    {
        $this->fakeLive();

        $this->get('/trains/12675/map')->assertInertia(fn (Assert $page) => $page->where('liveRefresh.map', 300));

        config(['railway.auto_refresh_seconds.railradar.map' => 600]);
        $this->get('/trains/12675/map')->assertInertia(fn (Assert $page) => $page->where('liveRefresh.map', 600));
    }

    private function partialReload(): TestResponse
    {
        return $this->get('/trains/12675/map', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Trains/LiveMap',
            'X-Inertia-Partial-Data' => 'live',
        ]);
    }

    private function assertErrorPage(): TestResponse
    {
        return $this->get('/trains/12675/map')
            ->assertStatus(503)
            ->assertDontSee(self::FAKE_KEY)
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 503));
    }
}
