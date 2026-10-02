<?php

namespace App\Railway\RailRadar;

use App\Railway\Contracts\RailwayProvider;
use App\Railway\Data\LiveStatus;
use App\Railway\Data\StationDetail;
use App\Railway\Data\TrainDetail;
use App\Railway\Enums\BoardType;
use App\Railway\Exceptions\RailwayDataException;
use App\Railway\Support\LocalStationDirectory;
use Carbon\CarbonImmutable;

/**
 * Real railway data from RailRadar (https://railradar.in/docs).
 *
 * Integrated: live train status, the station live board and station search.
 * RailRadar has no nearby-station endpoint, so nearby/featured stations come
 * from the app's local station dataset (real codes + coordinates, no API calls).
 * Train search is not integrated and throws a clear "not supported" error.
 */
final class RailRadarProvider implements RailwayProvider
{
    public function __construct(
        private readonly RailRadarClient $client,
        private readonly RailRadarNormalizer $normalizer,
        private readonly LocalStationDirectory $directory,
        private readonly LiveSnapshotStore $snapshots,
    ) {}

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    public function train(string $number): ?TrainDetail
    {
        try {
            $response = $this->client->liveTrain($number);
        } catch (RailwayDataException $e) {
            if ($e->reason === RailwayDataException::NOT_FOUND) {
                return null; // contract: null when the train does not exist
            }
            throw $e;
        }

        $train = $this->normalizer->trainDetail($response['data'], $response['meta']);
        $trackingMode = is_string($response['data']['trackingMode'] ?? null) ? $response['data']['trackingMode'] : null;

        // Attach real position fixes (same cached response for Train Details and Live Map).
        return $train->withLive($this->snapshots->apply($train->number, $train->live, $trackingMode, $this->now()));
    }

    public function liveStatus(string $number): ?LiveStatus
    {
        return $this->train($number)?->live;
    }

    /** Local Haversine calculation — RailRadar documents no nearby/geo endpoint. */
    public function nearbyStations(float $lat, float $lng, float $radiusKm, int $limit): array
    {
        return $this->directory->nearby($lat, $lng, $radiusKm, $limit);
    }

    /**
     * From the local dataset: only identity and location (no live request per
     * station). Platform counts/facilities are unknown here; the dashboard
     * loads live RailRadar data once a station is opened.
     */
    public function popularStations(array $codes): array
    {
        return array_map(fn ($s) => new StationDetail(
            code: $s->code,
            name: $s->name,
            fullName: null,
            city: $s->city,
            state: $s->state,
            lat: $s->lat,
            lng: $s->lng,
            platforms: null,
            image: null,
            facilities: null,
        ), $this->directory->find($codes));
    }

    public function searchStations(string $query, int $limit = 10): array
    {
        // Short queries never reach the API (protects the monthly quota).
        if (mb_strlen(trim($query)) < (int) config('railway.search_min_length', 2)) {
            return [];
        }

        try {
            $response = $this->client->searchStations($query, $limit);
        } catch (RailwayDataException $e) {
            if ($e->reason === RailwayDataException::NOT_FOUND) {
                return []; // documented 404 for "not found" → no results
            }
            throw $e;
        }

        return $this->normalizer->stationSearch($response['data'], $response['meta']);
    }

    /** Hours ahead requested from the live board (documented: 2, 4, 6, 8). */
    private const BOARD_HOURS = 8;

    public function station(string $code): ?StationDetail
    {
        try {
            $response = $this->client->stationLive($code, self::BOARD_HOURS);
        } catch (RailwayDataException $e) {
            if ($e->reason === RailwayDataException::NOT_FOUND) {
                return null;
            }
            throw $e;
        }

        return $this->normalizer->stationDetail($response['data'], $code);
    }

    /** Same (cached) request as station(), so a dashboard view costs one API call. */
    public function stationBoard(string $code, BoardType $type): array
    {
        $response = $this->client->stationLive($code, self::BOARD_HOURS);

        return $this->normalizer->stationBoard($response['data'], $type, $response['meta']);
    }

    public function searchTrains(string $query, int $limit = 10): array
    {
        throw RailwayDataException::notSupported('Train search');
    }
}
