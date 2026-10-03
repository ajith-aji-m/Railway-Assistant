<?php

namespace Database\Seeders;

use App\Models\Station;
use App\Models\Train;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Loads the mock railway network from database/data/*.json. Stops at stations
 * that are not in stations.json use the station directory's row (seeded first by
 * StationDirectorySeeder): identity and location only, nothing invented.
 */
class RailwayMockSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $stations = collect(File::json(database_path('data/stations.json')))
                ->mapWithKeys(fn (array $row) => [$row['code'] => Station::updateOrCreate(['code' => $row['code']], $row)]);

            $trains = File::json(database_path('data/trains.json'))['trains'];
            $missing = collect($trains)->flatMap(fn (array $t) => array_column($t['stops'], 0))->unique()->diff($stations->keys());
            $stations = $stations->merge(Station::query()->whereIn('code', $missing->all())->get()->keyBy('code'));

            foreach ($trains as $row) {
                $stops = $row['stops'];

                $train = Train::updateOrCreate(['number' => $row['number']], [
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'has_pantry' => $row['pantry'],
                    'zone' => $row['zone'],
                    'origin_station_id' => $stations[$stops[0][0]]->id,
                    'destination_station_id' => $stations[end($stops)[0]]->id,
                ]);

                $train->stops()->delete();
                $delays = [];

                foreach ($stops as $index => [$code, $arrival, $departure, $platform, $distance, $delay, $dayOffset]) {
                    $sequence = $index + 1;
                    $delays[(string) $sequence] = $delay;

                    $train->stops()->create([
                        'station_id' => $stations[$code]->id,
                        'sequence' => $sequence,
                        'scheduled_arrival' => $arrival,
                        'scheduled_departure' => $departure,
                        'day_offset' => $dayOffset,
                        'platform' => $platform,
                        'distance_km' => $distance,
                    ]);
                }

                $train->liveStatus()->updateOrCreate([], [
                    'status' => $row['live']['status'],
                    'gps_status' => $row['live']['gps'],
                    'stop_delays' => $delays,
                ]);
            }
        });
    }
}
