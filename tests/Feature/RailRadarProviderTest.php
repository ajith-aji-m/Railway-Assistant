<?php

namespace Tests\Feature;

use App\Railway\Contracts\RailwayProvider;
use App\Railway\Enums\GpsStatus;
use App\Railway\Enums\RunningStatus;
use App\Railway\Enums\StopState;
use App\Railway\Exceptions\RailwayDataException;
use App\Railway\Mock\MockRailwayProvider;
use App\Railway\RailRadar\RailRadarProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RailRadarProviderTest extends TestCase
{
    private const FAKE_KEY = 'rr_test_fake_key_for_tests';

    private const FIXTURE_NOW = '2026-10-02 10:45:00';

    private const LIVE_URL = 'https://api.railradar.in/v1/trains/12675/live*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'railway.provider' => 'railradar',
            'services.railradar.key' => self::FAKE_KEY,
            'services.railradar.base_url' => 'https://api.railradar.in',
            'services.railradar.cache_seconds' => 0,
        ]);
        Http::preventStrayRequests();

        // Fixtures are real RailRadar responses from 2026-10-02 ~10:42 IST; pin "now"
        // just after them so fixes are fresh (the stale threshold is 10 minutes).
        \Carbon\CarbonImmutable::setTestNow(self::FIXTURE_NOW);
    }

    protected function tearDown(): void
    {
        \Carbon\CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function provider(): RailwayProvider
    {
        return app(RailwayProvider::class);
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/railradar/live-12675.json')), true);
    }

    /** @return RailwayDataException */
    private function expectFailure(callable $call): RailwayDataException
    {
        try {
            $call();
        } catch (RailwayDataException $e) {
            $this->assertStringNotContainsString(self::FAKE_KEY, $e->getMessage());

            return $e;
        }
        $this->fail('Expected RailwayDataException');
    }

    public function test_provider_switch(): void
    {
        $this->assertInstanceOf(RailRadarProvider::class, $this->provider());

        app()->forgetInstance(RailwayProvider::class);
        config(['railway.provider' => 'mock']);
        $this->assertInstanceOf(MockRailwayProvider::class, app(RailwayProvider::class));
    }

    public function test_live_status_is_normalized(): void
    {
        Http::fake([self::LIVE_URL => Http::response($this->fixture())]);

        $train = $this->provider()->train('12675');

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::FAKE_KEY)
            && str_contains($r->url(), '/v1/trains/12675/live')
            && $r['includeCoordinates'] === 'true');

        $this->assertSame('12675', $train->number);
        $this->assertSame('Kovai SF Express', $train->name);
        $this->assertSame('Superfast Express', $train->type);
        $this->assertSame(['MAS', 'CBE'], [$train->from->code, $train->to->code]);
        $this->assertSame(['06:10', '14:05'], [$train->departs, $train->arrives]);
        $this->assertTrue($train->hasPantry, 'PC coach present in coachPosition');
        $this->assertNull($train->zone);
        $this->assertNull($train->wifiStations);
        $this->assertCount(15, $train->route);

        $live = $train->live;
        $this->assertSame(RunningStatus::Running, $live->status);
        $this->assertSame(GpsStatus::Active, $live->gps); // trackingMode = real-time
        $this->assertSame(6, $live->delayMinutes);
        $this->assertNull($live->speedKmh, 'RailRadar did not report live speed');
        $this->assertSame(['lat' => 11.875847, 'lng' => 78.15302], $live->position);
        $this->assertFalse($live->atStation);
        $this->assertSame(63, $live->lastStopSequence); // Morappur
        $this->assertSame(72, $live->nextStopSequence); // Salem Jn
        $this->assertSame(39.7, $live->distanceFromLastKm); // 307.95 − 268.3
        $this->assertSame(26.5, $live->distanceToNextKm);
        $this->assertSame('2026-10-02', $live->journeyDate);
        $this->assertSame('2026-10-02T10:42:12+05:30', $live->updatedAt);

        $this->assertCount(12, $live->stops, 'Only halts become stops');
        $salem = collect($live->stops)->firstWhere('sequence', 72);
        $this->assertSame(StopState::Next, $salem->state);
        $this->assertSame(['11:02', '11:13', '4', 11], [$salem->scheduledArrival, $salem->expectedArrival, $salem->platform, $salem->delayMinutes]);
        $this->assertSame(StopState::Departed, $live->stops[0]->state);
        $this->assertSame(StopState::Upcoming, $live->stops[count($live->stops) - 1]->state);
    }

    public function test_unauthorized(): void
    {
        Log::spy();
        Http::fake([self::LIVE_URL => Http::response(['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'bad key']], 401)]);

        $e = $this->expectFailure(fn () => $this->provider()->train('12675'));

        $this->assertSame(RailwayDataException::UNAUTHORIZED, $e->reason);
        $this->assertSame(503, $e->getStatusCode());
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => ! str_contains(json_encode($ctx), self::FAKE_KEY));
    }

    public function test_train_not_found_returns_null(): void
    {
        Http::fake(['https://api.railradar.in/v1/trains/99999/live*' => Http::response([
            'success' => false,
            'error' => ['code' => 'NOT_FOUND', 'message' => 'Train 99999 not found'],
            'meta' => ['traceId' => 'trace-1'],
        ], 404)]);

        $this->assertNull($this->provider()->train('99999'));
        $this->assertNull($this->provider()->liveStatus('99999'));
    }

    public function test_invalid_train_number_makes_no_request(): void
    {
        Http::fake();
        $this->assertNull($this->provider()->train('12a'));
        Http::assertNothingSent();
    }

    public function test_rate_limited(): void
    {
        Http::fake([self::LIVE_URL => Http::response(['success' => false], 429, ['Retry-After' => '120'])]);

        $e = $this->expectFailure(fn () => $this->provider()->train('12675'));

        $this->assertSame(RailwayDataException::RATE_LIMITED, $e->reason);
        $this->assertSame(120, $e->retryAfterSeconds);
        $this->assertSame(['Retry-After' => '120'], $e->getHeaders());
    }

    public function test_service_unavailable(): void
    {
        Http::fake([self::LIVE_URL => Http::response('upstream degraded', 503)]);

        $this->assertSame(RailwayDataException::UNAVAILABLE, $this->expectFailure(fn () => $this->provider()->train('12675'))->reason);
    }

    public function test_timeout(): void
    {
        Http::fake([self::LIVE_URL => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        $e = $this->expectFailure(fn () => $this->provider()->train('12675'));

        $this->assertSame(RailwayDataException::TIMEOUT, $e->reason);
        $this->assertNull($e->getPrevious(), 'Original exception is not chained');
    }

    public function test_invalid_responses(): void
    {
        Http::fake([self::LIVE_URL => Http::sequence()
            ->push('<html>oops</html>', 200)
            ->push(['success' => false, 'data' => null], 200)
            ->push(['success' => true, 'data' => ['trainNumber' => '12675']], 200)]);

        foreach (['body is not JSON', 'success flag', 'missing route'] as $detail) {
            $e = $this->expectFailure(fn () => $this->provider()->train('12675'));
            $this->assertSame(RailwayDataException::INVALID_RESPONSE, $e->reason);
            $this->assertStringContainsString($detail, $e->getMessage());
        }
    }

    public function test_missing_key_makes_no_request(): void
    {
        config(['services.railradar.key' => '']);
        app()->forgetInstance(RailwayProvider::class);
        Http::fake();

        $this->assertSame(RailwayDataException::NOT_CONFIGURED, $this->expectFailure(fn () => $this->provider()->train('12675'))->reason);
        Http::assertNothingSent();
    }

    public function test_responses_are_cached_to_save_quota(): void
    {
        config(['services.railradar.cache_seconds' => 60]);
        Http::fake([self::LIVE_URL => Http::response($this->fixture())]);

        $this->provider()->train('12675');
        $this->provider()->train('12675');

        Http::assertSentCount(1);
    }

    public function test_exact_train_number_search_reuses_the_cached_live_request(): void
    {
        config(['services.railradar.cache_seconds' => 60]);
        Http::fake([self::LIVE_URL => Http::response($this->fixture())]);

        $results = $this->provider()->searchTrains('12675');
        $this->provider()->train('12675');

        $this->assertSame('12675', $results[0]->number);
        $this->assertSame(RunningStatus::Running, $results[0]->status);
        Http::assertSentCount(1);
    }

    public function test_diagnostic_command_prints_safe_summary(): void
    {
        Http::fake([self::LIVE_URL => Http::response($this->fixture())]);

        $this->artisan('railradar:check', ['train' => '12675'])
            ->expectsOutputToContain('RailRadar: Connected')
            ->expectsOutputToContain('Train: 12675 – Kovai SF Express')
            ->expectsOutputToContain('Delay: 6 minutes')
            ->expectsOutputToContain('Next station: Salem Jn (SA)')
            ->expectsOutputToContain('Coordinates: 11.875847, 78.15302')
            ->doesntExpectOutputToContain(self::FAKE_KEY)
            ->assertSuccessful();
    }

    public function test_diagnostic_command_reports_failures_safely(): void
    {
        Http::fake([self::LIVE_URL => Http::response(['success' => false], 401)]);

        $this->artisan('railradar:check', ['train' => '12675'])
            ->expectsOutputToContain('Reason: unauthorized')
            ->doesntExpectOutputToContain(self::FAKE_KEY)
            ->assertFailed();
    }
}
