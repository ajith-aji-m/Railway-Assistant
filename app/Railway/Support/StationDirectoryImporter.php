<?php

namespace App\Railway\Support;

/**
 * Normalizes a DataMeet-format station GeoJSON (FeatureCollection of Points with
 * code / name / state / zone properties) into the station directory rows stored
 * in database/data/station_directory.json. Only stations whose code and
 * coordinates look trustworthy are kept; nothing is invented or guessed.
 */
class StationDirectoryImporter
{
    /** Columns of each row in station_directory.json. */
    public const FIELDS = ['code', 'name', 'state', 'zone', 'lat', 'lng'];

    /** Indian Railways codes: 1-8 letters/digits (placeholder codes such as "XX-ABC" are not real). */
    private const CODE_PATTERN = '/^[A-Z0-9]{1,8}$/';

    /** Generous bounding box around India's rail network. */
    private const LAT_RANGE = [6.0, 37.5];

    private const LNG_RANGE = [68.0, 97.5];

    /** A station this far from the median of its state's stations contradicts its own state. */
    private const STATE_MISMATCH_KM = 900;

    /**
     * Rows (see FIELDS) sorted by code, and the codes left out per reason.
     *
     * @return array{stations: list<list<string|float|null>>, skipped: array<string, list<string>>}
     */
    public function import(array $geojson): array
    {
        $skipped = [
            'no_coordinates' => [],
            'invalid_code' => [],
            'missing_name' => [],
            'outside_india' => [],
            'duplicate_code' => [],
            'shared_coordinates' => [],
            'state_mismatch' => [],
        ];
        $stations = [];

        foreach ($geojson['features'] ?? [] as $feature) {
            $props = $feature['properties'] ?? [];
            $code = strtoupper(trim((string) ($props['code'] ?? '')));
            $coords = $feature['geometry']['coordinates'] ?? null;

            if (! preg_match(self::CODE_PATTERN, $code)) {
                $skipped['invalid_code'][] = $code;

                continue;
            }
            if (($feature['geometry']['type'] ?? null) !== 'Point' || ! is_array($coords) || ! is_numeric($coords[0] ?? null) || ! is_numeric($coords[1] ?? null)) {
                $skipped['no_coordinates'][] = $code;

                continue;
            }
            $name = $this->name((string) ($props['name'] ?? ''));
            if ($name === '') {
                $skipped['missing_name'][] = $code;

                continue;
            }
            [$lng, $lat] = [round((float) $coords[0], 6), round((float) $coords[1], 6)];
            if ($lat < self::LAT_RANGE[0] || $lat > self::LAT_RANGE[1] || $lng < self::LNG_RANGE[0] || $lng > self::LNG_RANGE[1]) {
                $skipped['outside_india'][] = $code;

                continue;
            }
            if (isset($stations[$code])) {
                $skipped['duplicate_code'][] = $code; // first occurrence wins

                continue;
            }

            $stations[$code] = [$code, $name, $this->text($props['state'] ?? null), $this->text($props['zone'] ?? null), $lat, $lng];
        }

        // Several different stations at exactly the same point were geocoded to a
        // town, not to the station: their coordinates cannot be trusted.
        $byPoint = [];
        foreach ($stations as $code => $row) {
            $byPoint[$row[4].','.$row[5]][] = $code;
        }
        foreach ($byPoint as $codes) {
            if (count($codes) > 1) {
                foreach ($codes as $code) {
                    $skipped['shared_coordinates'][] = $code;
                    unset($stations[$code]);
                }
            }
        }

        foreach ($this->stateMismatches($stations) as $code) {
            $skipped['state_mismatch'][] = $code;
            unset($stations[$code]);
        }

        ksort($stations, SORT_STRING);

        return ['stations' => array_values($stations), 'skipped' => $skipped];
    }

    /** @return list<string> Codes far away from every other station of their stated state. */
    private function stateMismatches(array $stations): array
    {
        $byState = [];
        foreach ($stations as $row) {
            if ($row[2] !== null) {
                $byState[$row[2]][] = $row;
            }
        }

        $codes = [];
        foreach ($byState as $rows) {
            $lat = $this->median(array_column($rows, 4));
            $lng = $this->median(array_column($rows, 5));
            foreach ($rows as $row) {
                if (Geo::distanceKm($lat, $lng, $row[4], $row[5]) > self::STATE_MISMATCH_KM) {
                    $codes[] = $row[0];
                }
            }
        }

        return $codes;
    }

    private function median(array $values): float
    {
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** "NAGERCOIL JN" → "Nagercoil Jn"; names already in mixed case are kept as written. */
    private function name(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));

        return $name === mb_strtoupper($name) ? mb_convert_case(mb_strtolower($name), MB_CASE_TITLE) : $name;
    }

    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
