<?php

namespace Tests\Feature;

use App\Railway\Contracts\RailwayProvider;
use App\Railway\Enums\BoardStatus;
use App\Railway\Enums\BoardType;
use App\Railway\Enums\GpsStatus;
use App\Railway\Enums\RunningStatus;
use App\Railway\Enums\StopState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MockRailwayProviderTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Real time at minute 0 → simulated 08:30 on the same day.
        config(['railway.mock.clock' => '08:30']);
        CarbonImmutable::setTestNow('2026-10-02 14:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function railway(): RailwayProvider
    {
        return app(RailwayProvider::class);
    }

    public function test_mock_clock_uses_the_configured_anchor(): void
    {
        $this->assertSame('2026-10-02 08:30', $this->railway()->now()->format('Y-m-d H:i'));
    }

    public function test_running_train_is_positioned_between_stops(): void
    {
        $live = $this->railway()->liveStatus('12675');

        $this->assertSame(RunningStatus::Running, $live->status);
        $this->assertSame(GpsStatus::Active, $live->gps);
        $this->assertSame(3, $live->lastStopSequence); // Katpadi
        $this->assertSame(4, $live->nextStopSequence); // Jolarpettai
        $this->assertSame(7, $live->delayMinutes);
        $this->assertFalse($live->atStation);
        $this->assertGreaterThan(0, $live->speedKmh);
        $this->assertEqualsWithDelta(84, $live->distanceFromLastKm + $live->distanceToNextKm, 0.2);

        // Between Katpadi (12.97, 79.14) and Jolarpettai (12.57, 78.57).
        $this->assertTrue($live->position['lat'] < 12.9717 && $live->position['lat'] > 12.5689);

        $states = array_map(fn ($s) => $s->state, $live->stops);
        $this->assertSame([StopState::Departed, StopState::Departed, StopState::Departed, StopState::Next], array_slice($states, 0, 4));
        $this->assertSame(StopState::Upcoming, end($states));
    }

    public function test_cancelled_scheduled_and_gps_lost_trains(): void
    {
        $this->assertSame(RunningStatus::Cancelled, $this->railway()->liveStatus('16057')->status);
        $this->assertSame(RunningStatus::Scheduled, $this->railway()->liveStatus('12673')->status);

        $brindavan = $this->railway()->liveStatus('12639');
        $this->assertSame(GpsStatus::Lost, $brindavan->gps);
        $this->assertNull($brindavan->speedKmh);
        $this->assertNotNull($brindavan->position, 'Position is still estimated from the timetable.');
    }

    public function test_overnight_train_from_yesterday_is_running_today(): void
    {
        // Howrah Mail takes ~2 days; yesterday's departure is mid-journey.
        $live = $this->railway()->liveStatus('12839');

        $this->assertSame(RunningStatus::Running, $live->status);
        $this->assertSame('2026-10-01', $live->journeyDate);
    }

    public function test_nearby_stations_are_sorted_and_limited_to_radius(): void
    {
        $nearby = $this->railway()->nearbyStations(13.0604, 80.2496, 50, 5);

        $this->assertCount(5, $nearby);
        $distances = array_map(fn ($s) => $s->distanceKm, $nearby);
        $sorted = $distances;
        sort($sorted);
        $this->assertSame($sorted, $distances);

        $this->assertSame([], $this->railway()->nearbyStations(40.7128, -74.0060, 50, 5));
    }

    public function test_station_board_lists_arrivals_and_departures(): void
    {
        $arrivals = $this->railway()->stationBoard('MAS', BoardType::Arrivals);
        $departures = $this->railway()->stationBoard('MAS', BoardType::Departures);

        $arrivalNumbers = array_map(fn ($e) => $e->trainNumber, $arrivals);
        $this->assertNotContains('12675', $arrivalNumbers, 'Originating trains have no arrival.');
        $this->assertContains('12028', $arrivalNumbers);

        $times = array_map(fn ($e) => $e->scheduledTime, $departures);
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times);

        $byNumber = collect($departures)->keyBy('trainNumber');
        $this->assertSame(BoardStatus::Departed, $byNumber['12675']->status);
        $this->assertSame(BoardStatus::Cancelled, $byNumber['16057']->status);
        $this->assertSame('07:22', $byNumber['12243']->expectedTime);
    }

    public function test_train_search_finds_completed_running_and_upcoming_trains(): void
    {
        $status = fn (string $q) => $this->railway()->searchTrains($q)[0]->status;

        $this->assertSame(RunningStatus::Completed, $status('06055')); // 05:15 → 07:05, before 08:30
        $this->assertSame(RunningStatus::Running, $status('12675'));
        $this->assertSame(RunningStatus::Scheduled, $status('12635'));
        $this->assertSame(RunningStatus::Cancelled, $status('16057'));

        // Leading zeros are part of the number.
        $this->assertSame(['06055'], array_map(fn ($t) => $t->number, $this->railway()->searchTrains('060')));
        $this->assertSame([], $this->railway()->searchTrains('6055'));
    }

    public function test_train_search_matches_number_name_and_station(): void
    {
        $this->assertCount(2, $this->railway()->searchTrains('kovai'));
        $this->assertSame('12675', $this->railway()->searchTrains('12675')[0]->number);
        $this->assertNotEmpty($this->railway()->searchTrains('KPD'));
        $this->assertSame([], $this->railway()->searchTrains('99882'));
    }
}
