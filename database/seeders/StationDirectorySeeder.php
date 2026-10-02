<?php

namespace Database\Seeders;

use App\Models\Station;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Loads the station directory (database/data/station_directory.json, rebuilt
 * with `php artisan railway:import-stations`) plus curated corrections
 * (station_overrides.json) into the stations table. Idempotent: upserts by code.
 * Runs before RailwayMockSeeder, whose richer rows then take over for the
 * stations of the mock network.
 */
class StationDirectorySeeder extends Seeder
{
    public function run(): void
    {
        $directory = File::json(database_path('data/station_directory.json'));
        $overrides = File::json(database_path('data/station_overrides.json'))['stations'] ?? [];
        $now = now();

        $rows = [];
        foreach ($directory['stations'] as $values) {
            $row = array_combine($directory['fields'], $values);
            $override = $overrides[$row['code']] ?? [];
            $aliases = $override['aliases'] ?? [];

            if (isset($override['code']) && $override['code'] !== $row['code']) {
                $aliases[] = $row['code']; // the old code stays searchable
                $row['code'] = $override['code'];
            }

            $rows[$row['code']] ??= [ // a code is only ever stored once
                'code' => $row['code'],
                'name' => $override['name'] ?? $row['name'],
                'state' => $row['state'],
                'zone' => $row['zone'],
                'lat' => $row['lat'],
                'lng' => $row['lng'],
                'is_active' => $override['active'] ?? true,
                'aliases' => $aliases ? json_encode(array_values(array_unique($aliases))) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $renamed = array_keys(array_filter($overrides, fn (array $o, string $code) => isset($o['code']) && $o['code'] !== $code, ARRAY_FILTER_USE_BOTH));

        DB::transaction(function () use ($rows, $renamed) {
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                Station::query()->upsert($chunk, ['code'], ['name', 'state', 'zone', 'lat', 'lng', 'is_active', 'aliases', 'updated_at']);
            }
            // Rows left behind under a since-renamed code (unless a train still uses them).
            Station::query()->whereIn('code', $renamed)->whereDoesntHave('stops')->delete();
        });
    }
}
