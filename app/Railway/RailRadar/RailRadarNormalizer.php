<?php

namespace App\Railway\RailRadar;

use App\Railway\Data\BoardEntry;
use App\Railway\Data\CurrentLocation;
use App\Railway\Data\LiveStatus;
use App\Railway\Data\StationDetail;
use App\Railway\Data\StationRef;
use App\Railway\Data\StationSummary;
use App\Railway\Data\StopStatus;
use App\Railway\Data\TrainDetail;
use App\Railway\Enums\BoardStatus;
use App\Railway\Enums\BoardType;
use App\Railway\Enums\GpsStatus;
use App\Railway\Enums\RunningStatus;
use App\Railway\Enums\StopState;
use App\Railway\Exceptions\RailwayDataException;
use Illuminate\Support\Arr;

/**
 * Maps RailRadar payloads (`/v1/trains/{number}/live`, `/v1/stations/{code}/live`)
 * onto the app's DTOs.
 * Only fields RailRadar actually returns are used; anything missing stays null.
 */
class RailRadarNormalizer
{
    /** @param  array<string, mixed>  $data  The `data` object of the response. */
    public function trainDetail(array $data, array $meta = []): TrainDetail
    {
        $route = $this->route($data);
        $halts = array_values(array_filter($route, fn (array $s) => ($s['isHalt'] ?? false) === true));
        $train = is_array($data['train'] ?? null) ? $data['train'] : [];

        $source = $train['source'] ?? null;
        $destination = $train['destination'] ?? null;
        $first = $halts[0] ?? $route[0];
        $last = $halts[count($halts) - 1] ?? $route[count($route) - 1];

        return new TrainDetail(
            number: (string) ($data['trainNumber'] ?? $train['number'] ?? throw RailwayDataException::invalidResponse('missing train number')),
            name: (string) ($data['trainName'] ?? $train['name'] ?? ''),
            type: (string) ($train['type'] ?? $train['category'] ?? ''),
            from: new StationRef($source['code'] ?? $first['stationCode'], $source['name'] ?? $first['stationName']),
            to: new StationRef($destination['code'] ?? $last['stationCode'], $destination['name'] ?? $last['stationName']),
            departs: $this->hm($first['scheduledDeparture'] ?? null) ?? '',
            arrives: $this->hm($last['scheduledArrival'] ?? null) ?? '',
            hasPantry: $this->hasPantryCar($train['coachPosition'] ?? null),
            zone: null, // not provided by RailRadar
            image: null,
            wifiStations: null, // not provided by RailRadar
            route: array_values(array_map(fn (array $s) => [(float) $s['lat'], (float) $s['lng']], array_filter($route, fn ($s) => isset($s['lat'], $s['lng'])))),
            live: $this->liveStatus($data, $meta),
        );
    }

