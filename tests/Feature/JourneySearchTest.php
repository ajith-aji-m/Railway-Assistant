<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Railway\Contracts\RailwayProvider;
use App\Railway\Enums\BoardPhase;
use App\Railway\Enums\BoardStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * From → To journey search with the mock provider. The mock network has two demo
 * specials on the Thiruvananthapuram – Nagercoil line: 06425 (TVC → … → PYD → ERL → NCJ)
 * and 06426 in the opposite direction (NCJ → ERL → PYD → … → TVC).
 */
class JourneySearchTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** A point in Palliyadi village, ~0.2 km from Palliyadi station (PYD). */
    private const LAT = 8.2660;

    private const LNG = 77.2610;

    protected function setUp(): void
    {
        parent::setUp();

        config(['railway.provider' => 'mock', 'railway.mock.clock' => '08:30']);
        CarbonImmutable::setTestNow('2026-10-02 14:00:00'); // simulated 08:30
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

    public function test_journey_page_renders_without_location_or_stations(): void
    {
        $this->get('/journey')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Journey/Index')
            ->where('location', null)
            ->where('nearby', null)
            ->where('from', null)
            ->where('to', null)
            ->where('journeys', null)
            ->where('journeyError', null));
    }

    public function test_from_suggestions_use_the_users_location(): void
    {
        $this->get('/journey?lat='.self::LAT.'&lng='.self::LNG)->assertInertia(fn (Assert $page) => $page
            ->where('location', ['lat' => self::LAT, 'lng' => self::LNG])
            ->where('nearby.0.code', 'PYD'));
    }

    public function test_from_suggestions_are_sorted_nearest_first_within_the_radius(): void
    {
        $this->get('/journey?lat='.self::LAT.'&lng='.self::LNG)->assertInertia(function (Assert $page) {
            $nearby = $page->toArray()['props']['nearby'];
            $codes = array_column($nearby, 'code');
            $distances = array_column($nearby, 'distanceKm');

            $sorted = $distances;
            sort($sorted);
            $this->assertSame($sorted, $distances);
            $this->assertLessThanOrEqual(config('railway.nearby.radius_km'), max($distances));
            $this->assertSame('PYD', $codes[0]);
            $this->assertContains('KZT', $codes); // Kulitthurai, next along the line
            $this->assertNotContains('MAS', $codes);
        });
    }

    public function test_to_search_never_returns_nearby_stations_on_its_own(): void
    {
        // Location given but nothing typed: no search results, only the From suggestions.
        $this->get('/journey?lat='.self::LAT.'&lng='.self::LNG)->assertInertia(fn (Assert $page) => $page
            ->where('query', '')
            ->where('searchResults', null));

        // Typed destination: results match the text, not the user's surroundings.
        $this->get('/journey?q=Chennai&lat='.self::LAT.'&lng='.self::LNG)->assertInertia(function (Assert $page) {
            $codes = array_column($page->toArray()['props']['searchResults'], 'code');
            $this->assertContains('MAS', $codes);
            $this->assertNotContains('PYD', $codes);
            $this->assertNotContains('KZT', $codes);
        });
    }

    public function test_to_search_returns_matching_station_names_codes_and_aliases(): void
    {
        $search = fn (string $q) => array_column(array_map(fn ($s) => (array) $s, $this->railway()->searchStations($q)), 'code');

        $this->assertContains('ERL', $search('Eraniel'));
        $this->assertContains('KZT', $search('Kulithurai')); // curated alias
        $this->assertSame('PYD', $search('PYD')[0]);

        $this->get('/journey?q=Eraniel')->assertInertia(fn (Assert $page) => $page
            ->where('searchResults.0.code', 'ERL')
            ->where('searchError', null));
    }

    public function test_destination_without_a_station_shows_no_results(): void
    {
        // Kalakkad has no railway station in the directory: nothing is invented.
        $this->get('/journey?q=Kalakkad')->assertInertia(fn (Assert $page) => $page
            ->where('searchResults', [])
            ->where('searchError', null));
    }

    public function test_empty_destination_search_is_not_searched(): void
    {
        $this->get('/journey?q=')->assertInertia(fn (Assert $page) => $page->where('searchResults', null));
        $this->get('/journey?q=%20%20')->assertInertia(fn (Assert $page) => $page->where('searchResults', null));
    }

    public function test_both_stations_must_be_selected_before_searching(): void
    {
        $this->get('/journey?from=PYD')->assertInertia(fn (Assert $page) => $page
            ->where('from.code', 'PYD')
            ->where('from.name', 'Palliyadi')
            ->where('journeys', null)
            ->where('journeyError', null));
        $this->get('/journey?to=ERL')->assertInertia(fn (Assert $page) => $page->where('journeys', null));
        $this->get('/journey?from=PYD&to=PYD')->assertInertia(fn (Assert $page) => $page
            ->where('journeys', null)
            ->where('journeyError', 'From and To must be different stations.'));
        $this->get('/journey?from=PY!D&to=ERL')->assertSessionHasErrors('from');
    }

    public function test_train_is_returned_when_its_route_has_from_before_to(): void
    {
        $this->get('/journey?from=PYD&to=ERL')->assertInertia(fn (Assert $page) => $page
            ->where('from.name', 'Palliyadi')
            ->where('to.name', 'Eraniel')
            ->has('journeys', 1)
            ->where('journeys.0.departure.trainNumber', '06425')
            ->where('journeys.0.departure.scheduledTime', '14:15') // departs Palliyadi
            ->where('journeys.0.arrives', '14:32')                  // arrives Eraniel
            ->where('journeys.0.arrivalDayOffset', 0)
            ->where('journeys.0.departure.platform', '1')
            ->where('journeys.0.departure.status', BoardStatus::Expected->value)
            ->where('journeys.0.departure.phase', BoardPhase::Upcoming->value)
            ->where('journeys.0.boarding.code', 'PYD')
            ->where('journeys.0.alighting.code', 'ERL'));
    }

    public function test_train_with_to_before_from_is_excluded(): void
    {
        // 06426 calls at Eraniel before Palliyadi; 06425 the other way round.
        $forward = array_map(fn ($j) => $j->departure->trainNumber, $this->railway()->journeys('PYD', 'ERL'));
        $reverse = array_map(fn ($j) => $j->departure->trainNumber, $this->railway()->journeys('ERL', 'PYD'));

        $this->assertSame(['06425'], $forward);
        $this->assertSame(['06426'], $reverse);
    }

    public function test_journey_status_follows_the_live_simulation(): void
    {
        // 06426 left Palliyadi at 08:16 + 5 min delay; the clock is 08:30.
        $journey = $this->railway()->journeys('ERL', 'PYD')[0];

        $this->assertSame(BoardStatus::Departed, $journey->departure->status);
        $this->assertSame(BoardPhase::Completed, $journey->departure->phase);
        $this->assertSame(5, $journey->departure->delayMinutes);
        $this->assertSame('08:15', $journey->arrives);
        $this->assertSame('08:20', $journey->expectedArrival);
    }

    public function test_route_without_trains_returns_an_empty_list(): void
    {
        $this->get('/journey?from=PYD&to=MAS')->assertInertia(fn (Assert $page) => $page
            ->where('journeys', [])
            ->where('journeyError', null));
    }

    public function test_partial_reloads_never_run_the_journey_search(): void
    {
        $this->get('/journey?from=PYD&to=ERL&q=Eraniel', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Journey/Index',
            'X-Inertia-Partial-Data' => 'query,searchResults,searchError',
        ])->assertJsonMissingPath('props.journeys')->assertJsonPath('props.searchResults.0.code', 'ERL');
    }

    public function test_existing_station_search_still_works(): void
    {
        $this->get('/?q=Chennai')->assertInertia(fn (Assert $page) => $page
            ->component('Stations/Index')
            ->where('searchResults', fn ($results) => collect($results)->pluck('code')->contains('MAS')));
        $this->get('/?lat='.self::LAT.'&lng='.self::LNG)->assertInertia(fn (Assert $page) => $page->where('nearby.0.code', 'PYD'));
    }

    public function test_existing_train_search_still_works(): void
    {
        $this->get('/trains?q=12675')->assertInertia(fn (Assert $page) => $page
            ->component('Trains/Search')
            ->where('results.0.number', '12675'));
        $this->get('/trains/06425')->assertOk();
    }
}
