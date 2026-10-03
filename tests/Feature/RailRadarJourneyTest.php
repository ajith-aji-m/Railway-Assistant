<?php

namespace Tests\Feature;

use App\Railway\Contracts\RailwayProvider;
use App\Railway\Enums\BoardPhase;
use App\Railway\Enums\BoardStatus;
use App\Railway\Enums\BoardType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * From → To with RailRadar. Route order comes from the two stations' timetables
 * (GET /v1/stations/{code}/trains, cached for a day — the same request as the
 * full-day board); the From station's live board (GET /v1/stations/{code}/live,
 * 60-second cache) adds status/delay/platform only when a train serves the route.
 * Payloads use the shape of the real responses in tests/Fixtures/railradar
 * (station-trains-MAS.json, station-live-MAS.json). 2026-10-02 is a Friday.
 */
class RailRadarJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'railway.provider' => 'railradar',
            'services.railradar.key' => 'rr_test_fake_key_for_tests',
            'services.railradar.base_url' => 'https://api.railradar.in',
            'services.railradar.cache_seconds' => 60,
            'services.railradar.search_cache_seconds' => 86400,
        ]);
        Http::preventStrayRequests();
        CarbonImmutable::setTestNow('2026-10-02 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private const DAILY = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private function stopCall(string $number, string $name, array $stop, array $runDays = self::DAILY): array
    {
        return [
            'train' => [
                'number' => $number,
                'name' => $name,
                'type' => 'Express',
                'source' => ['code' => 'TVC', 'name' => 'Trivandrum Central'],
                'destination' => ['code' => 'NCJ', 'name' => 'Nagercoil Jn'],
                'runDays' => $runDays,
            ],
            'stop' => $stop + ['arrivalDay' => 1, 'departureDay' => 1, 'distance' => 50, 'stopType' => 'halt'],
        ];
    }

    private function timetable(string $code, string $name, array $trains): array
    {
        return [
            'success' => true,
            'data' => ['station' => ['code' => $code, 'name' => $name], 'trains' => $trains, 'count' => count($trains), 'includeIntermediate' => false],
            'meta' => ['traceId' => 'test'],
        ];
    }

    /** PYD and ERL timetables: 16724 calls at PYD (seq 12) then ERL (seq 13); 16723 the other way round. */
    private function fakeLine(array $live = []): void
    {
        Http::fake([
            'https://api.railradar.in/v1/stations/PYD/trains*' => Http::response($this->timetable('PYD', 'Palliyadi', [
                $this->stopCall('16724', 'Ananthapuri Express', ['sequence' => 12, 'arrival' => '14:14', 'departure' => '14:15']),
                $this->stopCall('16723', 'Ananthapuri Express', ['sequence' => 41, 'arrival' => '06:10', 'departure' => '06:11']),
                $this->stopCall('56310', 'Local Passenger', ['sequence' => 3, 'arrival' => '09:00', 'departure' => '09:01']), // never calls at ERL
                $this->stopCall('16999', 'Weekend Express', ['sequence' => 5, 'arrival' => '18:00', 'departure' => '18:01'], ['sun']), // not today
            ])),
            'https://api.railradar.in/v1/stations/ERL/trains*' => Http::response($this->timetable('ERL', 'Eraniel', [
                $this->stopCall('16724', 'Ananthapuri Express', ['sequence' => 13, 'arrival' => '14:31', 'departure' => '14:32']),
                $this->stopCall('16723', 'Ananthapuri Express', ['sequence' => 40, 'arrival' => '05:55', 'departure' => '05:56']),
                $this->stopCall('16999', 'Weekend Express', ['sequence' => 6, 'arrival' => '18:20', 'departure' => '18:21'], ['sun']),
            ])),
            'https://api.railradar.in/v1/stations/MAS/trains*' => Http::response($this->timetable('MAS', 'MGR Chennai Central', [])),
            'https://api.railradar.in/v1/stations/PYD/live*' => Http::response([
                'success' => true,
                'data' => ['station' => ['code' => 'PYD', 'name' => 'Palliyadi'], 'window' => [], 'count' => count($live), 'trains' => $live],
                'meta' => ['traceId' => 'test'],
            ]),
            'https://api.railradar.in/v1/stations/ERL/live*' => Http::response([
                'success' => true,
                'data' => ['station' => ['code' => 'ERL', 'name' => 'Eraniel'], 'window' => [], 'count' => 0, 'trains' => []],
                'meta' => ['traceId' => 'test'],
            ]),
            'https://api.railradar.in/v1/lookup/search/stations*' => Http::response(['success' => true, 'data' => [
                ['code' => 'ERL', 'name' => 'Eraniel', 'city' => 'Eraniel', 'isActive' => true],
            ], 'meta' => []]),
        ]);
    }

    private function journeys(string $from = 'PYD', string $to = 'ERL'): array
    {
        return app(RailwayProvider::class)->journeys($from, $to);
    }

    private function sent(string $path): int
    {
        return count(Http::recorded(fn (Request $r) => str_contains($r->url(), $path)));
    }

    public function test_only_trains_calling_at_from_before_to_are_returned(): void
    {
        $this->fakeLine();
        $journeys = $this->journeys();

        $this->assertSame(['16724'], array_map(fn ($j) => $j->departure->trainNumber, $journeys));
        $journey = $journeys[0];
        $this->assertSame('14:15', $journey->departure->scheduledTime);
        $this->assertSame('14:31', $journey->arrives);
        $this->assertSame('Palliyadi', $journey->boarding->name);
        $this->assertSame('Eraniel', $journey->alighting->name);
        // Timetable only (no live entry): never shown as expected / on time.
        $this->assertSame(BoardStatus::Scheduled, $journey->departure->status);
        $this->assertSame(BoardPhase::Upcoming, $journey->departure->phase);
        $this->assertFalse($journey->departure->isLive);
        $this->assertNull($journey->departure->delayMinutes);
        $this->assertNull($journey->expectedArrival);
    }

    public function test_train_with_to_before_from_is_excluded(): void
    {
        $this->fakeLine();

        // 16723 calls at Eraniel (40) before Palliyadi (41).
        $this->assertSame(['16723'], array_map(fn ($j) => $j->departure->trainNumber, $this->journeys('ERL', 'PYD')));
        $this->assertNotContains('16723', array_map(fn ($j) => $j->departure->trainNumber, $this->journeys()));
    }

    public function test_live_board_adds_status_delay_and_platform(): void
    {
        $this->fakeLine([[
            'train' => ['number' => '16724', 'name' => 'Ananthapuri Express', 'type' => 'Express', 'source' => 'TVC', 'destination' => 'NCJ'],
            'stop' => ['sequence' => 12, 'arrival' => '14:14', 'departure' => '14:15', 'day' => 1, 'isHalt' => true, 'platform' => '1'],
            'live' => ['type' => 'upcoming', 'startDate' => '2026-10-02', 'expectedDepartureTime' => '2026-10-02T14:22:00+05:30', 'delayMinutes' => 7, 'platform' => '2'],
        ]]);
        $journey = $this->journeys()[0];

        $this->assertTrue($journey->departure->isLive);
        $this->assertSame(7, $journey->departure->delayMinutes);
        $this->assertSame('14:22', $journey->departure->expectedTime);
        $this->assertSame('2', $journey->departure->platform);
        $this->assertSame(BoardPhase::Running, $journey->departure->phase);
    }

    public function test_route_without_trains_makes_no_live_request(): void
    {
        $this->fakeLine();

        $this->assertSame([], $this->journeys('PYD', 'MAS'));
        $this->assertSame(0, $this->sent('/live'));
        $this->assertSame(2, count(Http::recorded()));
    }

    public function test_requests_are_cached_and_shared_with_the_station_board(): void
    {
        $this->fakeLine();

        $this->journeys();
        $this->assertSame(1, $this->sent('/v1/stations/PYD/trains'));
        $this->assertSame(1, $this->sent('/v1/stations/ERL/trains'));
        $this->assertSame(1, $this->sent('/v1/stations/PYD/live'));

        // Repeat search and the From station's dashboard: everything from the cache.
        $this->journeys();
        app(RailwayProvider::class)->stationBoard('PYD', BoardType::Departures);
        $this->assertSame(3, count(Http::recorded()));

        // After the 60-second live cache expires only the live board is fetched again;
        // the timetables stay cached for a day.
        $this->travel(2)->minutes();
        $this->journeys();
        $this->assertSame(1, $this->sent('/v1/stations/PYD/trains'));
        $this->assertSame(1, $this->sent('/v1/stations/ERL/trains'));
        $this->assertSame(2, $this->sent('/v1/stations/PYD/live'));
    }

    public function test_live_board_failure_falls_back_to_the_timetable(): void
    {
        Http::fake(['https://api.railradar.in/v1/stations/PYD/live*' => Http::response(['success' => false], 503)]);
        $this->fakeLine();

        $journeys = $this->journeys();

        $this->assertSame(['16724'], array_map(fn ($j) => $j->departure->trainNumber, $journeys));
        $this->assertFalse($journeys[0]->departure->isLive);
    }

    public function test_unknown_station_returns_no_trains(): void
    {
        Http::fake(['https://api.railradar.in/v1/stations/*/trains*' => Http::response(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Not found']], 404)]);

        $this->assertSame([], $this->journeys('PYD', 'XYZ'));
    }

    public function test_page_makes_no_requests_until_both_stations_are_selected(): void
    {
        $this->seed();
        $this->fakeLine();

        // From suggestions: local directory + Haversine only.
        $this->get('/journey?lat=8.2660&lng=77.2610&from=PYD')->assertInertia(fn (Assert $page) => $page
            ->where('nearby.0.code', 'PYD')
            ->where('from.name', 'Palliyadi')
            ->where('journeys', null));
        // Short destination queries never reach RailRadar.
        $this->get('/journey?from=PYD&q=E')->assertInertia(fn (Assert $page) => $page
            ->where('searchMinLength', 2)
            ->where('searchDebounceMs', 400)
            ->where('searchResults', null));

        Http::assertNothingSent();
    }

    public function test_destination_search_uses_the_existing_station_lookup(): void
    {
        $this->seed();
        $this->fakeLine();

        $this->get('/journey?from=PYD&q=Eraniel')->assertInertia(fn (Assert $page) => $page
            ->where('searchResults.0.code', 'ERL')
            ->where('journeys', null));
        $this->get('/journey?from=PYD&q=Eraniel')->assertOk(); // cached for a day

        $this->assertSame(1, count(Http::recorded()));
        $this->assertSame(1, $this->sent('/v1/lookup/search/stations'));
    }

    public function test_journey_page_lists_the_route(): void
    {
        $this->seed();
        $this->fakeLine();

        $this->get('/journey?from=PYD&to=ERL')->assertInertia(fn (Assert $page) => $page
            ->component('Journey/Index')
            ->has('journeys', 1)
            ->where('journeys.0.departure.trainNumber', '16724')
            ->where('journeyError', null));
        $this->get('/journey?from=PYD&to=MAS')->assertInertia(fn (Assert $page) => $page->where('journeys', []));
    }

    public function test_provider_failure_shows_a_safe_error(): void
    {
        $this->seed();
        Http::fake(['https://api.railradar.in/*' => Http::response(['success' => false], 503)]);

        $this->get('/journey?from=PYD&to=ERL')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('journeys', null)
            ->where('journeyError', fn ($message) => is_string($message) && $message !== ''));
    }
}