    /** @param  array<string, mixed>  $data */
    public function liveStatus(array $data, array $meta = []): LiveStatus
    {
        $route = $this->route($data);
        $current = is_array($data['currentLocation'] ?? null) ? $data['currentLocation'] : [];
        $previousHalt = is_array($data['previousHalt'] ?? null) ? $data['previousHalt'] : null;
        $nextHalt = is_array($data['nextHalt'] ?? null) ? $data['nextHalt'] : null;

        $status = $this->runningStatus($data['status'] ?? null, $route);
        $atStation = ($current['isHalt'] ?? false) === true && isset($current['status']) && $current['status'] !== 'departed';

        // Distances along the route (km from origin).
        $travelled = $this->number($current['distanceFromOriginKm'] ?? null);
        $fromLast = $travelled !== null && isset($previousHalt['distance']) ? $this->km($travelled - (float) $previousHalt['distance']) : null;
        $toNext = $travelled !== null && isset($nextHalt['distance']) ? $this->km((float) $nextHalt['distance'] - $travelled) : null;

        $coords = $current['coordinates'] ?? null;
        $position = is_array($coords) && is_numeric($coords['lat'] ?? null) && is_numeric($coords['lng'] ?? null)
            ? ['lat' => (float) $coords['lat'], 'lng' => (float) $coords['lng']]
            : null;

        $lastSequence = $atStation ? ($current['sequence'] ?? null) : ($previousHalt['sequence'] ?? null);
        $nextSequence = $nextHalt['sequence'] ?? null;

        $stops = [];
        foreach ($route as $stop) {
            if (($stop['isHalt'] ?? false) !== true) {
                continue;
            }
            if (! isset($stop['lat'], $stop['lng'])) {
                throw RailwayDataException::invalidResponse('halt without coordinates', $meta['traceId'] ?? null);
            }

            $sequence = (int) $stop['sequence'];
            $stops[] = new StopStatus(
                sequence: $sequence,
                station: new StationRef((string) $stop['stationCode'], (string) ($stop['stationName'] ?? $stop['stationCode'])),
                lat: (float) $stop['lat'],
                lng: (float) $stop['lng'],
                scheduledArrival: $this->hm($stop['scheduledArrival'] ?? null),
                scheduledDeparture: $this->hm($stop['scheduledDeparture'] ?? null),
                // For upcoming stops RailRadar's actual* fields hold the predicted time.
                expectedArrival: $this->hm($stop['actualArrival'] ?? null) ?? $this->hm($stop['scheduledArrival'] ?? null),
                expectedDeparture: $this->hm($stop['actualDeparture'] ?? null) ?? $this->hm($stop['scheduledDeparture'] ?? null),
                platform: isset($stop['platform']) ? (string) $stop['platform'] : null,
                distanceKm: (int) round((float) ($stop['distance'] ?? 0)),
                delayMinutes: is_numeric($stop['delayArrival'] ?? $stop['delayDeparture'] ?? null) ? (int) ($stop['delayArrival'] ?? $stop['delayDeparture']) : null,
                state: match (true) {
                    $atStation && $sequence === ($current['sequence'] ?? null) => StopState::Current,
                    ($stop['status'] ?? null) === 'departed' => StopState::Departed,
                    $sequence === $nextSequence => StopState::Next,
                    default => StopState::Upcoming,
                },
            );
        }

        $speed = $this->number($current['speedKmh'] ?? null);

        return new LiveStatus(
            status: $status,
            // Never report an active GPS fix without actual coordinates.
            gps: $position === null ? GpsStatus::Lost : $this->gpsStatus($data, $current),
            delayMinutes: (int) ($data['delayMinutes'] ?? $current['delayMinutes'] ?? 0),
            speedKmh: $speed !== null ? (int) round($speed) : null, // only when RailRadar reports live speed
            position: $position,
            atStation: $atStation,
            lastStopSequence: $lastSequence !== null ? (int) $lastSequence : null,
            nextStopSequence: $nextSequence !== null ? (int) $nextSequence : null,
            distanceFromLastKm: $fromLast,
            distanceToNextKm: $toNext,
            journeyDate: (string) ($data['startDate'] ?? ''),
            updatedAt: (string) ($data['lastUpdatedAt'] ?? $meta['timestamp'] ?? ''),
            stops: $stops,
            currentLocation: $this->currentLocation($current),
        );
    }

    /** `currentLocation` (observed: stationCode, stationName, isHalt, distanceFromLastStationKm). */
    private function currentLocation(array $current): ?CurrentLocation
    {
        if (! is_string($current['stationCode'] ?? null) || $current['stationCode'] === '') {
            return null;
        }

        $distance = $this->number($current['distanceFromLastStationKm'] ?? null);

        return new CurrentLocation(
            station: new StationRef($current['stationCode'], (string) ($current['stationName'] ?? $current['stationCode'])),
            isHalt: ($current['isHalt'] ?? false) === true,
            distanceFromStationKm: $distance !== null ? $this->km($distance) : null,
        );
    }

