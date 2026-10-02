<?php

namespace Tests\Feature;

use App\Railway\Contracts\RailwayProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Search screen backed by RailRadar `GET /v1/lookup/search/stations` (HTTP mocked). */
class RailRadarSearchTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_KEY = 'rr_test_fake_key_for_tests';

    private const SEARCH_URL = 'https://api.railradar.in/v1/lookup/search/stations*';

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
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/railradar/search-stations-chennai.json')), true);
    }

    private function fakeSearch(?array $body = null, int $status = 200): void
    {
        Http::fake([self::SEARCH_URL => Http::response($body ?? $this->fixture(), $status)]);
    }

    public function test_search_page_renders_in_station_mode_without_calling_api(): void
    {
        Http::fake();

        $this->get('/trains')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Trains/Search')
            ->where('searchMode', 'stations')
            ->where('minQueryLength', 2)
            ->where('liveData', true)
            ->where('stationResults', null)
            ->where('results', null)
            ->where('searchError', null));

        Http::assertNothingSent(); // empty query
    }

    public function test_station_search_request_and_query_parameter(): void
    {
        $this->fakeSearch();

        $this->get('/trains?q='.urlencode('  Chennai   Central '))->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.railradar.in/v1/lookup/search/stations?')
            && $r['q'] === 'Chennai Central'   // trimmed + collapsed
            && $r['limit'] === '10'            // documented value
            && count($r->data()) === 2         // no undocumented parameters
            && $r->hasHeader('Authorization', 'Bearer '.self::FAKE_KEY)
            && ! str_contains($r->url(), self::FAKE_KEY));
    }

    public function test_results_are_normalized_and_displayed(): void
    {
        $this->fakeSearch();

        $this->get('/trains?q=Chennai')->assertInertia(fn (Assert $page) => $page
            ->has('stationResults', 7) // 10 returned, 3 inactive left out
            ->where('stationResults.0.code', 'MPK')
            ->where('stationResults.0.name', 'Chennai Park')
            ->where('stationResults.0.city', 'Chennai')
            ->where('stationResults.0.state', null)
            ->where('stationResults.0.lat', null)
            ->where('stationResults.0.lng', null)
            ->where('stationResults.0.distanceKm', null)
            ->where('stationResults.3.code', 'MS')
            ->where('stationResults.3.name', 'Chennai Egmore')
            ->etc());

        $codes = array_column(app(RailwayProvider::class)->searchStations('Chennai'), 'code');
        $this->assertNotContains('HOM', $codes, 'inactive station');
        $this->assertNotContains('popularity', array_keys((array) app(RailwayProvider::class)->searchStations('Chennai')[0]));
    }

    public function test_selecting_a_result_opens_the_station_dashboard(): void
    {
        $this->fakeSearch();
        Http::fake(['https://api.railradar.in/v1/stations/MS/live*' => Http::response([
            'success' => true,
            'data' => ['station' => ['code' => 'MS', 'name' => 'Chennai Egmore', 'city' => 'Chennai'], 'trains' => [], 'count' => 0],
            'meta' => [],
        ])]);

        $code = null;
        $this->get('/trains?q=Egmore')->assertInertia(function (Assert $page) use (&$code) {
            $code = $page->toArray()['props']['stationResults'][3]['code'];
        });
        $this->assertSame('MS', $code);

        // The result links to the existing dashboard route: /stations/{code}.
        $this->get('/stations/'.$code)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Stations/Show')
            ->where('station.code', 'MS'));
    }

    public function test_short_query_does_not_call_api(): void
    {
        Http::fake();

        $this->get('/trains?q=C')->assertOk()->assertInertia(fn (Assert $page) => $page->where('stationResults', null));
        $this->assertSame([], app(RailwayProvider::class)->searchStations(' c '));

        Http::assertNothingSent();
    }

    public function test_repeated_query_is_served_from_cache(): void
    {
        $this->fakeSearch();

        $this->get('/trains?q=Salem')->assertOk();
        $this->get('/trains?q=Salem')->assertOk();
        app(RailwayProvider::class)->searchStations('Salem');

        Http::assertSentCount(1);
    }

    public function test_no_results(): void
    {
        $this->fakeSearch(['success' => true, 'data' => [], 'meta' => []]);

        $this->get('/trains?q=Zzzz')->assertInertia(fn (Assert $page) => $page
            ->where('stationResults', [])
            ->where('searchError', null));
    }

    public function test_not_found_is_treated_as_no_results(): void
    {
        $this->fakeSearch(['success' => false, 'error' => ['code' => 'NOT_FOUND']], 404);

        $this->get('/trains?q=Zzzz')->assertInertia(fn (Assert $page) => $page
            ->where('stationResults', [])
            ->where('searchError', null));
    }

    public function test_authentication_error(): void
    {
        $this->fakeSearch(['success' => false], 401);
        $this->assertSearchError('credentials');
    }

    public function test_rate_limit(): void
    {
        Http::fake([self::SEARCH_URL => Http::response(['success' => false], 429, ['Retry-After' => '60'])]);
        $this->assertSearchError('rate limit');
    }

    public function test_api_unavailable(): void
    {
        Http::fake([self::SEARCH_URL => Http::response('degraded', 503)]);
        $this->assertSearchError('temporarily unavailable');
    }

    public function test_timeout(): void
    {
        Http::fake([self::SEARCH_URL => fn () => throw new ConnectionException('cURL error 28')]);
        $this->assertSearchError('did not respond');
    }

    public function test_malformed_response(): void
    {
        $this->fakeSearch(['success' => true, 'data' => ['code' => 'MAS']]);
        $this->assertSearchError('not a list');
    }

    public function test_mock_search_is_unchanged(): void
    {
        $this->seed();
        app()->forgetInstance(RailwayProvider::class);
        config(['railway.provider' => 'mock']);
        Http::fake();

        $this->get('/trains?q=kovai')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('searchMode', 'trains')
            ->where('liveData', false)
            ->has('results', 2)
            ->where('stationResults', null));
        $this->get('/trains?all=1')->assertInertia(fn (Assert $page) => $page->where('showAll', true)->has('results', 13));

        Http::assertNothingSent();
    }

    public function test_api_key_never_leaks(): void
    {
        Log::spy();
        $this->fakeSearch(['success' => false, 'error' => ['message' => 'invalid']], 401);

        $response = $this->get('/trains?q=Chennai');
        $response->assertDontSee(self::FAKE_KEY);

        $this->fakeSearch();
        $this->get('/trains?q=Salem')->assertDontSee(self::FAKE_KEY);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => ! str_contains($message.json_encode($context), self::FAKE_KEY));
    }

    private function assertSearchError(string $fragment): TestResponse
    {
        return $this->get('/trains?q=Chennai')
            ->assertOk()
            ->assertDontSee(self::FAKE_KEY)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Trains/Search')
                ->where('stationResults', null)
                ->where('searchError', fn (string $error) => str_contains($error, $fragment)));
    }
}
