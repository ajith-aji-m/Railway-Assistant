<?php

namespace App\Railway\Mock;

use App\Models\Station;
use App\Models\Train;
use App\Models\TrainStop;
use App\Railway\Contracts\RailwayProvider;
use App\Railway\Data\BoardEntry;
use App\Railway\Data\JourneyOption;
use App\Railway\Data\LiveStatus;
use App\Railway\Data\StationDetail;
use App\Railway\Data\StationRef;
use App\Railway\Data\StationSummary;
use App\Railway\Data\TrainDetail;
use App\Railway\Data\TrainSummary;
use App\Railway\Enums\BoardPhase;
use App\Railway\Enums\BoardStatus;
use App\Railway\Enums\BoardType;
use App\Railway\Enums\RunningStatus;
use App\Railway\Support\LocalStationDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Railway data backed by the seeded SQLite mock network
 * (database/data/*.json) and a simulated live feed.
 */
final class MockRailwayProvider implements RailwayProvider
{
    /** Minutes before arrival when a train counts as "approaching". */
    private const APPROACH_WINDOW = 15;

    private const DEFAULT_TRAIN_IMAGE = '/images/trains/locomotive.jpg';

    public function __construct(
        private readonly MockClock $clock,
        private readonly JourneySimulator $simulator,
        private readonly LocalStationDirectory $directory,
    ) {}

    public function now(): CarbonImmutable
    {
        return $this->clock->now();
    }

    public function nearbyStations(float $lat, float $lng, float $radiusKm, int $limit): array
    {
        return $this->directory->nearby($lat, $lng, $radiusKm, $limit);
    }

    public function searchStations(string $query, int $limit = 10): array
    {
        $query = trim($query);

        return Station::query()
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q
                ->where('code', 'like', $query)
                ->orWhere('name', 'like', "%{$query}%")
                ->orWhere('city', 'like', "%{$query}%")
                ->orWhere('aliases', 'like', "%{$query}%")) // alternate spellings / former codes
            ->orderByRaw('code = ? desc', [strtoupper($query)])
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Station $s) => $this->summary($s))
            ->all();
    }

    public function popularStations(array $codes): array
    {
        return array_values(array_filter(array_map(fn (string $code) => $this->station($code), $codes)));
    }

    public function station(string $code): ?StationDetail
    {
        $station = Station::query()->where('code', strtoupper($code))->first();

        return $station ? new StationDetail(
            code: $station->code,
            name: $station->name,
            fullName: $station->full_name ?? $station->name,
            city: $station->city,
            state: $station->state,
            lat: $station->lat,
            lng: $station->lng,
            platforms: $station->platforms,
            image: $station->image_path,
            facilities: $station->facilities ?? [],
        ) : null;
    }

    public function stationBoard(string $code, BoardType $type): array
    {
        $now = $this->now();
        $column = $type === BoardType::Arrivals ? 'scheduled_arrival' : 'scheduled_departure';

        return TrainStop::query()
            ->whereHas('station', fn (Builder $q) => $q->where('code', strtoupper($code)))
            ->whereNotNull($column)
            ->with(['train.origin', 'train.destination', 'train.liveStatus', 'train.stops'])
            ->get()
            ->map(fn (TrainStop $stop) => $this->boardEntry($stop, $type, $now))
            ->sortBy('scheduledTime')
            ->values()
            ->all();
    }

    public function searchTrains(string $query, int $limit = 10): array
    {
        $query = trim($query);
        $now = $this->now();

        return $this->trainQuery()
            ->where(fn (Builder $q) => $q
                ->where('number', 'like', "{$query}%")
                ->orWhere('name', 'like', "%{$query}%")
                ->orWhereHas('stops.station', fn (Builder $s) => $s
                    ->where('code', 'like', $query)
                    ->orWhere('name', 'like', "%{$query}%")))
            ->orderBy('number')
            ->limit($limit)
            ->get()
            ->map(fn (Train $train) => $this->trainSummary($train, $now))
            ->all();
    }

    public function train(string $number): ?TrainDetail
    {
        $train = $this->trainQuery()->where('number', $number)->first();

        if (! $train) {
            return null;
        }

        $stations = $train->stops->pluck('station');

        return new TrainDetail(
            number: $train->number,
            name: $train->name,
            type: $train->type,
            from: $this->ref($train->origin),
            to: $this->ref($train->destination),
            departs: substr($train->stops->first()->scheduled_departure, 0, 5),
            arrives: substr($train->stops->last()->scheduled_arrival, 0, 5),
            hasPantry: $train->has_pantry,
            zone: $train->zone,
            image: $train->image_path ?? self::DEFAULT_TRAIN_IMAGE,
            wifiStations: $stations->filter(fn (Station $s) => in_array('wifi', $s->facilities ?? [], true))
                ->map(fn (Station $s) => $this->ref($s))->values()->all(),
            route: $train->route_geometry ?? $stations->map(fn (Station $s) => [$s->lat, $s->lng])->all(),
            live: $this->simulator->simulate($train, $this->now()),
        );
    }

    public function liveStatus(string $number): ?LiveStatus
    {
        $train = $this->trainQuery()->where('number', $number)->first();

        return $train ? $this->simulator->simulate($train, $this->now()) : null;
    }

    public function journeys(string $from, string $to): array
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        if ($from === $to) {
            return [];
        }

        $now = $this->now();

        return TrainStop::query()
            ->whereHas('station', fn (Builder $q) => $q->where('code', $from))
            ->whereNotNull('scheduled_departure')
            ->with(['station', 'train.origin', 'train.destination', 'train.liveStatus', 'train.stops.station'])
            ->get()
            ->map(function (TrainStop $boarding) use ($to, $now): ?JourneyOption {
                // The To station must come after the From station on the same run.
                $alighting = $boarding->train->stops->first(fn (TrainStop $s) => $s->station->code === $to
                    && $s->sequence > $boarding->sequence
                    && $s->scheduled_arrival !== null);

                return $alighting ? $this->journeyOption($boarding, $alighting, $now) : null;
            })
            ->filter()
            ->sortBy(fn (JourneyOption $j) => $j->departure->scheduledTime)
            ->values()
            ->all();
    }

    private function journeyOption(TrainStop $boarding, TrainStop $alighting, CarbonImmutable $now): JourneyOption
    {
        $train = $boarding->train;
        $cancelled = $train->liveStatus?->status === 'cancelled';
        // Same run as the departure shown on the board (started `day_offset` days before today).
        $base = $now->startOfDay()->subDays($boarding->day_offset);
        $delay = $train->liveStatus?->delayAt($alighting->sequence) ?? 0;

        return new JourneyOption(
            departure: $this->boardEntry($boarding, BoardType::Departures, $now),
            boarding: $this->ref($boarding->station),
            alighting: $this->ref($alighting->station),
            arrives: substr($alighting->scheduled_arrival, 0, 5),
            expectedArrival: $cancelled ? null : $this->simulator->at($base, $alighting->scheduled_arrival, $alighting->day_offset, $delay)?->format('H:i'),
            arrivalDayOffset: $alighting->day_offset - $boarding->day_offset,
        );
    }

    private function trainQuery(): Builder
    {
        return Train::query()->with(['origin', 'destination', 'liveStatus', 'stops.station']);
    }

    private function boardEntry(TrainStop $stop, BoardType $type, CarbonImmutable $now): BoardEntry
    {
        $train = $stop->train;
        $delay = $train->liveStatus?->delayAt($stop->sequence) ?? 0;

        // The journey that serves this station today started `day_offset` days ago.
        $base = $now->startOfDay()->subDays($stop->day_offset);
        $arrival = $this->simulator->at($base, $stop->scheduled_arrival, $stop->day_offset, $delay);
        $departure = $this->simulator->at($base, $stop->scheduled_departure, $stop->day_offset, $delay, after: $stop->scheduled_arrival);

        $scheduled = $type === BoardType::Arrivals ? $stop->scheduled_arrival : $stop->scheduled_departure;
        $expected = $type === BoardType::Arrivals ? $arrival : $departure;

        $status = match (true) {
            $train->liveStatus?->status === 'cancelled' => BoardStatus::Cancelled,
            $departure && $departure <= $now => BoardStatus::Departed,
            $arrival && $arrival <= $now => $departure ? BoardStatus::AtStation : BoardStatus::Arrived,
            $type === BoardType::Arrivals && $arrival->subMinutes(self::APPROACH_WINDOW) <= $now => BoardStatus::Approaching,
            default => BoardStatus::Expected,
        };

        return new BoardEntry(
            trainNumber: $train->number,
            trainName: $train->name,
            trainType: $train->type,
            from: $this->ref($train->origin),
            to: $this->ref($train->destination),
            scheduledTime: substr($scheduled, 0, 5),
            expectedTime: $expected->format('H:i'),
            platform: $stop->platform,
            delayMinutes: $delay,
            status: $status,
            phase: match ($status) {
                BoardStatus::Departed, BoardStatus::Arrived => BoardPhase::Completed,
                BoardStatus::AtStation, BoardStatus::Approaching => BoardPhase::Running,
                BoardStatus::Cancelled => $expected <= $now ? BoardPhase::Completed : BoardPhase::Upcoming,
                // Expected here: running once the journey has started, otherwise upcoming.
                default => $this->simulator->simulate($train, $now)->status === RunningStatus::Running ? BoardPhase::Running : BoardPhase::Upcoming,
            },
        );
    }

    private function trainSummary(Train $train, CarbonImmutable $now): TrainSummary
    {
        $live = $this->simulator->simulate($train, $now);

        return new TrainSummary(
            number: $train->number,
            name: $train->name,
            type: $train->type,
            from: $this->ref($train->origin),
            to: $this->ref($train->destination),
            departs: substr($train->stops->first()->scheduled_departure, 0, 5),
            arrives: substr($train->stops->last()->scheduled_arrival, 0, 5),
            originPlatform: $train->stops->first()->platform,
            status: $live->status,
            delayMinutes: $live->delayMinutes,
        );
    }

    private function summary(Station $station, ?float $distanceKm = null): StationSummary
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

    private function ref(Station $station): StationRef
    {
        return new StationRef($station->code, $station->name);
    }
}