    /**
     * Results of `/v1/lookup/search/stations` (observed fields: code, name, city,
     * popularity, isActive). Inactive stations are left out — they have no service.
     *
     * @param  mixed  $data  The `data` array of the response.
     * @return list<StationSummary>
     */
    public function stationSearch(mixed $data, array $meta = []): array
    {
        if (! is_array($data) || ! Arr::isList($data)) {
            throw RailwayDataException::invalidResponse('station search data is not a list', $meta['traceId'] ?? null);
        }

        $results = [];
        foreach ($data as $item) {
            if (! is_array($item) || ! is_string($item['code'] ?? null) || $item['code'] === '' || ($item['isActive'] ?? true) === false) {
                continue;
            }

            $results[] = new StationSummary(
                code: $item['code'],
                name: is_string($item['name'] ?? null) ? $item['name'] : $item['code'],
                city: is_string($item['city'] ?? null) && $item['city'] !== '' ? $item['city'] : null,
                state: null, // not provided
                lat: $this->number($item['lat'] ?? null),
                lng: $this->number($item['lng'] ?? null),
            );
        }

        return $results;
    }

    /**
     * Station header from the `station` object of `/v1/stations/{code}/live`
     * (observed fields: code, name, city, lat, lng).
     *
     * @param  array<string, mixed>  $data
     */
    public function stationDetail(array $data, string $requestedCode): StationDetail
    {
        $station = is_array($data['station'] ?? null) ? $data['station'] : [];
        $code = (string) ($station['code'] ?? strtoupper($requestedCode));

        return new StationDetail(
            code: $code,
            name: (string) ($station['name'] ?? $code),
            fullName: null, // not provided
            city: isset($station['city']) ? (string) $station['city'] : null,
            state: null, // not provided
            lat: $this->number($station['lat'] ?? null),
            lng: $this->number($station['lng'] ?? null),
            platforms: null, // not provided
            image: null,
            facilities: null, // not provided
        );
    }

    /**
     * Arrivals (stops with an arrival time) or departures (stops with a departure
     * time) from `/v1/stations/{code}/live`, ordered by expected time.
     *
     * @param  array<string, mixed>  $data
     * @return list<BoardEntry>
     */
    public function stationBoard(array $data, BoardType $type, array $meta = []): array
    {
        $trains = $data['trains'] ?? null;

        if (! is_array($trains) || ! Arr::isList($trains)) {
            throw RailwayDataException::invalidResponse('missing trains list', $meta['traceId'] ?? null);
        }

        $arrivals = $type === BoardType::Arrivals;
        $entries = [];

        foreach ($trains as $item) {
            $train = is_array($item['train'] ?? null) ? $item['train'] : null;
            $stop = is_array($item['stop'] ?? null) ? $item['stop'] : [];
            $live = is_array($item['live'] ?? null) ? $item['live'] : [];

            $scheduled = $arrivals ? ($stop['arrival'] ?? null) : ($stop['departure'] ?? null);
            if ($train === null || ! isset($train['number']) || ! is_string($scheduled) || ! preg_match('/^\d{2}:\d{2}/', $scheduled)) {
                continue; // not an arrival/departure for this tab
            }

            $expectedIso = $arrivals ? ($live['expectedArrivalTime'] ?? null) : ($live['expectedDepartureTime'] ?? null);
            $platform = $live['platform'] ?? $stop['platform'] ?? null; // live platform when reported, else timetable platform
            $source = is_string($train['source'] ?? null) ? $train['source'] : null;
            $destination = is_string($train['destination'] ?? null) ? $train['destination'] : null;

            $entries[] = [
                'sort' => is_string($expectedIso) ? $expectedIso : null,
                'entry' => new BoardEntry(
                    trainNumber: (string) $train['number'],
                    trainName: (string) ($train['name'] ?? $train['number']),
                    trainType: (string) ($train['type'] ?? ''),
                    // RailRadar gives only station codes for origin/destination here.
                    from: new StationRef($source ?? '—', $source ?? '—'),
                    to: new StationRef($destination ?? '—', $destination ?? '—'),
                    scheduledTime: substr($scheduled, 0, 5),
                    expectedTime: $this->hm($expectedIso),
                    platform: $platform !== null && $platform !== '' ? (string) $platform : null,
                    delayMinutes: is_numeric($live['delayMinutes'] ?? null) ? (int) $live['delayMinutes'] : null,
                    status: $this->boardStatus($live['type'] ?? null),
                ),
            ];
        }

        // Expected time order (ISO strings sort chronologically); unknown times last.
        usort($entries, fn ($a, $b) => [$a['sort'] === null, $a['sort'] ?? $a['entry']->scheduledTime] <=> [$b['sort'] === null, $b['sort'] ?? $b['entry']->scheduledTime]);

        return array_column($entries, 'entry');
    }

