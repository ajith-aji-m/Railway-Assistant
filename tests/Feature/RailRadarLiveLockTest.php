<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Railway\Exceptions\RailwayDataException;
use App\Railway\RailRadar\RailRadarClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Train-specific cache lock around RailRadar live refreshes: concurrent cache
 * misses for the same train make one upstream request, the others reuse it.
 */
class RailRadarLiveLockTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_KEY = 'rr_test_fake_key_for_tests';

    private const URL = 'https://api.railradar.in/v1/trains/12675/live*';

    private const LOCK = 'railradar:live:lock:12675';

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
    }

    private function fixture(string $name = 'live-12675'): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/railradar/{$name}.json")), true);
    }

    private function client(int $lockWaitSeconds = 1): RailRadarClient
    {
        return new RailRadarClient(self::FAKE_KEY, 'https://api.railradar.in', timeout: 10, cacheSeconds: 60, lockWaitSeconds: $lockWaitSeconds);
    }

    /** The client's cache key for train 12675's live status. */
    private function cacheKey(): string
    {
        return 'railradar:'.sha1('/v1/trains/12675/live?'.http_build_query(['includeCoordinates' => 'true']));
    }

    /** Payload as the client caches it. */
    private function payload(): array
    {
        $f = $this->fixture();

        return ['data' => $f['data'], 'meta' => $f['meta']];
    }

    public function test_a_single_cache_miss_makes_exactly_one_request(): void
    {
        Http::fake([self::URL => Http::response($this->fixture())]);

        $first = $this->client()->liveTrain('12675');
        $second = $this->client()->liveTrain('12675'); // cached: no lock, no request

        Http::assertSentCount(1);
        $this->assertSame($first, $second);
        $this->assertTrue(Cache::lock(self::LOCK)->get(), 'lock released after the refresh');
    }

    public function test_concurrent_requests_for_the_same_train_make_one_request(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to run requests in parallel.');
        }

        // Real parallel processes sharing a file cache (with file locks).
        $dir = storage_path('framework/testing/lock-'.uniqid());
        File::ensureDirectoryExists($dir);
        config(['cache.default' => 'file', 'cache.stores.file.path' => $dir, 'cache.stores.file.lock_path' => $dir]);
        $counter = "{$dir}/requests";
        $fixture = $this->fixture();
        Http::fake([self::URL => function () use ($counter, $fixture) {
            file_put_contents($counter, "1\n", FILE_APPEND | LOCK_EX);
            usleep(500_000); // a slow upstream widens the race window

            return Http::response($fixture);
        }]);

        $callers = 6;
        $startAt = microtime(true) + 0.5;
        $pids = [];
        for ($i = 0; $i < $callers; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                // Child: all callers start at the same instant, while the cache is empty.
                time_sleep_until($startAt);
                try {
                    $result = json_encode($this->client(lockWaitSeconds: 5)->liveTrain('12675'));
                } catch (\Throwable $e) {
                    $result = 'error: '.$e->getMessage();
                }
                file_put_contents("{$dir}/result-{$i}", $result);
                posix_kill(getmypid(), SIGKILL); // skip PHPUnit shutdown handlers in the child
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = array_map(fn ($i) => file_get_contents("{$dir}/result-{$i}"), range(0, $callers - 1));

        $requests = substr_count(file_get_contents($counter), "\n");
        $this->assertSame(1, $requests, 'exactly one RailRadar request');
        $this->assertCount(1, array_unique($results), 'all callers receive the same result');
        $this->assertSame($this->payload(), json_decode($results[0], true));
        File::deleteDirectory($dir);
    }

    public function test_cache_populated_while_waiting_is_reused(): void
    {
        Http::fake();
        // Another request holds the lock and is refreshing the same train.
        $other = Cache::lock(self::LOCK, 15);
        $this->assertTrue($other->get());

        // While this request waits, the other one stores its response and releases the lock.
        Sleep::fake();
        Sleep::whenFakingSleep(function () use ($other) {
            if (! Cache::has($this->cacheKey())) {
                Cache::put($this->cacheKey(), $this->payload(), 60);
                $other->release();
            }
        });

        $this->assertSame($this->payload(), $this->client()->liveTrain('12675'));
        Http::assertNothingSent();
    }

    public function test_lock_unavailable_never_starts_a_second_request(): void
    {
        Http::fake();
        Cache::lock(self::LOCK, 15)->get(); // held elsewhere for the whole wait
        Sleep::fake(syncWithCarbon: true);  // the wait runs on fake time

        try {
            $this->client()->liveTrain('12675');
            $this->fail('expected the existing timeout error');
        } catch (RailwayDataException $e) {
            $this->assertSame(RailwayDataException::TIMEOUT, $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_lock_unavailable_during_a_background_refresh_keeps_the_existing_map(): void
    {
        Http::fake();
        Cache::lock(self::LOCK, 15)->get();
        Sleep::fake(syncWithCarbon: true);
        $headers = [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Trains/LiveMap',
            'X-Inertia-Partial-Data' => 'live',
        ];

        // Same response as an upstream timeout: not an Inertia page → the client keeps its map.
        $this->get('/trains/12675/map', $headers)
            ->assertStatus(503)
            ->assertHeaderMissing('X-Inertia')
            ->assertDontSee(self::FAKE_KEY);
        Http::assertNothingSent();
    }

    public function test_failed_refresh_releases_the_lock_and_a_later_request_retries(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push('degraded', 503)
            ->push($this->fixture())]);

        try {
            $this->client()->liveTrain('12675');
            $this->fail('expected the existing unavailable error');
        } catch (RailwayDataException $e) {
            $this->assertSame(RailwayDataException::UNAVAILABLE, $e->reason);
        }
        $this->assertFalse(Cache::has($this->cacheKey()), 'failures are not cached');
        $this->assertTrue(Cache::lock(self::LOCK)->get(), 'lock released after the failure');
        Cache::lock(self::LOCK)->forceRelease();

        $this->assertSame($this->payload(), $this->client()->liveTrain('12675'));
        Http::assertSentCount(2);
    }

    public function test_an_expired_lock_does_not_block_future_refreshes(): void
    {
        Http::fake([self::URL => Http::response($this->fixture())]);
        Cache::lock(self::LOCK, 15)->get(); // a holder that crashed and never released

        $this->travel(16)->seconds();

        $this->assertSame($this->payload(), $this->client()->liveTrain('12675'));
        Http::assertSentCount(1);
    }

    public function test_different_trains_have_independent_locks(): void
    {
        Http::fake(['https://api.railradar.in/v1/trains/12635/live*' => Http::response($this->fixture('live-12635-not-started'))]);
        Cache::lock(self::LOCK, 15)->get(); // train 12675 is being refreshed elsewhere
        Sleep::fake(syncWithCarbon: true);

        $this->client()->liveTrain('12635'); // not blocked by 12675's lock

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/trains/12635/live'));
        $this->assertTrue(Cache::lock('railradar:live:lock:12635')->get(), '12635 has its own, released lock');
        $this->assertFalse(Cache::lock(self::LOCK)->get(), '12675 is still locked');
    }

    public function test_station_boards_and_search_are_not_locked(): void
    {
        Http::fake(['https://api.railradar.in/v1/stations/MAS/live*' => Http::response($this->fixture('station-live-MAS'))]);
        Cache::lock(self::LOCK, 15)->get();

        $this->client()->stationLive('MAS');

        Http::assertSentCount(1);
    }
}
