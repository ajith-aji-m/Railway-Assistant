<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\TrainStop;
use App\Railway\Contracts\RailwayProvider;
use App\Railway\Support\LocalStationDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Location → nearby station → station dashboard → that station's trains, plus
 * manual search for any station (mock provider unless stated otherwise).
 */
class StationDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** A point in Madurai, far from the Chennai stations. */
    private const LAT = 9.9252;

    private const LNG = 78.1198;

    public function test_nearby_stations_are_ranked_nearest_first_from_the_users_location(): void
    {
        $this->get('/?lat='.self::LAT.'&lng='.self::LNG.'&radius=100')->assertInertia(function (Assert $page) {
            $nearby = $page->toArray()['props']['nearby'];
            $this->assertSame('MDU', $nearby[0]['code']);

            $distances = array_column($nearby, 'distanceKm');
            $sorted = $distances;
            sort($sorted);
            $this->assertSame($sorted, $distances);
            $this->assertLessThanOrEqual(100, max($distances));
            // Far-away default hubs are never suggested just because they exist.
            $this->assertNotContains('MAS', array_column($nearby, 'code'));
            $this->assertNotContains('SBC', array_column($nearby, 'code'));
        });
    }

    public function test_radius_comes_from_configuration(): void
    {
        config(['railway.nearby.radius_km' => 5]);

        $this->get('/?lat='.self::LAT.'&lng='.self::LNG)->assertInertia(fn (Assert $page) => $page
            ->where('radiusKm', 5)
            ->where('nearby.0.code', 'MDU')
            ->where('nearby', fn ($nearby) => collect($nearby)->every(fn ($s) => $s['distanceKm'] <= 5)));
    }

    public function test_stations_without_coordinates_are_excluded_from_nearby_ranking(): void
    {
        $stations = [
            new Station(['code' => 'NOC', 'name' => 'No Coordinates', 'lat' => null, 'lng' => null]),
            new Station(['code' => 'HAF', 'name' => 'Half Coordinates', 'lat' => self::LAT, 'lng' => null]),
            new Station(['code' => 'FAR', 'name' => 'Farther', 'lat' => 9.95, 'lng' => 78.15]),
            new Station(['code' => 'NER', 'name' => 'Nearer', 'lat' => 9.926, 'lng' => 78.12]),
        ];

        $ranked = app(LocalStationDirectory::class)->rank($stations, self::LAT, self::LNG, 50, 10);

        $this->assertSame(['NER', 'FAR'], array_map(fn ($s) => $s->code, $ranked));
    }

    public function test_without_location_no_stations_are_suggested(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('location', null)
            ->where('nearby', null)
            ->missing('popular')
            ->reload(only: 'popular', callback: fn (Assert $reload) => $reload->missing('popular')));
    }

    public function test_selecting_a_nearby_station_opens_its_dashboard(): void
    {
        $code = null;
        $this->get('/?lat='.self::LAT.'&lng='.self::LNG)->assertInertia(function (Assert $page) use (&$code) {
            $code = $page->toArray()['props']['nearby'][0]['code'];
        });

        $this->get('/stations/'.$code)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Stations/Show')
            ->where('station.code', 'MDU'));
    }

    public function test_manual_search_is_not_restricted_by_location(): void
    {
        // A user near Madurai can still find and open Chennai Central.
        $this->get('/?lat='.self::LAT.'&lng='.self::LNG.'&q=chennai central')->assertInertia(fn (Assert $page) => $page
            ->where('searchResults.0.code', 'MAS')
            ->where('searchResults.0.distanceKm', null));

        $this->get('/?q=chennai')->assertInertia(fn (Assert $page) => $page->has('searchResults', 10));

        $this->get('/stations/MAS')->assertOk()->assertInertia(fn (Assert $page) => $page->where('station.code', 'MAS'));
    }

    public function test_dashboard_lists_only_trains_serving_the_selected_station(): void
    {
        foreach (['arrivals', 'departures'] as $tab) {
            $this->assertBoardServes('MDU', $tab);
            $this->assertBoardServes('MAS', $tab);
        }
    }

    public function test_changing_station_loads_the_new_stations_trains(): void
    {
        $first = $this->assertBoardServes('MDU', 'departures');
        $second = $this->assertBoardServes('MAS', 'departures');

        $this->assertNotSame($first, $second);
    }

    public function test_railradar_dashboard_requests_only_the_selected_station(): void
    {
        config([
            'railway.provider' => 'railradar',
            'services.railradar.key' => 'rr_test_fake_key_for_tests',
            'services.railradar.base_url' => 'https://api.railradar.in',
        ]);
        app()->forgetInstance(RailwayProvider::class);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.railradar.in/v1/stations/MS/live*' => Http::response($this->fixture('MS')),
            'https://api.railradar.in/v1/stations/MAS/live*' => Http::response($this->fixture('MAS')),
        ]);
        $this->fakeStationTimetable();

        $boards = [];
        foreach (['MS', 'MAS'] as $code) {
            $this->get('/stations/'.$code.'?tab=departures')->assertOk()->assertInertia(function (Assert $page) use ($code, &$boards) {
                $page->where('station.code', $code)->where('boardError', null);
                $boards[$code] = array_column($page->toArray()['props']['board'], 'trainNumber');
            });
        }

        $this->assertNotEmpty($boards['MS']);
        $this->assertNotEmpty($boards['MAS']);
        $this->assertSame([], array_intersect($boards['MS'], $boards['MAS']));
        Http::assertSentCount(4); // each station: its live board + its timetable
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/stations/MS/live'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/stations/MAS/live'));
    }

    /** @return list<string> The board's train numbers, after checking they all stop at the station. */
    private function assertBoardServes(string $code, string $tab): array
    {
        $column = $tab === 'arrivals' ? 'scheduled_arrival' : 'scheduled_departure';
        $serving = TrainStop::query()
            ->whereHas('station', fn (Builder $q) => $q->where('code', $code))
            ->whereNotNull($column)
            ->with('train')
            ->get()
            ->map(fn (TrainStop $stop) => $stop->train->number)
            ->sort()->values()->all();

        $board = [];
        $this->get("/stations/{$code}?tab={$tab}")->assertOk()->assertInertia(function (Assert $page) use ($code, &$board) {
            $page->where('station.code', $code);
            $board = array_column($page->toArray()['props']['board'], 'trainNumber');
        });

        $this->assertNotEmpty($board, "{$code} {$tab} board should not be empty");
        $sorted = $board;
        sort($sorted);
        $this->assertSame($serving, $sorted, "{$code} {$tab} board must list exactly the trains serving {$code}");

        return $board;
    }

    private function fixture(string $code): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/railradar/station-live-{$code}.json")), true);
    }
}
