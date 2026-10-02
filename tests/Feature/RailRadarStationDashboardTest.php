<?php

namespace Tests\Feature;

use App\Railway\Contracts\RailwayProvider;
use App\Railway\Enums\BoardStatus;
use App\Railway\Enums\BoardType;
use App\Railway\Exceptions\RailwayDataException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Station Dashboard backed by RailRadar `GET /v1/stations/{code}/live` (HTTP mocked). */
class RailRadarStationDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_KEY = 'rr_test_fake_key_for_tests';

    private const MAS_URL = 'https://api.railradar.in/v1/stations/MAS/live*';

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
        $this->fakeStationTimetable();
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/railradar/station-live-MAS.json')), true);
    }

    public function test_dashboard_renders_real_station_and_arrivals(): void
    {
        Http::fake([self::MAS_URL => Http::response($this->fixture())]);

        $this->get('/stations/mas')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stations/Show')
                ->where('station.code', 'MAS')
                ->where('station.name', 'MGR Chennai Central')
                ->where('station.city', 'Chennai')
                ->where('station.lat', 13.083847)
                ->where('station.state', null)
                ->where('station.platforms', null)
                ->where('station.facilities', null)
                ->where('tab', 'arrivals')
                ->where('boardError', null)
                ->has('board', 4)
                ->where('board.0.trainNumber', '22159') // scheduled 10:45 (running late)
                ->where('board.1.trainNumber', '12028')
                ->where('board.1.trainName', 'KSR Bengaluru - Chennai Central Shatabdi Express')
                ->where('board.1.scheduledTime', '11:00')
                ->where('board.1.expectedTime', '11:00')
                ->where('board.1.delayMinutes', 0)
                ->where('board.1.platform', '2')
                ->where('board.1.status', 'expected')
                ->where('board.1.phase', 'running') // live-tracked, on its way
                ->where('board.1.from.code', 'SBC')
                ->where('liveRefresh.station', 0));

        // Live board + the station timetable (cached for a day); the key only ever goes in the Authorization header.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/stations/MAS/live') && $r['hours'] === '8');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/stations/MAS/trains'));
        foreach (Http::recorded() as [$request]) {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.self::FAKE_KEY));
            $this->assertStringNotContainsString(self::FAKE_KEY, $request->url());
        }
    }

    public function test_arrivals_and_departures_are_split_and_ordered(): void
    {
        Http::fake([self::MAS_URL => Http::response($this->fixture())]);
        $railway = app(RailwayProvider::class);

        $arrivals = $railway->stationBoard('MAS', BoardType::Arrivals);
        $departures = $railway->stationBoard('MAS', BoardType::Departures);

        // Chronological by the scheduled time at this station.
        $this->assertSame(['22159', '12028', '12841', '12969'], array_column($arrivals, 'trainNumber'));
        $this->assertSame(['67789', '22160', '22637', '12969'], array_column($departures, 'trainNumber'));

        // Train 12969 calls at MAS: arrives 17:10 and departs 17:40.
        $this->assertSame(['17:10', '17:15'], [$arrivals[3]->scheduledTime, $arrivals[3]->expectedTime]);
        $this->assertSame(['17:40', '17:45'], [$departures[3]->scheduledTime, $departures[3]->expectedTime]);

        $this->assertSame(BoardStatus::Departed, $departures[0]->status);   // live.type departed
        // live.type not-started: timetable only → "Scheduled", no live expected time/delay.
        $this->assertSame(BoardStatus::Scheduled, $departures[2]->status);
        $this->assertFalse($departures[2]->isLive);
        $this->assertNull($departures[2]->expectedTime);
        $this->assertNull($departures[2]->delayMinutes);

        Http::assertSentCount(2); // both tabs share the cached live board and timetable
    }

    public function test_delay_handling(): void
    {
        $fixture = $this->fixture();
        unset($fixture['data']['trains'][0]['live']['delayMinutes'], $fixture['data']['trains'][0]['live']['expectedArrivalTime']);
        Http::fake([self::MAS_URL => Http::response($fixture)]);

        $byNumber = collect(app(RailwayProvider::class)->stationBoard('MAS', BoardType::Arrivals))->keyBy('trainNumber');

        $this->assertSame(50, $byNumber['22159']->delayMinutes);
        $this->assertSame(['10:45', '11:35'], [$byNumber['22159']->scheduledTime, $byNumber['22159']->expectedTime]);
        $this->assertSame(156, $byNumber['12841']->delayMinutes);

        // Not reported → unknown, not "on time".
        $this->assertNull($byNumber['12028']->delayMinutes);
        $this->assertNull($byNumber['12028']->expectedTime);
    }

    public function test_platform_handling(): void
    {
        $fixture = $this->fixture();
        $fixture['data']['trains'][0]['live']['platform'] = '3A';   // live platform wins over timetable
        unset($fixture['data']['trains'][1]['stop']['platform']);    // none reported at all
        Http::fake([self::MAS_URL => Http::response($fixture)]);

        $byNumber = collect(app(RailwayProvider::class)->stationBoard('MAS', BoardType::Arrivals))->keyBy('trainNumber');

        $this->assertSame('3A', $byNumber['12028']->platform);
        $this->assertNull($byNumber['22159']->platform);
        $this->assertSame('4', $byNumber['12841']->platform); // timetable platform
    }

    public function test_tab_switch_reload_uses_cache(): void
    {
        Http::fake([self::MAS_URL => Http::response($this->fixture())]);

        $this->get('/stations/MAS')->assertOk();
        $this->get('/stations/MAS?tab=departures')
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'departures')->has('board', 4));

        Http::assertSentCount(2); // live board + timetable, both reused for the second tab
    }

    public function test_unknown_station_shows_not_found_page(): void
    {
        Http::fake(['https://api.railradar.in/v1/stations/ZZZ/live*' => Http::response([
            'success' => false,
            'error' => ['code' => 'NOT_FOUND', 'message' => 'Station not found'],
        ], 404)]);

        $this->get('/stations/ZZZ')->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('Error'));
    }

    public function test_rate_limit_shows_dashboard_error_state(): void
    {
        Http::fake([self::MAS_URL => Http::response(['success' => false], 429, ['Retry-After' => '60'])]);

        $this->assertDashboardError('rate limit');
    }

    public function test_api_unavailable_shows_dashboard_error_state(): void
    {
        Http::fake([self::MAS_URL => Http::response('upstream degraded', 503)]);

        $this->assertDashboardError('temporarily unavailable');
    }

    public function test_unauthorized_shows_dashboard_error_state(): void
    {
        Http::fake([self::MAS_URL => Http::response(['success' => false], 401)]);

        $this->assertDashboardError('credentials');
    }

    public function test_timeout_shows_dashboard_error_state(): void
    {
        Http::fake([self::MAS_URL => fn () => throw new ConnectionException('cURL error 28')]);

        $this->assertDashboardError('did not respond');
    }

    public function test_malformed_response_shows_dashboard_error_state(): void
    {
        Http::fake([self::MAS_URL => Http::response(['success' => true, 'data' => ['station' => ['code' => 'MAS']]])]);

        $this->assertDashboardError('missing trains list');
    }

    public function test_invalid_station_code_makes_no_request(): void
    {
        Http::fake();
        $this->assertNull(app(RailwayProvider::class)->station('M4S!'));
        Http::assertNothingSent();
    }

    public function test_mock_dashboard_still_works(): void
    {
        $this->seed();
        app()->forgetInstance(RailwayProvider::class);
        config(['railway.provider' => 'mock']);
        Http::fake();

        $this->get('/stations/MAS')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('station.platforms', 17)
            ->where('boardError', null)
            ->where('liveRefresh.station', 60)
            ->has('board'));

        Http::assertNothingSent();
    }

    private function assertDashboardError(string $messageFragment): void
    {
        $this->get('/stations/MAS')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stations/Show')
                ->where('station.code', 'MAS') // placeholder header, page still renders
                ->where('board', [])
                ->where('boardError', fn (string $error) => str_contains($error, $messageFragment)
                    && ! str_contains($error, self::FAKE_KEY)));
    }
}
