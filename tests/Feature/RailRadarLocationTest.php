<?php

namespace Tests\Feature;

use App\Railway\Contracts\RailwayProvider;
use App\Railway\Support\Geo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Location / station selection with RAILWAY_PROVIDER=railradar.
 * RailRadar documents no nearby-station endpoint, so nearest stations come from
 * the local station dataset (Haversine) and must never call the API.
 */
class RailRadarLocationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const FAKE_KEY = 'rr_test_fake_key_for_tests';

    /** A point in central Chennai. */
    private const LAT = 13.0604;

    private const LNG = 80.2496;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'railway.provider' => 'railradar',
            'services.railradar.key' => self::FAKE_KEY,
            'services.railradar.base_url' => 'https://api.railradar.in',
        ]);
        Http::preventStrayRequests();
        $this->fakeStationTimetable();
    }

    public function test_location_screen_renders_without_api_calls(): void
    {
        Http::fake();

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Stations/Index')
            ->where('location', null)
            ->where('nearby', null)
            ->where('searchResults', null)
            ->where('searchError', null)
            ->where('searchMinLength', 2)
            ->where('searchDebounceMs', 400)
            ->missing('popular'));

        Http::assertNothingSent();
    }

    public function test_nearest_stations_are_calculated_locally_and_sorted(): void
    {
        Http::fake();

        $this->get('/?lat='.self::LAT.'&lng='.self::LNG)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('location', ['lat' => self::LAT, 'lng' => self::LNG])
            ->has('nearby', 5)
            ->where('nearby.0.code', 'MSC')
            ->where('nearby.0.name', 'Chennai Chetpat')
            ->where('nearby.0.city', null)        // the station directory has no city data
            ->where('nearby.0.distanceKm', 1.7)
            ->where('nearby.3.code', 'MS')
            ->where('nearby.3.distanceKm', 2.4)
            ->where('nearby', function ($nearby) {
                $distances = collect($nearby)->pluck('distanceKm')->all();
                $sorted = $distances;
                sort($sorted);

                return $distances === $sorted;
            }));

        Http::assertNothingSent(); // never one request per station
    }

    public function test_distances_use_haversine(): void
    {
        // One degree of longitude at the equator ≈ 111.19 km.
        $this->assertEqualsWithDelta(111.19, Geo::distanceKm(0, 0, 0, 1), 0.01);
        // Chennai Central (MAS) → Coimbatore Jn (CBE): ≈ 425 km great-circle.
        $this->assertEqualsWithDelta(425, Geo::distanceKm(13.0827, 80.2757, 10.9966, 76.9668), 5);

        $nearest = app(RailwayProvider::class)->nearbyStations(self::LAT, self::LNG, 50, 1)[0];
        $this->assertEqualsWithDelta(round(Geo::distanceKm(self::LAT, self::LNG, $nearest->lat, $nearest->lng), 1), $nearest->distanceKm, 0.001);
    }

    public function test_no_nearby_stations(): void
    {
        Http::fake();

        $this->get('/?lat=40.7128&lng=-74.0060')->assertInertia(fn (Assert $page) => $page->where('nearby', []));
        $this->get('/?lat=40.7128&lng=-74.0060&radius=100')->assertInertia(fn (Assert $page) => $page->where('radiusKm', 100)->where('nearby', []));

        Http::assertNothingSent();
    }

    public function test_selecting_nearest_station_opens_railradar_dashboard(): void
    {
        Http::fake(['https://api.railradar.in/v1/stations/MSC/live*' => Http::response([
            'success' => true,
            'data' => [
                'station' => ['code' => 'MSC', 'name' => 'Chennai Chetpat', 'city' => 'Chennai', 'lat' => 13.0711, 'lng' => 80.2414],
                'trains' => [],
                'count' => 0,
            ],
            'meta' => [],
        ])]);

        $code = null;
        $this->get('/?lat='.self::LAT.'&lng='.self::LNG)->assertInertia(function (Assert $page) use (&$code) {
            $code = $page->toArray()['props']['nearby'][0]['code'];
        });

        // The existing route with the station's real code → live RailRadar board.
        $this->get('/stations/'.$code)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Stations/Show')
            ->where('station.code', 'MSC')
            ->where('station.name', 'Chennai Chetpat'));
        Http::assertSentCount(2); // live board + station timetable
    }

    public function test_no_default_stations_are_suggested_without_location(): void
    {
        Http::fake();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('nearby', null)
            ->missing('popular')
            ->reload(only: 'popular', callback: fn (Assert $reload) => $reload->missing('popular')));

        Http::assertNothingSent();
    }

    public function test_see_all_nearby_makes_no_api_calls(): void
    {
        Http::fake();

        $this->get('/?lat='.self::LAT.'&lng='.self::LNG.'&all=1')->assertInertia(fn (Assert $page) => $page->where('showAll', true)->has('nearby', 20)); // "See All" cap

        Http::assertNothingSent();
    }

    public function test_short_search_on_location_screen_makes_no_api_call(): void
    {
        Http::fake();

        $this->get('/?q=c')->assertInertia(fn (Assert $page) => $page->where('searchResults', null)->where('searchError', null));

        Http::assertNothingSent();
    }

    public function test_search_api_401(): void
    {
        Http::fake(['https://api.railradar.in/v1/lookup/search/stations*' => Http::response(['success' => false], 401)]);
        $this->assertSearchErrorKeepsNearby('credentials');
    }

    public function test_search_api_429(): void
    {
        Http::fake(['https://api.railradar.in/v1/lookup/search/stations*' => Http::response(['success' => false], 429)]);
        $this->assertSearchErrorKeepsNearby('rate limit');
    }

    public function test_search_api_503(): void
    {
        Http::fake(['https://api.railradar.in/v1/lookup/search/stations*' => Http::response('degraded', 503)]);
        $this->assertSearchErrorKeepsNearby('temporarily unavailable');
    }

    public function test_search_api_timeout(): void
    {
        Http::fake(['https://api.railradar.in/v1/lookup/search/stations*' => fn () => throw new ConnectionException('cURL error 28')]);
        $this->assertSearchErrorKeepsNearby('did not respond');
    }

    public function test_search_api_malformed_response(): void
    {
        Http::fake(['https://api.railradar.in/v1/lookup/search/stations*' => Http::response(['success' => true, 'data' => ['oops' => true]])]);
        $this->assertSearchErrorKeepsNearby('not a list');
    }

    public function test_mock_location_flow_unchanged(): void
    {
        app()->forgetInstance(RailwayProvider::class);
        config(['railway.provider' => 'mock']);
        Http::fake();

        $this->get('/?lat='.self::LAT.'&lng='.self::LNG)->assertInertia(fn (Assert $page) => $page
            ->has('nearby', 5)
            ->where('nearby.0.code', 'MSC')
            ->where('nearby.0.distanceKm', 1.7)
            ->where('searchMinLength', 1)
            ->where('searchDebounceMs', 250));
        $this->get('/?q=chennai')->assertInertia(fn (Assert $page) => $page->has('searchResults', 10));

        Http::assertNothingSent();
    }

    public function test_api_key_never_leaks(): void
    {
        Log::spy();
        Http::fake(['https://api.railradar.in/v1/lookup/search/stations*' => Http::response(['success' => false], 401)]);

        $this->get('/?lat='.self::LAT.'&lng='.self::LNG.'&q=Chennai')->assertDontSee(self::FAKE_KEY);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => ! str_contains($message.json_encode($context), self::FAKE_KEY));
    }

    private function assertSearchErrorKeepsNearby(string $fragment): TestResponse
    {
        return $this->get('/?lat='.self::LAT.'&lng='.self::LNG.'&q=Chennai')
            ->assertOk()
            ->assertDontSee(self::FAKE_KEY)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stations/Index')
                ->has('nearby', 5) // local nearest stations still shown
                ->where('searchResults', null)
                ->where('searchError', fn (string $error) => str_contains($error, $fragment)));
    }
}
