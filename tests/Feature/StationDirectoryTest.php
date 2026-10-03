<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Railway\Contracts\RailwayProvider;
use App\Railway\Support\StationDirectoryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The expanded local station directory (database/data/station_directory.json). */
class StationDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Nagercoil Jn's coordinates in the directory. */
    private const NCJ_LAT = 8.17385;

    private const NCJ_LNG = 77.44347;

    /** Priority 1 (southern Tamil Nadu): code => name in the directory. */
    private const PRIORITY = [
        'NCJ' => 'Nagercoil Jn', 'CAPE' => 'Kanyakumari', 'ERL' => 'Eraniel', 'PYD' => 'Palliyadi',
        'KZT' => 'Kulitthurai', 'VLY' => 'Valliyur', 'TEN' => 'Tirunelveli', 'TN' => 'Tuticorin',
        'TSI' => 'Tenkasi Jn', 'MDU' => 'Madurai Jn', 'DG' => 'Dindigul Jn', 'VPT' => 'Virudunagar Jn',
        'SVKS' => 'Sivakasi', 'CVP' => 'Kovilpatti', 'RMD' => 'Ramanathapuram', 'RMM' => 'Rameswaram',
    ];

    private function directory(): array
    {
        return File::json(database_path('data/station_directory.json'));
    }

    // --- Station data ---

    public function test_directory_file_has_unique_codes_and_valid_coordinates(): void
    {
        $directory = $this->directory();
        $this->assertSame(StationDirectoryImporter::FIELDS, $directory['fields']);
        $this->assertSame('CC0 1.0 (public domain)', $directory['_source']['license']);

        $codes = array_column($directory['stations'], 0);
        $this->assertGreaterThan(8000, count($codes));
        $this->assertSame(count($codes), count(array_unique($codes)), 'duplicate station codes');

        foreach ($directory['stations'] as [$code, $name, , , $lat, $lng]) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{1,8}$/', $code);
            $this->assertNotSame('', $name);
            $this->assertTrue($lat >= 6 && $lat <= 37.5 && $lng >= 68 && $lng <= 97.5, "{$code} coordinates outside India");
        }
    }

    public function test_seeded_stations_have_no_duplicates_and_keep_the_mock_network(): void
    {
        $this->assertGreaterThan(8000, Station::count());
        $this->assertSame(0, Station::query()->selectRaw('code, count(*) c')->groupBy('code')->having('c', '>', 1)->count());

        // The mock network's richer rows still win for its own stations.
        $mas = Station::where('code', 'MAS')->first();
        $this->assertSame('Chennai Central', $mas->name);
        $this->assertSame(17, $mas->platforms);
        $this->assertSame(['wifi', 'food', 'taxi', 'elevator', 'charging'], $mas->facilities);
    }

    public function test_priority_stations_exist_with_their_codes(): void
    {
        $stations = Station::query()->whereIn('code', array_keys(self::PRIORITY))->pluck('name', 'code')->all();
        ksort($stations);
        $expected = self::PRIORITY;
        ksort($expected);

        $this->assertSame($expected, $stations);
    }

    public function test_renamed_codes_and_aliases_from_overrides(): void
    {
        $this->assertNull(Station::where('code', 'CSTM')->first()); // replaced by the official code
        $csmt = Station::where('code', 'CSMT')->first();
        $this->assertSame('Mumbai CSMT', $csmt->name);
        $this->assertContains('CSTM', $csmt->aliases);

        $this->get('/?q=CSTM')->assertInertia(fn (Assert $page) => $page->where('searchResults.0.code', 'CSMT'));
        $this->get('/?q=thoothukudi')->assertInertia(fn (Assert $page) => $page->where('searchResults.0.code', 'TN'));
    }

    public function test_inactive_stations_are_hidden_from_nearby_and_search(): void
    {
        Station::where('code', 'NJT')->update(['is_active' => false]); // Nagercoil Town

        $this->get('/?lat='.self::NCJ_LAT.'&lng='.self::NCJ_LNG.'&all=1')->assertInertia(fn (Assert $page) => $page
            ->where('nearby', fn ($nearby) => ! collect($nearby)->contains('code', 'NJT')));
        $this->get('/?q=nagercoil')->assertInertia(fn (Assert $page) => $page
            ->where('searchResults', fn ($results) => collect($results)->pluck('code')->all() === ['NCJ']));
    }

    public function test_importer_keeps_only_trustworthy_stations(): void
    {
        $point = fn (string $code, ?string $name, ?array $coords, ?string $state = null) => [
            'type' => 'Feature',
            'properties' => ['code' => $code, 'name' => $name, 'state' => $state, 'zone' => 'SR'],
            'geometry' => $coords ? ['type' => 'Point', 'coordinates' => $coords] : null,
        ];
        $features = [
            $point('NCJ', 'NAGERCOIL JN', [77.44347, 8.17385], 'Tamil Nadu'),
            $point('NCJ', 'DUPLICATE', [77.5, 8.2], 'Tamil Nadu'),
            $point('XX-ABC', 'PLACEHOLDER', [77.0, 8.0]),
            $point('NOGEO', 'NO GEOMETRY', null),
            $point('ABROAD', 'ABROAD', [2.35, 48.85]),
            $point('TWN1', 'TOWN ONE', [78.0, 9.0]),
            $point('TWN2', 'TOWN TWO', [78.0, 9.0]),
            $point('Kzt', 'Kulitthurai', [77.218817, 8.302348], 'Tamil Nadu'),
        ];
        // Tamil Nadu stations around 8-11°N, plus one claiming Tamil Nadu but placed in Punjab.
        foreach (range(1, 5) as $i) {
            $features[] = $point("TN{$i}", "Station {$i}", [78.0 + $i / 10, 10.0 + $i / 10], 'Tamil Nadu');
        }
        $features[] = $point('WRONG', 'WRONG STATE', [75.85, 30.9], 'Tamil Nadu');

        $result = app(StationDirectoryImporter::class)->import(['features' => $features]);
        $rows = collect($result['stations'])->keyBy(0);

        $this->assertSame(['KZT', 'NCJ', 'TN1', 'TN2', 'TN3', 'TN4', 'TN5'], $rows->keys()->all());
        $this->assertSame(['NCJ', 'Nagercoil Jn', 'Tamil Nadu', 'SR', 8.17385, 77.44347], $rows['NCJ']); // first occurrence, title-cased
        $this->assertSame('Kulitthurai', $rows['KZT'][1]); // mixed case kept as written
        $this->assertSame(['NCJ'], $result['skipped']['duplicate_code']);
        $this->assertSame(['XX-ABC'], $result['skipped']['invalid_code']);
        $this->assertSame(['NOGEO'], $result['skipped']['no_coordinates']);
        $this->assertSame(['ABROAD'], $result['skipped']['outside_india']);
        $this->assertSame(['TWN1', 'TWN2'], $result['skipped']['shared_coordinates']);
        $this->assertSame(['WRONG'], $result['skipped']['state_mismatch']);
    }

    // --- Nearby discovery ---

    public function test_nagercoil_area_finds_the_nearby_stations(): void
    {
        $this->get('/?lat='.self::NCJ_LAT.'&lng='.self::NCJ_LNG.'&all=1')->assertInertia(function (Assert $page) {
            $nearby = collect($page->toArray()['props']['nearby']);

            $this->assertSame('NCJ', $nearby->first()['code']); // standing at Nagercoil Jn itself
            foreach (['ERL', 'PYD', 'KZT', 'CAPE'] as $code) {
                $this->assertTrue($nearby->contains('code', $code), "{$code} should be within 50 km of Nagercoil");
            }
            $distances = $nearby->pluck('distanceKm')->all();
            $sorted = $distances;
            sort($sorted);
            $this->assertSame($sorted, $distances);
            $this->assertLessThanOrEqual(50, max($distances));
        });
    }

    public function test_nearby_lookup_never_calls_railradar(): void
    {
        config(['railway.provider' => 'railradar', 'services.railradar.key' => 'rr_test_fake_key_for_tests']);
        app()->forgetInstance(RailwayProvider::class);
        Http::preventStrayRequests();
        Http::fake();

        $this->get('/?lat='.self::NCJ_LAT.'&lng='.self::NCJ_LNG.'&all=1')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('nearby.0.code', 'NCJ'));

        Http::assertNothingSent();
    }

    // --- Manual search ---

    public function test_new_stations_can_be_found_by_manual_search(): void
    {
        foreach (['nagercoil' => 'NCJ', 'kanyakumari' => 'CAPE', 'tirunelveli' => 'TEN', 'eraniel' => 'ERL', 'kulithurai' => 'KZT', 'NCJ' => 'NCJ'] as $query => $code) {
            $this->get('/?q='.$query)->assertInertia(fn (Assert $page) => $page
                ->where('searchResults', fn ($results) => collect($results)->contains('code', $code)));
        }
        $this->get('/?q=madurai')->assertInertia(fn (Assert $page) => $page
            ->where('searchResults', fn ($results) => collect($results)->contains('code', 'MDU')));
    }

    // --- Station selection / train scope ---

    public function test_selecting_a_new_station_loads_its_dashboard_without_fabricated_trains(): void
    {
        // Mock mode has no timetable for directory-only stations: an honest empty board.
        $this->get('/stations/CAPE')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Stations/Show')
            ->where('station.code', 'CAPE')
            ->where('station.name', 'Kanyakumari')
            ->where('station.platforms', null)
            ->where('station.facilities', [])
            ->where('board', [])
            ->where('boardError', null));
    }

    public function test_railradar_dashboard_for_a_new_station_requests_only_that_station(): void
    {
        config([
            'railway.provider' => 'railradar',
            'services.railradar.key' => 'rr_test_fake_key_for_tests',
            'services.railradar.base_url' => 'https://api.railradar.in',
        ]);
        app()->forgetInstance(RailwayProvider::class);
        Http::preventStrayRequests();
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/railradar/station-live-MS.json')), true);
        $fixture['data']['station'] = ['code' => 'NCJ', 'name' => 'Nagercoil Jn', 'city' => 'Nagercoil', 'lat' => 8.17385, 'lng' => 77.44347];
        Http::fake(['https://api.railradar.in/v1/stations/NCJ/live*' => Http::response($fixture)]);
        $this->fakeStationTimetable();

        $this->get('/stations/NCJ?tab=departures')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('station.code', 'NCJ')
            ->where('boardError', null)
            ->has('board'));

        Http::assertSentCount(2); // NCJ's live board + NCJ's timetable only
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/stations/NCJ/live'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/stations/NCJ/trains'));
    }
}
