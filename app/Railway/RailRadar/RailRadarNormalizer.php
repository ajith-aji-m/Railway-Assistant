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
use App\Railway\Data\TrainSummary;
use App\Railway\Enums\BoardPhase;
use App\Railway\Enums\BoardStatus;
use App\Railway\Enums\BoardType;
use App\Railway\Enums\GpsStatus;
use App\Railway\Enums\RunningStatus;
use App\Railway\Enums\StopState;
use App\Railway\Exceptions\RailwayDataException;
use Carbon\CarbonImmutable;
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
        // Same convention as the station board: before the journey starts RailRadar
        // echoes the timetable (delay 0, actual = scheduled), which is not live data.
        $delayIsLive = $status !== RunningStatus::Scheduled;
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
                expectedArrival: $delayIsLive ? ($this->hm($stop['actualArrival'] ?? null) ?? $this->hm($stop['scheduledArrival'] ?? null)) : null,
                expectedDeparture: $delayIsLive ? ($this->hm($stop['actualDeparture'] ?? null) ?? $this->hm($stop['scheduledDeparture'] ?? null)) : null,
                platform: isset($stop['platform']) ? (string) $stop['platform'] : null,
                distanceKm: (int) round((float) ($stop['distance'] ?? 0)),
                delayMinutes: $delayIsLive && is_numeric($stop['delayArrival'] ?? $stop['delayDeparture'] ?? null) ? (int) ($stop['delayArrival'] ?? $stop['delayDeparture']) : null,
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
            delayIsLive: $delayIsLive,
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
    /** Search result from a full live status (exact train number). */
    public function trainSummary(TrainDetail $train): TrainSummary
    {
        return new TrainSummary(
            number: $train->number,
            name: $train->name,
            type: $train->type,
            from: $train->from,
            to: $train->to,
            departs: $train->departs !== '' ? $train->departs : null,
            arrives: $train->arrives !== '' ? $train->arrives : null,
            originPlatform: null,
            status: $train->live->status,
            delayMinutes: $train->live->delayMinutes,
            delayIsLive: $train->live->delayIsLive,
        );
    }

    /**
     * `/v1/lookup/search/trains` results (observed fields: number, name, type, source,
     * sourceName, dest, destName). No live status: it is loaded on Train Details.
     *
     * @return list<TrainSummary>
     */
    public function trainSearch(mixed $data, array $meta = []): array
    {
        if (! is_array($data) || ! Arr::isList($data)) {
            throw RailwayDataException::invalidResponse('train search data is not a list', $meta['traceId'] ?? null);
        }

        $results = [];
        foreach ($data as $item) {
            if (! is_array($item) || ! is_string($item['number'] ?? null) || ! preg_match('/^\d{5}$/', $item['number'])) {
                continue;
            }
            $ref = fn (mixed $code, mixed $name) => is_string($code) && $code !== ''
                ? new StationRef($code, is_string($name) && $name !== '' ? $name : $code)
                : new StationRef('—', '—');

            $results[] = new TrainSummary(
                number: $item['number'],
                name: is_string($item['name'] ?? null) ? $item['name'] : $item['number'],
                type: is_string($item['type'] ?? null) ? $item['type'] : '',
                from: $ref($item['source'] ?? null, $item['sourceName'] ?? null),
                to: $ref($item['dest'] ?? $item['destination'] ?? null, $item['destName'] ?? null),
                departs: null,
                arrives: null,
                originPlatform: null,
                status: null,
                delayMinutes: null,
                delayIsLive: false,
            );
        }

        return $results;
    }

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
    public function stationBoard(array $data, BoardType $type, array $meta = [], ?CarbonImmutable $now = null): array
    {
        $nowHm = ($now ?? CarbonImmutable::now())->format('H:i');
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
            $type = is_string($live['type'] ?? null) ? strtolower($live['type']) : null;
            $isLive = in_array($type, self::LIVE_BOARD_TYPES, true);
            $delay = is_numeric($live['delayMinutes'] ?? null) ? (int) $live['delayMinutes'] : null;

            // Live expected time; if RailRadar sent only a delay, derive it from the schedule.
            $expected = $this->hm($expectedIso) ?? ($isLive && $delay !== null ? $this->addMinutes(substr($scheduled, 0, 5), $delay) : null);
            $platform = $live['platform'] ?? $stop['platform'] ?? null; // live platform when reported, else timetable platform
            $source = is_string($train['source'] ?? null) ? $train['source'] : null;
            $destination = is_string($train['destination'] ?? null) ? $train['destination'] : null;
            $status = $this->boardStatus($type);

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
                    // Without live data the "expected" time is just the timetable, so it is not exposed.
                    expectedTime: $isLive ? $expected : null,
                    platform: $platform !== null && $platform !== '' ? (string) $platform : null,
                    delayMinutes: $isLive ? $delay : null,
                    status: $status,
                    isLive: $isLive,
                    phase: match ($status) {
                        BoardStatus::Departed, BoardStatus::Arrived => BoardPhase::Completed,
                        // Live "upcoming" / at the station: RailRadar is tracking the run.
                        BoardStatus::AtStation, BoardStatus::Expected, BoardStatus::Approaching => BoardPhase::Running,
                        BoardStatus::Cancelled => substr($scheduled, 0, 5) < $nowHm ? BoardPhase::Completed : BoardPhase::Upcoming,
                        default => BoardPhase::Upcoming, // RailRadar: not started
                    },
                ),
            ];
        }

        // Expected time order (ISO strings sort chronologically); unknown times last.
        usort($entries, fn ($a, $b) => [$a['sort'] === null, $a['sort'] ?? $a['entry']->scheduledTime] <=> [$b['sort'] === null, $b['sort'] ?? $b['entry']->scheduledTime]);

        return array_column($entries, 'entry');
    }

    /**
     * Today's calls at the station from its timetable (GET /v1/stations/{code}/trains),
     * for the part of the day the live board does not cover. No live data here: a past
     * call counts as completed per timetable, a future one as scheduled.
     *
     * @return list<BoardEntry>
     */
    public function stationTimetable(array $data, BoardType $type, CarbonImmutable $now, array $meta = []): array
    {
        $trains = $data['trains'] ?? null;

        if (! is_array($trains) || ! Arr::isList($trains)) {
            throw RailwayDataException::invalidResponse('missing trains list', $meta['traceId'] ?? null);
        }

        $arrivals = $type === BoardType::Arrivals;
        $today = $now->startOfDay();
        $nowHm = $now->format('H:i');
        $entries = [];

        foreach ($trains as $item) {
            $train = is_array($item['train'] ?? null) ? $item['train'] : null;
            $stop = is_array($item['stop'] ?? null) ? $item['stop'] : [];
            $scheduled = $arrivals ? ($stop['arrival'] ?? null) : ($stop['departure'] ?? null);

            if ($train === null || ! is_string($train['number'] ?? null) || ! is_string($scheduled) || ! preg_match('/^\d{2}:\d{2}/', $scheduled)) {
                continue; // not an arrival/departure for this tab
            }

            // The run calling here today started `day - 1` days ago; it must run on that weekday.
            $day = $arrivals ? ($stop['arrivalDay'] ?? null) : ($stop['departureDay'] ?? null);
            $startedOn = $today->subDays(max(0, (is_numeric($day) ? (int) $day : 1) - 1));
            $runDays = $train['runDays'] ?? null;
            if (is_array($runDays) && $runDays !== [] && ! in_array(strtolower($startedOn->format('D')), array_map('strtolower', $runDays), true)) {
                continue;
            }

            $time = substr($scheduled, 0, 5);
            $past = $time < $nowHm;
            $entries[] = new BoardEntry(
                trainNumber: $train['number'],
                trainName: (string) ($train['name'] ?? $train['number']),
                trainType: (string) ($train['type'] ?? ''),
                from: $this->endpoint($train['source'] ?? null),
                to: $this->endpoint($train['destination'] ?? null),
                scheduledTime: $time,
                expectedTime: null,
                platform: null,
                delayMinutes: null,
                status: $past ? ($arrivals ? BoardStatus::Arrived : BoardStatus::Departed) : BoardStatus::Scheduled,
                isLive: false,
                phase: $past ? BoardPhase::Completed : BoardPhase::Upcoming,
            );
        }

        return $entries;
    }

    /**
     * Full-day board: the timetable for the whole day, with the live board's entry
     * (status, delay, platform) replacing the timetable entry wherever RailRadar has one.
     *
     * @param  list<BoardEntry>  $timetable
     * @param  list<BoardEntry>  $live
     * @return list<BoardEntry> In scheduled-time order at this station.
     */
    public function fullDayBoard(array $timetable, array $live): array
    {
        $byNumber = [];
        foreach ($timetable as $entry) {
            $byNumber[$entry->trainNumber] ??= $entry;
        }
        foreach ($live as $entry) {
            $byNumber[$entry->trainNumber] = $entry; // live data wins
        }

        $entries = array_values($byNumber);
        usort($entries, fn (BoardEntry $a, BoardEntry $b) => [$a->scheduledTime, $a->trainNumber] <=> [$b->scheduledTime, $b->trainNumber]);

        return $entries;
    }

    /** Timetable origin/destination: {code, name} object or a bare code. */
    private function endpoint(mixed $value): StationRef
    {
        $code = is_array($value) ? ($value['code'] ?? null) : $value;
        $name = is_array($value) ? ($value['name'] ?? $code) : $value;

        return is_string($code) && $code !== '' ? new StationRef($code, is_string($name) ? $name : $code) : new StationRef('—', '—');
    }

    /** `live.type` values backed by live tracking (documented + observed). */
    private const LIVE_BOARD_TYPES = ['upcoming', 'at-station', 'at_station', 'departed', 'arrived'];

    /**
     * Documented `live.type`: at-station, upcoming, departed, scheduled.
     * Also observed: not-started. Unknown values are treated as timetable-only.
     */
    private function boardStatus(?string $type): BoardStatus
    {
        return match ($type) {
            'at-station', 'at_station' => BoardStatus::AtStation,
            'departed' => BoardStatus::Departed,
            'arrived', 'terminated' => BoardStatus::Arrived,
            'cancelled', 'canceled' => BoardStatus::Cancelled,
            'upcoming' => BoardStatus::Expected,
            default => BoardStatus::Scheduled, // scheduled, not-started, unknown
        };
    }

    /** "23:50" + 20 → "00:10" */
    private function addMinutes(string $hm, int $minutes): ?string
    {
        if (! preg_match('/^(\d{2}):(\d{2})$/', $hm, $m)) {
            return null;
        }
        $total = ((int) $m[1] * 60 + (int) $m[2] + $minutes) % 1440;
        $total = $total < 0 ? $total + 1440 : $total;

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
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
