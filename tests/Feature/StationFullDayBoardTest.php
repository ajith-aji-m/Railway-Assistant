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
 * Full-day station board: completed + running + upcoming trains. RailRadar: the
 * station timetable (GET /v1/stations/{code}/trains) for the whole day, with the
 * live board (GET /v1/stations/{code}/live) replacing entries it covers.
 * Fixtures are real MAS responses; 2026-10-02 is a Friday.
 */
class StationFullDayBoardTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_KEY = 'rr_test_fake_key_for_tests';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'railway.provider' => 'railradar',
            'services.railradar.key' => self::FAKE_KEY,
            'services.railradar.base_url' => 'https://api.railradar.in',
            'services.railradar.cache_seconds' => 60,
            'services.railradar.search_cache_seconds' => 86400,
        ]);
        Http::preventStrayRequests();
        CarbonImmutable::setTestNow('2026-10-02 10:45:00'); // live fixture captured ~10:50 IST
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/railradar/{$name}.json")), true);
    }

    private function fakeMas(?array $live = null): void
    {
        Http::fake([
            'https://api.railradar.in/v1/stations/MAS/live*' => Http::response($live ?? $this->fixture('station-live-MAS')),
            'https://api.railradar.in/v1/stations/MAS/trains*' => Http::response($this->fixture('station-trains-MAS')),
        ]);
    }

    /** @return \Illuminate\Support\Collection<string, \App\Railway\Data\BoardEntry> */
    private function arrivals()
    {
        return collect(app(RailwayProvider::class)->stationBoard('MAS', BoardType::Arrivals))->keyBy('trainNumber');
    }

    public function test_completed_running_and_upcoming_trains_are_all_listed(): void
    {
        $this->fakeMas();
        $board = $this->arrivals();

        // Completed per timetable (before the live window): kept, not hidden, no fake live data.
        $this->assertSame(BoardPhase::Completed, $board['12839']->phase); // arr 03:20
        $this->assertSame(BoardStatus::Arrived, $board['12839']->status);
        $this->assertFalse($board['12839']->isLive);
        $this->assertNull($board['12839']->delayMinutes);
        $this->assertNull($board['12839']->expectedTime);

        // Running: live-tracked by RailRadar on its way here (live entry replaces the timetable one).
        $this->assertSame(BoardPhase::Running, $board['12028']->phase);
        $this->assertTrue($board['12028']->isLive);
        $this->assertSame('11:00', $board['12028']->expectedTime);
        $this->assertSame(BoardPhase::Running, $board['22159']->phase);
        $this->assertSame('11:35', $board['22159']->expectedTime);

        // Upcoming: later today, timetable only → "Scheduled".
        $later = $board->first(fn ($e) => $e->scheduledTime > '20:00');
        $this->assertNotNull($later);
        $this->assertSame(BoardPhase::Upcoming, $later->phase);
        $this->assertSame(BoardStatus::Scheduled, $later->status);
        $this->assertFalse($later->isLive);

        $phases = $board->pluck('phase')->unique()->values()->all();
        $this->assertEqualsCanonicalizing([BoardPhase::Completed, BoardPhase::Running, BoardPhase::Upcoming], $phases);
    }

    public function test_board_is_chronological_by_scheduled_time(): void
    {
        $this->fakeMas();
        $times = $this->arrivals()->pluck('scheduledTime')->values()->all();
        $sorted = $times;
        sort($sorted);

        $this->assertSame($sorted, $times);
        $this->assertGreaterThan(40, count($times));
    }

    public function test_only_trains_running_today_are_listed(): void
    {
        $this->fakeMas();
        $board = $this->arrivals();

        $this->assertTrue($board->has('06120'), 'Thu-only run arriving on day 2 → reaches MAS on Friday; leading zero kept');
        $this->assertTrue($board->has('16032'), 'Tue start, day 4 → Friday');
        $this->assertFalse($board->has('12291'), 'Fri-only run arriving on day 2 → reaches MAS on Saturday');
        $this->assertFalse($board->has('12968'), 'Fri/Sun runs arriving on day 3 → Sunday/Tuesday');
        $this->assertSame(1, $board->filter(fn ($e) => $e->trainNumber === '12028')->count(), 'no duplicates');
    }

    public function test_cancelled_trains_stay_cancelled(): void
    {
        $live = $this->fixture('station-live-MAS');
        foreach ($live['data']['trains'] as &$item) {
            if ($item['train']['number'] === '12841') { // arr 17:00
                $item['live']['type'] = 'cancelled';
            }
        }
        unset($item);
        $this->fakeMas($live);

        $entry = $this->arrivals()['12841'];
        $this->assertSame(BoardStatus::Cancelled, $entry->status);
        $this->assertSame(BoardPhase::Upcoming, $entry->phase); // later today
    }

    public function test_dashboard_costs_one_live_and_one_timetable_request(): void
    {
        $this->fakeMas();

        $this->get('/stations/MAS')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('board', fn ($board) => collect($board)->pluck('phase')->unique()->sort()->values()->all() === ['completed', 'running', 'upcoming']));
        $this->get('/stations/MAS?tab=departures')->assertOk();
        $this->get('/stations/MAS')->assertOk();

        Http::assertSentCount(2); // timetable cached for a day, live board for 60 s
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/stations/MAS/trains'));
    }

    public function test_timetable_failure_falls_back_to_the_live_board(): void
    {
        Http::fake([
            'https://api.railradar.in/v1/stations/MAS/live*' => Http::response($this->fixture('station-live-MAS')),
            'https://api.railradar.in/v1/stations/MAS/trains*' => Http::response(['success' => false], 503),
        ]);

        $this->get('/stations/MAS')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('boardError', null)
            ->has('board', 4)); // the live board alone, as before
    }

    public function test_mock_board_lists_the_full_day_with_consistent_phases(): void
    {
        $this->seed();
        app()->forgetInstance(RailwayProvider::class);
        config(['railway.provider' => 'mock', 'railway.mock.clock' => '08:30']);
        CarbonImmutable::setTestNow('2026-10-02 14:00:00'); // simulated 08:30
        Http::fake();

        $board = collect(app(RailwayProvider::class)->stationBoard('MAS', BoardType::Departures))->keyBy('trainNumber');

        $this->assertSame(BoardPhase::Completed, $board['12675']->phase); // departed 06:10 (delayed scenario)
        $this->assertSame(BoardStatus::Cancelled, $board['16057']->status);
        foreach ($board as $entry) {
            $expected = match ($entry->status) {
                BoardStatus::Departed, BoardStatus::Arrived => BoardPhase::Completed,
                BoardStatus::AtStation, BoardStatus::Approaching => BoardPhase::Running,
                default => null,
            };
            if ($expected) {
                $this->assertSame($expected, $entry->phase, $entry->trainNumber);
            }
        }
        $this->assertContains(BoardPhase::Upcoming, $board->pluck('phase')->all());
        Http::assertNothingSent();
    }
}
