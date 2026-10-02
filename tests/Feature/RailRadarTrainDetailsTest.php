<?php

namespace Tests\Feature;

use App\Railway\Contracts\RailwayProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Train Details page backed by RailRadar `GET /v1/trains/{number}/live` (HTTP mocked). */
class RailRadarTrainDetailsTest extends TestCase
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

    private function fakeLive(array $fixture = null): void
    {
        Http::fake([self::LIVE_URL => Http::response($fixture ?? $this->fixture())]);
    }

    public function test_train_details_page_uses_real_live_data(): void
    {
        $this->fakeLive();

        $this->get('/trains/12675')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Trains/Show')
                ->where('train.number', '12675')
                ->where('train.name', 'Kovai SF Express')
                ->where('train.type', 'Superfast Express')
                ->where('train.from.code', 'MAS')
                ->where('train.to.code', 'CBE')
                ->where('train.live.status', 'running')
                ->where('train.live.updatedAt', '2026-10-02T10:42:12+05:30')
                ->where('train.zone', null)
                ->where('train.wifiStations', null)
                ->where('liveRefresh.train', 300));

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::FAKE_KEY)
            && ! str_contains($r->url(), self::FAKE_KEY));
    }

    public function test_delay(): void
    {
        $this->fakeLive();

        $live = app(RailwayProvider::class)->liveStatus('12675');

        $this->assertSame(6, $live->delayMinutes);
        $salem = collect($live->stops)->firstWhere('sequence', 72);
        $this->assertSame(11, $salem->delayMinutes);
    }

    public function test_unknown_stop_delay_is_null_not_on_time(): void
    {
        $fixture = $this->fixture();
        foreach ($fixture['data']['route'] as &$stop) {
            if ($stop['stationCode'] === 'SA') {
                unset($stop['delayArrival'], $stop['delayDeparture']);
            }
        }
        $this->fakeLive($fixture);

        $salem = collect(app(RailwayProvider::class)->liveStatus('12675')->stops)->firstWhere('sequence', 72);

        $this->assertNull($salem->delayMinutes);
    }

    public function test_current_location_and_coordinates(): void
    {
        $this->fakeLive();

        $this->get('/trains/12675')->assertInertia(fn (Assert $page) => $page
            ->where('train.live.currentLocation.station.code', 'LCR')
            ->where('train.live.currentLocation.station.name', 'Lokur')
            ->where('train.live.currentLocation.isHalt', false)
            ->where('train.live.currentLocation.distanceFromStationKm', 6.8) // 6.75 km
            ->where('train.live.position.lat', 11.875847)
            ->where('train.live.position.lng', 78.15302)
            ->where('train.live.gps', 'active')
            ->where('train.live.atStation', false));
    }

    public function test_missing_coordinates_stay_null(): void
    {
        $fixture = $this->fixture();
        unset($fixture['data']['currentLocation']['coordinates']);
        $this->fakeLive($fixture);

        $this->assertNull(app(RailwayProvider::class)->liveStatus('12675')->position);
    }

    public function test_previous_and_next_station(): void
    {
        $this->fakeLive();

        $this->get('/trains/12675')->assertInertia(fn (Assert $page) => $page
            ->where('train.live.lastStopSequence', 63)
            ->where('train.live.nextStopSequence', 72)
            ->where('train.live.distanceFromLastKm', 39.7)
            ->where('train.live.distanceToNextKm', 26.5)
            ->where('train.live.stops.7.station.code', 'MAP')
            ->where('train.live.stops.7.state', 'departed')
            ->where('train.live.stops.8.station.code', 'SA')
            ->where('train.live.stops.8.state', 'next'));
    }

    public function test_platform_and_timetable(): void
    {
        $this->fakeLive();

        $this->get('/trains/12675')->assertInertia(fn (Assert $page) => $page
            ->where('train.departs', '06:10')
            ->where('train.arrives', '14:05')
            ->has('train.live.stops', 12)
            // Origin: departure only, actual 06:18 (+9), platform 11
            ->where('train.live.stops.0.scheduledArrival', null)
            ->where('train.live.stops.0.scheduledDeparture', '06:10')
            ->where('train.live.stops.0.expectedDeparture', '06:18')
            ->where('train.live.stops.0.platform', '11')
            ->where('train.live.stops.0.delayMinutes', 9)
            // Next stop Salem: scheduled 11:02/11:05, predicted 11:13/11:16, platform 4
            ->where('train.live.stops.8.scheduledArrival', '11:02')
            ->where('train.live.stops.8.expectedArrival', '11:13')
            ->where('train.live.stops.8.scheduledDeparture', '11:05')
            ->where('train.live.stops.8.platform', '4')
            ->where('train.live.stops.8.distanceKm', 334));
    }

    public function test_live_speed_is_null_when_not_reported(): void
    {
        $this->fakeLive();

        $live = app(RailwayProvider::class)->liveStatus('12675');

        // The fixture has route[].speedToNextStationKmph (timetable average) but no live speed.
        $this->assertNotEmpty(array_filter(array_column($this->fixture()['data']['route'], 'speedToNextStationKmph')));
        $this->assertNull($live->speedKmh);
    }

    public function test_live_speed_is_used_only_when_reported(): void
    {
        $fixture = $this->fixture();
        $fixture['data']['currentLocation']['speedKmh'] = 72.4;
        $this->fakeLive($fixture);

        $this->assertSame(72, app(RailwayProvider::class)->liveStatus('12675')->speedKmh);
    }

    public function test_unknown_train_shows_not_found_page(): void
    {
        Http::fake(['https://api.railradar.in/v1/trains/99999/live*' => Http::response([
            'success' => false,
            'error' => ['code' => 'NOT_FOUND', 'message' => 'Train 99999 not found'],
        ], 404)]);

        $this->get('/trains/99999')->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
    }

    public function test_authentication_error(): void
    {
        Http::fake([self::LIVE_URL => Http::response(['success' => false], 401)]);
        $this->assertErrorPage();
    }

    public function test_rate_limit(): void
    {
        Http::fake([self::LIVE_URL => Http::response(['success' => false], 429, ['Retry-After' => '90'])]);
        $this->assertErrorPage()->assertHeader('Retry-After', '90');
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
        Http::fake([self::LIVE_URL => Http::response(['success' => true, 'data' => ['trainNumber' => '12675']])]);
        $this->assertErrorPage();
    }

    public function test_failed_background_refresh_keeps_current_page(): void
    {
        Http::fake([self::LIVE_URL => Http::response(['success' => false], 429)]);

        $this->get('/trains/12675', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Trains/Show',
            'X-Inertia-Partial-Data' => 'train',
        ])
            ->assertStatus(503)
            ->assertHeaderMissing('X-Inertia')
            ->assertJson(['message' => 'Railway data provider rate limit reached. Please try again later.']);
    }

    public function test_cache_reuse(): void
    {
        $this->fakeLive();

        $this->get('/trains/12675')->assertOk();
        $this->get('/trains/12675')->assertOk();
        app(RailwayProvider::class)->liveStatus('12675');

        Http::assertSentCount(1);
    }

    public function test_mock_train_details_unchanged(): void
    {
        $this->seed();
        app()->forgetInstance(RailwayProvider::class);
        config(['railway.provider' => 'mock']);
        Http::fake();

        $this->get('/trains/12675')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('train.name', 'Kovai Express')
            ->where('train.zone', 'SR')
            ->where('train.hasPantry', true)
            ->where('train.live.currentLocation', null)
            ->where('liveRefresh.train', 30));

        Http::assertNothingSent();
    }

    private function assertErrorPage(): \Illuminate\Testing\TestResponse
    {
        return $this->get('/trains/12675')
            ->assertStatus(503)
            ->assertDontSee(self::FAKE_KEY)
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 503));
    }
}
