<?php

namespace App\Console\Commands;

use App\Railway\Support\StationDirectoryImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Rebuilds database/data/station_directory.json from an open station dataset.
 * Development-time only: the app never calls this at runtime, and it makes no
 * RailRadar request. Load the result with `php artisan db:seed`.
 */
class ImportStationDirectory extends Command
{
    public const DEFAULT_SOURCE = 'https://raw.githubusercontent.com/datameet/railways/master/stations.json';

    protected $signature = 'railway:import-stations
        {source? : URL or local path of a DataMeet-format station GeoJSON (defaults to DataMeet)}
        {--source-version= : Version of the source (e.g. a git commit) recorded in the output}
        {--output= : Output path (defaults to database/data/station_directory.json)}';

    protected $description = 'Rebuild the local station directory from an open station dataset';

    public function handle(StationDirectoryImporter $importer): int
    {
        $source = (string) ($this->argument('source') ?: self::DEFAULT_SOURCE);
        $output = (string) ($this->option('output') ?: database_path('data/station_directory.json'));

        $raw = str_starts_with($source, 'http') ? Http::timeout(60)->get($source)->throw()->body() : File::get($source);
        $geojson = json_decode($raw, true);
        if (! is_array($geojson) || ! is_array($geojson['features'] ?? null)) {
            $this->error('The source is not a GeoJSON FeatureCollection.');

            return self::FAILURE;
        }

        $result = $importer->import($geojson);

        $document = [
            '_source' => [
                'name' => 'DataMeet Indian Railways Data (stations.json)',
                'url' => $source,
                'license' => 'CC0 1.0 (public domain)',
                'version' => $this->option('source-version') ?: null,
                'imported_at' => now()->toDateString(),
                'filters' => 'Kept stations with a real code (1-8 letters/digits), a name and Point coordinates inside India; '
                    .'dropped duplicate codes (first kept), stations sharing identical coordinates (town-level geocodes) '
                    .'and stations more than 900 km from the median of their own state.',
                'source_features' => count($geojson['features']),
                'skipped' => array_map('count', $result['skipped']),
            ],
            'fields' => StationDirectoryImporter::FIELDS,
            'stations' => $result['stations'],
        ];

        // One station per line: readable diffs when the dataset is refreshed.
        $lines = array_map(fn (array $row) => '        '.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $result['stations']);
        $header = json_encode(['_source' => $document['_source'], 'fields' => $document['fields']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        File::put($output, substr($header, 0, -2).",\n    \"stations\": [\n".implode(",\n", $lines)."\n    ]\n}\n");

        $this->info(sprintf('Wrote %d stations to %s', count($result['stations']), $output));
        foreach ($result['skipped'] as $reason => $codes) {
            $this->line(sprintf('  skipped %-20s %d', $reason, count($codes)));
        }

        return self::SUCCESS;
    }
}