    /**
     * Documented `live.type`: at-station, upcoming, departed, scheduled.
     * Also observed: not-started. Unknown values fall back to "expected".
     */
    private function boardStatus(mixed $type): BoardStatus
    {
        return match (is_string($type) ? strtolower($type) : null) {
            'at-station', 'at_station', 'arrived_at_station' => BoardStatus::AtStation,
            'departed' => BoardStatus::Departed,
            'arrived', 'terminated' => BoardStatus::Arrived,
            'cancelled', 'canceled' => BoardStatus::Cancelled,
            default => BoardStatus::Expected, // upcoming, scheduled, not-started
        };
    }

    /** @return list<array<string, mixed>> */
    private function route(array $data): array
    {
        $route = $data['route'] ?? null;

        if (! is_array($route) || $route === [] || ! Arr::isList($route)) {
            throw RailwayDataException::invalidResponse('missing route');
        }

        return array_values(array_filter($route, 'is_array'));
    }

    /**
     * Documented value: "running". Other values are mapped defensively; anything
     * unrecognised is derived from the per-station statuses.
     */
    private function runningStatus(mixed $status, array $route): RunningStatus
    {
        $normalized = is_string($status) ? strtolower(str_replace(['-', ' '], '_', $status)) : null;

        return match ($normalized) {
            'running' => RunningStatus::Running,
            'cancelled', 'canceled' => RunningStatus::Cancelled,
            'completed', 'arrived', 'reached', 'terminated' => RunningStatus::Completed,
            'scheduled', 'not_started', 'yet_to_start', 'upcoming' => RunningStatus::Scheduled,
            default => $this->statusFromRoute($route),
        };
    }

    private function statusFromRoute(array $route): RunningStatus
    {
        $departed = count(array_filter($route, fn ($s) => ($s['status'] ?? null) === 'departed'));

        return match (true) {
            $departed === 0 => RunningStatus::Scheduled,
            $departed >= count($route) => RunningStatus::Completed,
            default => RunningStatus::Running,
        };
    }

    /** "Active" only when RailRadar says the position is real-time. */
    private function gpsStatus(array $data, array $current): GpsStatus
    {
        if (array_key_exists('isActualPosition', $current)) {
            return $current['isActualPosition'] === true ? GpsStatus::Active : GpsStatus::Lost;
        }

        return ($data['trackingMode'] ?? null) === 'real-time' ? GpsStatus::Active : GpsStatus::Lost;
    }

    /** Coach composition string, e.g. "ENG-…-D4-PC-D3-…"; "PC" is the pantry car. */
    private function hasPantryCar(mixed $coachPosition): ?bool
    {
        if (! is_string($coachPosition) || trim($coachPosition) === '') {
            return null;
        }

        return in_array('PC', array_map('trim', explode('-', strtoupper($coachPosition))), true);
    }

    /** "2026-10-02T06:10:00+05:30" → "06:10" (station-local time, as RailRadar returns it). */
    private function hm(mixed $iso): ?string
    {
        return is_string($iso) && preg_match('/T(\d{2}:\d{2})/', $iso, $m) ? $m[1] : null;
    }

    /** Non-negative km with one decimal (two-step round avoids 39.649999… → 39.6). */
    private function km(float $value): float
    {
        return round(round(max(0, $value), 2), 1);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
