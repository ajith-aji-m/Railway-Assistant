<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Railway\Contracts\RailwayProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Real-position snapshots for cost-controlled live tracking. RailRadar's
 * coordinates + lastUpdatedAt are the only source; snapshots live in the cache.
 */
class RailRadarLiveSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_KEY = 'rr_test_fake_key_for_tests';

    private const URL = 'https://api.railradar.in/v1/trains/12675/live*';

    /** Real fix in the fixture: 11.875847, 78.15302 at 10:42:12 IST. */
    private const FIX_A = ['lat' => 11.875847, 'lng' => 78.15302, 'at' => '2026-10-02T10:42:12+05:30'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'railway.provider' => 'railradar',
            'services.railradar.key' => self::FAKE_KEY,
            'services.railradar.base_url' => 'https://api.railradar.in',
            'services.railradar.cache_seconds' => 60,
            'services.railradar.stale_after_seconds' => 600,
        ]);
        Http::preventStrayRequests();
        CarbonImmutable::setTestNow('2026-10-02 10:45:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/railradar/live-12675.json')), true);
    }

    /** The same train a few minutes later, further along (values from the real 10:46 run). */
    private function laterFixture(string $at = '2026-10-02T10:46:14+05:30', ?array $coords = ['lat' => 11.853142, 'lng' => 78.12888]): array
    {
        $f = $this->fixture();
        $f['data']['lastUpdatedAt'] = $at;
        if ($coords === null) {
            unset($f['data']['currentLocation']['coordinates']);
        } else {
            $f['data']['currentLocation']['coordinates'] = $coords;
        }

        return $f;
    }

    private function live()
    {
        app()->forgetInstance(RailwayProvider::class);

        return app(RailwayProvider::class)->liveStatus('12675');
    }

    public function test_first_snapshot_is_a_single_real_fix(): void
    {
        Http::fake([self::URL => Http::response($this->fixture())]);

        $live = $this->live();

        $this->assertSame('active', $live->gps->value);
        $this->assertTrue($live->snapshot->authoritative);
        $this->assertSame('real-time', $live->snapshot->trackingMode);
        $this->assertNull($live->snapshot->previous);            // single fix → marker stays put
        $this->assertSame(self::FIX_A, $live->snapshot->lastKnown);
        $this->assertFalse($live->snapshot->stale);
        $this->assertSame('2026-10-02T10:42:12+05:30', $live->updatedAt); // RailRadar timestamp kept
    }

    public function test_train_details_and_map_share_one_cached_request_and_snapshot(): void
    {
        Http::fake([self::URL => Http::response($this->fixture())]);

        $this->get('/trains/12675')->assertInertia(fn (Assert $page) => $page->where('train.live.snapshot.lastKnown', self::FIX_A));
        $this->get('/trains/12675/map')->assertInertia(fn (Assert $page) => $page
            ->where('live.snapshot.lastKnown', self::FIX_A)
            ->where('live.snapshot.previous', null));
        $this->get('/trains/12675/map')->assertOk();
        $this->get('/trains/12675')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_many_viewers_polling_share_one_railradar_request_per_cache_window(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push($this->fixture())
            ->push($this->laterFixture())]);

        // 50 browsers polling the map's live prop within the 60 s window → one upstream call.
        $version = app(HandleInertiaRequests::class)->version(request());
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Trains/LiveMap', 'X-Inertia-Partial-Data' => 'live'];
        foreach (range(1, 50) as $_) {
            $this->get('/trains/12675/map', $headers)->assertOk()->assertJsonPath('props.live.snapshot.lastKnown', self::FIX_A);
        }
        Http::assertSentCount(1);

        // After the cache window the next poll (from any viewer) refreshes once, and
        // everyone then gets the new real fix paired with the previous one.
        CarbonImmutable::setTestNow('2026-10-02 10:47:00');
        $this->travel(61)->seconds();
        foreach (range(1, 50) as $_) {
            $this->get('/trains/12675/map', $headers)->assertOk()
                ->assertJsonPath('props.live.snapshot.previous', self::FIX_A)
                ->assertJsonPath('props.live.snapshot.lastKnown.at', '2026-10-02T10:46:14+05:30');
        }
        Http::assertSentCount(2);
    }

    public function test_newer_fix_gives_a_previous_and_current_real_pair(): void
    {
        config(['services.railradar.cache_seconds' => 0]); // every call reaches the (fake) API
        Http::fake([self::URL => Http::sequence()->push($this->fixture())->push($this->laterFixture())]);

        $this->live();
        CarbonImmutable::setTestNow('2026-10-02 10:47:00');
        $live = $this->live();

        $this->assertTrue($live->snapshot->authoritative);
        $this->assertSame(self::FIX_A, $live->snapshot->previous);
        $this->assertSame(['lat' => 11.853142, 'lng' => 78.12888, 'at' => '2026-10-02T10:46:14+05:30'], $live->snapshot->lastKnown);
        $this->assertSame(['lat' => 11.853142, 'lng' => 78.12888], $live->position);
    }

    public function test_same_timestamp_does_not_create_a_fake_pair(): void
    {
        config(['services.railradar.cache_seconds' => 0]);
        Http::fake([self::URL => Http::sequence()->push($this->fixture())->push($this->fixture())]);

        $this->live();
        $live = $this->live();

        $this->assertNull($live->snapshot->previous);
        $this->assertSame(self::FIX_A, $live->snapshot->lastKnown);
    }

    public function test_missing_coordinates_keep_the_last_real_fix(): void
    {
        config(['services.railradar.cache_seconds' => 0]);
        Http::fake([self::URL => Http::sequence()
            ->push($this->fixture())
            ->push($this->laterFixture('2026-10-02T10:46:14+05:30', null))
            ->push($this->laterFixture('2026-10-02T10:49:00+05:30'))]);

        $this->live();
        $missing = $this->live();

        $this->assertNull($missing->position);                 // never a fake fix
        $this->assertSame('lost', $missing->gps->value);
        $this->assertFalse($missing->snapshot->authoritative);
        $this->assertNull($missing->snapshot->previous);
        $this->assertSame(self::FIX_A, $missing->snapshot->lastKnown); // not overwritten with null

        CarbonImmutable::setTestNow('2026-10-02 10:50:00');
        $back = $this->live();
        $this->assertSame(self::FIX_A, $back->snapshot->previous);    // interpolation resumes from the last real fix
    }

    public function test_stale_fix_is_not_shown_as_live(): void
    {
        Http::fake([self::URL => Http::response($this->fixture())]);
        CarbonImmutable::setTestNow('2026-10-02 10:53:00'); // 10m48s after RailRadar's fix

        $live = $this->live();

        $this->assertTrue($live->snapshot->stale);
        $this->assertSame('lost', $live->gps->value);           // existing GPS-lost treatment
        $this->assertSame(self::FIX_A, $live->snapshot->lastKnown);
        $this->assertSame('2026-10-02T10:42:12+05:30', $live->updatedAt); // no fake timestamp
    }

    public function test_failed_refresh_keeps_the_stored_snapshot(): void
    {
        config(['services.railradar.cache_seconds' => 0]);
        Http::fake([self::URL => Http::sequence()
            ->push($this->fixture())
            ->push(['success' => false], 429)
            ->push($this->laterFixture())]);

        $this->get('/trains/12675/map')->assertOk();

        // Background refresh fails → plain 503 (client keeps its map), store untouched.
        $this->get('/trains/12675/map', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Trains/LiveMap',
            'X-Inertia-Partial-Data' => 'live',
        ])->assertStatus(503)->assertHeaderMissing('X-Inertia');

        $this->assertSame(self::FIX_A, $this->live()->snapshot->previous);
    }

    public function test_out_of_order_response_does_not_overwrite_newer_fix(): void
    {
        config(['services.railradar.cache_seconds' => 0]);
        Http::fake([self::URL => Http::sequence()->push($this->laterFixture())->push($this->fixture())]);

        $this->live();
        $older = $this->live();

        $this->assertNull($older->snapshot->previous); // no backwards animation
        $this->assertSame('2026-10-02T10:46:14+05:30', cache()->get('railradar:snapshot:12675')['current']['at']);
    }

    public function test_new_journey_starts_without_a_previous_fix(): void
    {
        config(['services.railradar.cache_seconds' => 0]);
        $nextRun = $this->laterFixture('2026-10-03T10:46:14+05:30');
        $nextRun['data']['startDate'] = '2026-10-03';
        Http::fake([self::URL => Http::sequence()->push($this->fixture())->push($nextRun)]);

        $this->live();
        CarbonImmutable::setTestNow('2026-10-03 10:47:00');
        $live = $this->live();

        $this->assertNull($live->snapshot->previous);
    }

    public function test_mock_mode_has_no_snapshot_and_no_requests(): void
    {
        $this->seed();
        app()->forgetInstance(RailwayProvider::class);
        config(['railway.provider' => 'mock']);
        Http::fake();

        $this->get('/trains/12675/map')->assertInertia(fn (Assert $page) => $page
            ->where('live.snapshot', null)
            ->has('live.position')
            ->where('live.speedKmh', fn ($speed) => is_int($speed)));  // mock simulated speed unchanged

        Http::assertNothingSent();
    }
}
