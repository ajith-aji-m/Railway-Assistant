<?php

namespace App\Railway\Support;

use App\Models\Station;
use App\Railway\Data\StationSummary;

/**
 * The application's own station dataset (database/data/stations.json: real
 * station codes and coordinates). RailRadar has no nearby-station endpoint, so
 * nearest stations are computed here with the Haversine formula — no API calls.
 */
class LocalStationDirectory
{
    /** @return list<StationSummary> Nearest first, within the radius. */
    public function nearby(float $lat, float $lng, float $radiusKm, int $limit): array
    {
        return Station::query()->get()
            ->map(fn (Station $s) => $this->summary($s, Geo::distanceKm($lat, $lng, $s->lat, $s->lng)))
            ->filter(fn (StationSummary $s) => $s->distanceKm <= $radiusKm)
            ->sortBy('distanceKm')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $codes
     * @return list<Station> In the given order; unknown codes are skipped.
     */
    public function find(array $codes): array
    {
        $stations = Station::query()->whereIn('code', $codes)->get()->keyBy('code');

        return array_values(array_filter(array_map(fn (string $code) => $stations[$code] ?? null, $codes)));
    }

    public function summary(Station $station, ?float $distanceKm = null): StationSummary
    {
        return new StationSummary(
            code: $station->code,
            name: $station->name,
            city: $station->city,
            state: $station->state,
            lat: $station->lat,
            lng: $station->lng,
            distanceKm: $distanceKm !== null ? round($distanceKm, 1) : null,
        );
    }
}
