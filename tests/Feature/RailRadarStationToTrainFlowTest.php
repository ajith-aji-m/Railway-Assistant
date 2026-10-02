<?php

namespace Tests\Feature;

use App\Railway\Contracts\RailwayProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Station Dashboard → today's trains → Train Details → Live Map, with RailRadar
 * (HTTP mocked from real responses for Chennai Egmore, MS).
 */
class RailRadarStationToTrainFlowTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_KEY = 'rr_test_fake_key_for_tests';

    private const FIXTURE_NOW = '2026-10-02 10:45:00';

    private const BOARD_URL = 'https://api.railradar.in/v1/stations/MS/live*';

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

        // Fixtures are real RailRadar responses from 2026-10-02 ~10:42 IST; pin "now"
        // just after them so fixes are fresh (the stale threshold is 10 minutes).
        \Carbon\CarbonImmutable::setTestNow(self::FIXTURE_NOW);
    }

    protected function tearDown(): void
    {
        \Carbon\CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/railradar/{$name}.json")), true);
    }

    private function fakeBoardAndTrains(): void
    {
        Http::fake([
            self::BOARD_URL => Http::response($this->fixture('station-live-MS')),
            'https://api.railradar.in/v1/trains/12635/live*' => Http::response($this->fixture('live-12635-not-started')),
            'https://api.railradar.in/v1/trains/12675/live*' => Http::response($this->fixture('live-12675')),
        ]);
    }

    public function test_dashboard_lists_todays_arrivals(): void
    {
        $this->fakeBoardAndTrains();

        $this->get('/stations/MS')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Stations/Show')
            ->where('station.code', 'MS')
            ->where('tab', 'arrivals')
            ->has('board', 5)
            ->where('board.0.trainNumber', '40040')
            ->where('board.2.trainNumber', '16357')
            ->where('board.3.trainNumber', '19419')
            ->where('board.3.trainName', 'Tiruchchirappalli Weekly Express')
            ->where('board.4.trainNumber', '22676'));
    }

    public function test_dashboard_lists_todays_departures_separately(): void
    {
        $this->fakeBoardAndTrains();

        $this->get('/stations/MS?tab=departures')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'departures')
            ->has('board', 5)
            ->where('board', fn ($board) => collect($board)->pluck('trainNumber')->all() === ['40040', '40039', '12635', '16357', '19419'])
            // 12635 starts here (no arrival) → only in departures; 22676 terminates here → only in arrivals.
            ->where('board', fn ($board) => ! collect($board)->contains('trainNumber', '22676')));

        Http::assertSentCount(2); // the live board + the station timetable (no per-card calls)
    }

    public function test_expected_vs_scheduled_time(): void
    {
        $this->fakeBoardAndTrains();
        $arrivals = collect(app(RailwayProvider::class)->stationBoard('MS', \App\Railway\Enums\BoardType::Arrivals))->keyBy('trainNumber');

        // Live (upcoming, +12): expected time and delay from RailRadar.
        $live = $arrivals['19419'];
        $this->assertTrue($live->isLive);
        $this->assertSame(['15:10', '15:22', 12, 'expected', '6'], [$live->scheduledTime, $live->expectedTime, $live->delayMinutes, $live->status->value, $live->platform]);

        // Not started: timetable only — no "expected" time, no "on time" claim.
        $scheduled = $arrivals['22676'];
        $this->assertFalse($scheduled->isLive);
        $this->assertSame(['19:25', null, null, 'scheduled'], [$scheduled->scheduledTime, $scheduled->expectedTime, $scheduled->delayMinutes, $scheduled->status->value]);
        $this->assertSame('4', $scheduled->platform); // timetable platform is still shown

        $this->assertSame('departed', $arrivals['40039']->status->value);
    }

    public function test_expected_time_is_derived_from_delay_when_only_delay_is_sent(): void
    {
        $fixture = $this->fixture('station-live-MS');
        foreach ($fixture['data']['trains'] as &$t) {
            if ($t['train']['number'] === '19419') {
                unset($t['live']['expectedArrivalTime']);
            }
        }
        Http::fake([self::BOARD_URL => Http::response($fixture)]);

        $entry = collect(app(RailwayProvider::class)->stationBoard('MS', \App\Railway\Enums\BoardType::Arrivals))->firstWhere('trainNumber', '19419');

        $this->assertSame('15:22', $entry->expectedTime); // 15:10 + 12 (backend, not React)
    }

    public function test_train_card_number_opens_existing_train_details_with_railradar_data(): void
    {
        $this->fakeBoardAndTrains();

        $number = null;
        $this->get('/stations/MS?tab=departures')->assertInertia(function (Assert $page) use (&$number) {
            $number = $page->toArray()['props']['board'][2]['trainNumber'];
        });
        $this->assertSame('12635', $number);

        // The card links to /trains/{number} — the existing Train Details route.
        $this->get('/trains/'.$number)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Trains/Show')
            ->where('train.number', '12635')
            ->where('train.name', 'Vaigai SF Express')
            ->where('train.from.code', 'MS')
            ->where('train.live.status', 'scheduled')     // RailRadar "not-started"
            ->where('train.live.atStation', true)          // at its origin
            ->where('train.live.nextStopSequence', fn ($seq) => $seq !== null)
            ->where('train.live.stops.0.station.code', 'MS')
            ->where('train.live.stops.0.platform', '4'));
    }

    public function test_missing_optional_fields_do_not_break_train_details(): void
    {
        // Not-started response has no previousHalt. RailRadar reports speedKmh: 0
        // for the train standing at its origin — a real value, passed through as-is.
        $this->fakeBoardAndTrains();

        $this->get('/trains/12635')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('train.live.lastStopSequence', 1) // at origin
            ->where('train.live.speedKmh', 0)
            ->where('train.live.distanceFromLastKm', null)
            ->where('train.zone', null)
            ->where('train.wifiStations', null));
    }

    public function test_not_started_train_details_use_timetable_only_convention(): void
    {
        // RailRadar echoes the timetable before departure (delay 0, actual = scheduled).
        $this->fakeBoardAndTrains();

        $this->get('/trains/12635')->assertInertia(fn (Assert $page) => $page
            ->where('train.live.status', 'scheduled')
            ->where('train.live.delayIsLive', false)            // UI shows "Scheduled", not "On time"
            ->where('train.live.stops.1.station.code', 'TBM')
            ->where('train.live.stops.1.scheduledArrival', '13:40')
            ->where('train.live.stops.1.expectedArrival', null) // no "expected" without live data
            ->where('train.live.stops.1.delayMinutes', null));
    }

    public function test_running_train_details_keep_live_delays_and_timestamp(): void
    {
        $this->fakeBoardAndTrains();

        $this->get('/trains/12675')->assertInertia(fn (Assert $page) => $page
            ->where('train.live.status', 'running')
            ->where('train.live.delayIsLive', true)
            ->where('train.live.delayMinutes', 6)
            ->where('train.live.stops.8.expectedArrival', '11:13')
            ->where('train.live.stops.8.delayMinutes', 11)
            ->where('train.live.updatedAt', '2026-10-02T10:42:12+05:30') // RailRadar lastUpdatedAt
            ->where('train.live.speedKmh', null));                       // not reported → unavailable
    }

    public function test_board_and_details_share_conventions_but_may_differ_in_delay(): void
    {
        // Two endpoints fetched at different times: values may legitimately differ.
        $board = $this->fixture('station-live-MS');
        $live = $this->fixture('live-12675');
        $board['data']['trains'][] = [
            'train' => ['number' => '12675', 'name' => 'Kovai SF Express', 'type' => 'Superfast Express', 'source' => 'MAS', 'destination' => 'CBE'],
            'stop' => ['sequence' => 1, 'arrival' => null, 'departure' => '06:10', 'day' => 1, 'platform' => '11'],
            'live' => ['type' => 'departed', 'delayMinutes' => 9, 'expectedDepartureTime' => '2026-10-02T06:19:00+05:30'],
        ];
        Http::fake([self::BOARD_URL => Http::response($board), 'https://api.railradar.in/v1/trains/12675/live*' => Http::response($live)]);

        $entry = collect(app(RailwayProvider::class)->stationBoard('MS', \App\Railway\Enums\BoardType::Departures))->firstWhere('trainNumber', '12675');
        $details = app(RailwayProvider::class)->liveStatus('12675');

        $this->assertTrue($entry->isLive);
        $this->assertTrue($details->delayIsLive);
        $this->assertSame([9, '06:19'], [$entry->delayMinutes, $entry->expectedTime]);
        $this->assertSame(6, $details->delayMinutes); // each screen shows its own endpoint's value
    }

    public function test_train_details_include_current_time_for_countdowns(): void
    {
        \Carbon\CarbonImmutable::setTestNow('2026-10-02 10:50:00');
        $this->fakeBoardAndTrains();

        $this->get('/trains/12675')->assertInertia(fn (Assert $page) => $page
            ->where('now', '2026-10-02T10:50:00+05:30')                  // real "now" (railradar)
            ->where('train.live.updatedAt', '2026-10-02T10:42:12+05:30')); // RailRadar's own timestamp kept
        \Carbon\CarbonImmutable::setTestNow();
    }

    public function test_mock_live_status_is_always_live(): void
    {
        $this->seed();
        app()->forgetInstance(RailwayProvider::class);
        config(['railway.provider' => 'mock']);
        Http::fake();

        $this->get('/trains/12675')->assertInertia(fn (Assert $page) => $page->where('train.live.delayIsLive', true));
        $this->get('/trains/12673')->assertInertia(fn (Assert $page) => $page->where('train.live.delayIsLive', true)); // mock overnight, scheduled
        Http::assertNothingSent();
    }

    public function test_no_duplicate_requests_across_the_flow(): void
    {
        $this->fakeBoardAndTrains();

        $this->get('/stations/MS')->assertOk();                 // live board + timetable (no per-card calls)
        $this->get('/stations/MS?tab=departures')->assertOk();  // cached
        $this->get('/trains/12635')->assertOk();                // 1 train request
        $this->get('/trains/12635/map')->assertOk()             // cached
            ->assertInertia(fn (Assert $page) => $page->where('train.number', '12635'));

        Http::assertSentCount(3);
    }

    public function test_live_map_keeps_the_selected_train(): void
    {
        $this->fakeBoardAndTrains();

        $this->get('/trains/12675/map')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Trains/LiveMap')
            ->where('train.number', '12675')
            ->where('live.position.lat', 11.875847));
    }

    public function test_train_not_found(): void
    {
        Http::fake(['https://api.railradar.in/v1/trains/99999/live*' => Http::response(['success' => false, 'error' => ['code' => 'NOT_FOUND']], 404)]);

        $this->get('/trains/99999')->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
    }

    public function test_train_details_401(): void
    {
        Http::fake(['https://api.railradar.in/v1/trains/12635/live*' => Http::response(['success' => false], 401)]);
        $this->assertSafeErrorPage();
    }

    public function test_train_details_429(): void
    {
        Http::fake(['https://api.railradar.in/v1/trains/12635/live*' => Http::response(['success' => false], 429)]);
        $this->assertSafeErrorPage();
    }

    public function test_train_details_503(): void
    {
        Http::fake(['https://api.railradar.in/v1/trains/12635/live*' => Http::response('degraded', 503)]);
        $this->assertSafeErrorPage();
    }

    public function test_train_details_timeout(): void
    {
        Http::fake(['https://api.railradar.in/v1/trains/12635/live*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10000 ms')]);
        $this->assertSafeErrorPage()->assertDontSee('cURL');
    }

    public function test_train_details_malformed_response(): void
    {
        Http::fake(['https://api.railradar.in/v1/trains/12635/live*' => Http::response(['success' => true, 'data' => ['trainNumber' => '12635']])]);
        $this->assertSafeErrorPage();
    }

    public function test_api_key_never_appears(): void
    {
        $this->fakeBoardAndTrains();

        foreach (['/stations/MS', '/stations/MS?tab=departures', '/trains/12635', '/trains/12635/map'] as $url) {
            $this->get($url)->assertOk()->assertDontSee(self::FAKE_KEY)->assertDontSee('Authorization');
        }
    }

    public function test_mock_station_to_train_flow_unchanged(): void
    {
        $this->seed();
        app()->forgetInstance(RailwayProvider::class);
        config(['railway.provider' => 'mock']);
        Http::fake();

        $this->get('/stations/MAS?tab=departures')->assertInertia(fn (Assert $page) => $page
            ->where('board', fn ($board) => collect($board)->firstWhere('trainNumber', '12675')['isLive'] === true
                && collect($board)->firstWhere('trainNumber', '12675')['status'] === 'departed'));
        $this->get('/trains/12675')->assertOk()->assertInertia(fn (Assert $page) => $page->where('train.name', 'Kovai Express'));

        Http::assertNothingSent();
    }

    private function assertSafeErrorPage(): TestResponse
    {
        return $this->get('/trains/12635')
            ->assertStatus(503)
            ->assertDontSee(self::FAKE_KEY)
            ->assertDontSee('api.railradar.in')
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 503));
    }
}
