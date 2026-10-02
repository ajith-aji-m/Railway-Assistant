<?php

namespace App\Railway\Contracts;

use App\Railway\Data\BoardEntry;
use App\Railway\Data\LiveStatus;
use App\Railway\Data\StationDetail;
use App\Railway\Data\StationSummary;
use App\Railway\Data\TrainDetail;
use App\Railway\Data\TrainSummary;
use App\Railway\Enums\BoardType;
use Carbon\CarbonImmutable;

/**
 * Source of railway data. The app only talks to this contract, so the mock
 * implementation can be swapped for a real railway API without touching
 * controllers or pages.
 */
interface RailwayProvider
{
    /** Current time as seen by the data source (used for "Today" and "Updated" labels). */
    public function now(): CarbonImmutable;

    /** @return list<StationSummary> Nearest first, within the radius. */
    public function nearbyStations(float $lat, float $lng, float $radiusKm, int $limit): array;

    /** @return list<StationSummary> */
    public function searchStations(string $query, int $limit = 10): array;

    public function station(string $code): ?StationDetail;

    /**
     * Featured stations for the station-selection screen. Must not cost one
     * upstream request per station.
     *
     * @param  list<string>  $codes
     * @return list<StationDetail>
     */
    public function popularStations(array $codes): array;

    /** @return list<BoardEntry> Today's trains at the station, ordered by time. */
    public function stationBoard(string $code, BoardType $type): array;

    /** @return list<TrainSummary> */
    public function searchTrains(string $query, int $limit = 10): array;

    public function train(string $number): ?TrainDetail;

    public function liveStatus(string $number): ?LiveStatus;
}
