<?php

namespace Tests\Feature;

use App\Railway\Contracts\RailwayProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Train Search with RAILWAY_PROVIDER=railradar (HTTP mocked). An exact 5-digit number
 * uses `GET /v1/trains/{number}/live` (the cached, locked request Train Details and the
 * Live Map share); partial numbers / names use `GET /v1/lookup/search/trains`.
 */
class RailRadarSearchTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_KEY = 'rr_test_fake_key_for_tests';

    private const LOOKUP_URL = 'https://api.railradar.in/v1/lookup/search/trains*';

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

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/railradar/{$name}.json")), true);
    }

    private function fakeLive(string $number, array $body, int $status = 200): void
    {
        Http::fake(["https://api.railradar.in/v1/trains/{$number}/live*" => Http::response($body, $status)]);
    }

    private function fakeLookup(?array $body = null, int $status = 200): void
    {
        Http::fake([self::LOOKUP_URL => Http::response($body ?? $this->fixture('search-trains-1267'), $status)]);
    }

    public function test_search_page_renders_without_calling_api(): void
    {
        Http::fake();

        $this->get('/trains')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Trains/Search')
            ->where('liveData', true)
            ->where('searchOnSubmit', true) // no request per keystroke
            ->where('minQueryLength', 2)
            ->where('results', null)
            ->where('searchError', null));

        Http::assertNothingSent();
    }

    public function test_running_train_found_by_number_and_details_and_map_reuse_the_request(): void
    {
        $this->fakeLive('12675', $this->fixture('live-12675'));

        $this->get('/trains?q=12675')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('results', 1)
            ->where('results.0.number', '12675')
            ->where('results.0.name', 'Kovai SF Express')
            ->where('results.0.status', 'running')
            ->where('results.0.delayMinutes', 6)
            ->where('results.0.delayIsLive', true)
            ->where('results.0.from.code', 'MAS')
            ->where('results.0.to.code', 'CBE'));

        // Existing routes, same cached response: no further RailRadar calls.
        $this->get('/trains/12675')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Trains/Show')->where('train.number', '12675'));
        $this->get('/trains/12675/map')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Trains/LiveMap'));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/trains/12675/live'));
    }

    public function test_upcoming_train_is_found_and_never_shown_as_on_time(): void
    {
        $this->fakeLive('12635', $this->fixture('live-12635-not-started'));

        $this->get('/trains?q=12635')->assertInertia(fn (Assert $page) => $page
            ->has('results', 1)
            ->where('results.0.status', 'scheduled')
            ->where('results.0.delayIsLive', false));
    }

    public function test_completed_train_is_still_found(): void
    {
        $live = $this->fixture('live-12675');
        $live['data']['status'] = 'completed';
        $this->fakeLive('12675', $live);

        $this->get('/trains?q=12675')->assertInertia(fn (Assert $page) => $page
            ->has('results', 1)
            ->where('results.0.number', '12675')
            ->where('results.0.status', 'completed'));
    }

    public function test_cancelled_train_is_found(): void
    {
        $live = $this->fixture('live-12675');
        $live['data']['status'] = 'cancelled';
        $this->fakeLive('12675', $live);

        $this->get('/trains?q=12675')->assertInertia(fn (Assert $page) => $page->where('results.0.status', 'cancelled'));
    }

    public function test_partial_number_uses_the_train_lookup_without_live_status(): void
    {
        $this->fakeLookup();

        $this->get('/trains?q=1267')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('results', 8)
            ->where('results.1.number', '12675')
            ->where('results.1.name', 'Kovai SF Express')
            ->where('results.1.from.code', 'MAS')
            ->where('results.1.from.name', 'MGR Chennai Central')
            ->where('results.1.to.code', 'CBE')
            ->where('results.1.status', null)       // not fetched per result (quota)
            ->where('results.1.delayMinutes', null)
            ->where('results.1.delayIsLive', false));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/lookup/search/trains') && $r['q'] === '1267' && $r['limit'] === '10');
    }

    public function test_leading_zeros_are_preserved(): void
    {
        $live = $this->fixture('live-12675');
        $live['data']['trainNumber'] = '06120';
        $this->fakeLive('06120', $live);

        $this->get('/trains?q=06120')->assertInertia(fn (Assert $page) => $page
            ->where('query', '06120')
            ->where('results.0.number', '06120'));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/trains/06120/live'));
    }

    public function test_query_is_trimmed(): void
    {
        $this->fakeLive('12675', $this->fixture('live-12675'));

        $this->get('/trains?q='.urlencode('  12675  '))->assertInertia(fn (Assert $page) => $page->where('query', '12675')->has('results', 1));
    }

    public function test_unknown_number_shows_no_results(): void
    {
        $this->fakeLive('99999', ['success' => false, 'error' => ['code' => 'NOT_FOUND']], 404);
        $this->fakeLookup(['success' => true, 'data' => [], 'meta' => []]);

        $this->get('/trains?q=99999')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('results', [])
            ->where('searchError', null));

        Http::assertSentCount(2); // live lookup, then the train lookup as a fallback
    }

    public function test_lookup_not_found_is_treated_as_no_results(): void
    {
        $this->fakeLookup(['success' => false, 'error' => ['code' => 'NOT_FOUND']], 404);

        $this->get('/trains?q=zzzz')->assertInertia(fn (Assert $page) => $page->where('results', [])->where('searchError', null));
    }

    public function test_empty_and_short_queries_make_no_request(): void
    {
        Http::fake();

        $this->get('/trains?q=')->assertInertia(fn (Assert $page) => $page->where('results', null));
        $this->get('/trains?q='.urlencode('   '))->assertInertia(fn (Assert $page) => $page->where('results', null));
        $this->get('/trains?q=1')->assertInertia(fn (Assert $page) => $page->where('results', null));
        $this->get('/trains?all=1')->assertInertia(fn (Assert $page) => $page->where('showAll', false)->where('results', null));

        Http::assertNothingSent();
    }

    public function test_repeated_searches_are_served_from_cache(): void
    {
        $this->fakeLookup();
        $this->fakeLive('12675', $this->fixture('live-12675'));

        foreach (range(1, 3) as $_) {
            $this->get('/trains?q=1267')->assertOk();
            $this->get('/trains?q=12675')->assertOk();
        }

        Http::assertSentCount(2);
    }

    public function test_live_cache_lock_still_guards_number_search(): void
    {
        $this->fakeLive('12675', $this->fixture('live-12675'));
        Cache::lock('railradar:live:lock:12675', 15)->get(); // refresh in flight elsewhere
        Sleep::fake(syncWithCarbon: true);

        $this->get('/trains?q=12675')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('results', null)
            ->where('searchError', fn (string $error) => str_contains($error, 'did not respond')));

        Http::assertNothingSent();
    }

    public function test_authentication_error(): void
    {
        $this->fakeLookup(['success' => false], 401);
        $this->assertSearchError('credentials');
    }

    public function test_rate_limit(): void
    {
        $this->fakeLookup(['success' => false], 429);
        $this->assertSearchError('rate limit');
    }

    public function test_api_unavailable(): void
    {
        $this->fakeLookup(['success' => false], 503);
        $this->assertSearchError('temporarily unavailable');
    }

    public function test_timeout(): void
    {
        Http::fake([self::LOOKUP_URL => fn () => throw new ConnectionException('cURL error 28')]);
        $this->assertSearchError('did not respond');
    }

    public function test_malformed_response(): void
    {
        $this->fakeLookup(['success' => true, 'data' => ['number' => '12675']]);
        $this->assertSearchError('not a list');
    }

    public function test_mock_search_is_unchanged(): void
    {
        $this->seed();
        app()->forgetInstance(RailwayProvider::class);
        config(['railway.provider' => 'mock']);
        Http::fake();

        $this->get('/trains?q=kovai')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('liveData', false)
            ->where('searchOnSubmit', false)
            ->has('results', 2));
        $this->get('/trains?all=1')->assertInertia(fn (Assert $page) => $page->where('showAll', true)->has('results', 16));

        Http::assertNothingSent();
    }

    public function test_api_key_never_leaks(): void
    {
        Log::spy();
        $this->fakeLookup(['success' => false, 'error' => ['message' => 'invalid']], 401);

        $this->get('/trains?q=Kovai')->assertDontSee(self::FAKE_KEY);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => ! str_contains($message.json_encode($context), self::FAKE_KEY));
    }

    private function assertSearchError(string $fragment): TestResponse
    {
        return $this->get('/trains?q=Kovai')
            ->assertOk()
            ->assertDontSee(self::FAKE_KEY)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Trains/Search')
                ->where('results', null)
                ->where('searchError', fn (string $error) => str_contains($error, $fragment)));
    }
}
