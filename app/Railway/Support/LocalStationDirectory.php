<?php

namespace App\Railway\Support;

use App\Models\Station;
use App\Railway\Data\StationSummary;

/**
 * The application's own station dataset (database/data/station_directory.json
 * + the mock network's stations.json: real station codes and coordinates).
 * RailRadar has no nearby-station endpoint, so nearest stations are computed
 * here with the Haversine formula — no API calls.
 */
class LocalStationDirectory
{
    /** Slightly less than the real ~111.2 km, so the bounding box never cuts off a station inside the radius. */
    private const KM_PER_DEGREE = 110.0;

    /** @return list<StationSummary> Nearest first, within the radius. */
    public function nearby(float $lat, float $lng, float $radiusKm, int $limit): array
    {
        // Indexed bounding-box prefilter (slightly larger than the radius), so only
        // candidate stations are loaded; Haversine in rank() decides the result.
        $dLat = $radiusKm / self::KM_PER_DEGREE;
        $dLng = $radiusKm / (self::KM_PER_DEGREE * max(cos(deg2rad($lat)), 0.01));

        $candidates = Station::query()
            ->where('is_active', true)
            ->whereBetween('lat', [$lat - $dLat, $lat + $dLat])
            ->whereBetween('lng', [$lng - $dLng, $lng + $dLng])
            ->get();

        return $this->rank($candidates, $lat, $lng, $radiusKm, $limit);
    }

    /**
     * Stations without coordinates cannot be ranked by distance and are left out
     * (they remain reachable through manual search).
     *
     * @param  iterable<Station>  $stations
     * @return list<StationSummary> Nearest first, within the radius.
     */
    public function rank(iterable $stations, float $lat, float $lng, float $radiusKm, int $limit): array
    {
        return collect($stations)
            ->filter(fn (Station $s) => $s->lat !== null && $s->lng !== null)
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
