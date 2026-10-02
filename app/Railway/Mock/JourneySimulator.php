<?php

namespace App\Railway\Mock;

use App\Models\Train;
use App\Models\TrainStop;
use App\Railway\Data\LiveStatus;
use App\Railway\Data\StationRef;
use App\Railway\Data\StopStatus;
use App\Railway\Enums\GpsStatus;
use App\Railway\Enums\RunningStatus;
use App\Railway\Enums\StopState;
use App\Railway\Support\Geo;
use Carbon\CarbonImmutable;

/**
 * Derives a train's live status (position, speed, stop progress) from its
 * timetable and the mock per-stop delays at a given moment.
 */
final class JourneySimulator
{
    /** Expects the train with `stops.station` and `liveStatus` loaded. */
    public function simulate(Train $train, CarbonImmutable $now): LiveStatus
    {
        $stops = $train->stops->values();
        $live = $train->liveStatus;
        $gps = GpsStatus::tryFrom($live?->gps_status ?? '') ?? GpsStatus::Active;

        if ($live?->status === 'cancelled') {
            $times = $this->expectedTimes($train, $now->startOfDay());

            return $this->result(RunningStatus::Cancelled, $gps, $train, $times, $now->startOfDay(), $now,
                states: array_fill(0, $stops->count(), StopState::Upcoming), delay: 0);
        }

        [$status, $base] = $this->pickJourney($train, $now);
        $times = $this->expectedTimes($train, $base);
        $last = $stops->count() - 1;

        if ($status !== RunningStatus::Running) {
            $state = $status === RunningStatus::Completed ? StopState::Departed : StopState::Upcoming;
            $delayIndex = $status === RunningStatus::Completed ? $last : 0;

            return $this->result($status, $gps, $train, $times, $base, $now,
                states: array_fill(0, $stops->count(), $state),
                delay: $live?->delayAt($stops[$delayIndex]->sequence) ?? 0);
        }

        // Running: find whether the train is standing at a stop or between two.
        foreach ($stops as $i => $stop) {
            [$arr, $dep] = $times[$i];

            if ($arr && $arr <= $now && (! $dep || $now < $dep)) {
                return $this->atStation($train, $gps, $times, $base, $now, $i);
            }

            if ($i < $last && $dep && $dep <= $now && $now < $times[$i + 1][0]) {
                return $this->between($train, $gps, $times, $base, $now, $i);
            }
        }

        // Before the first departure (journey window edge) — treat as at origin.
        return $this->atStation($train, $gps, $times, $base, $now, 0);
    }

    /**
     * Choose the journey (by start date) that is relevant now: one in progress,
     * otherwise today's upcoming run, otherwise today's completed run.
     *
     * @return array{0: RunningStatus, 1: CarbonImmutable}
     */
    private function pickJourney(Train $train, CarbonImmutable $now): array
    {
        $maxOffset = (int) $train->stops->max('day_offset');
        $today = $now->startOfDay();

        for ($daysAgo = $maxOffset; $daysAgo >= 0; $daysAgo--) {
            $base = $today->subDays($daysAgo);
            $times = $this->expectedTimes($train, $base);
            $start = $times[0][1];
            $end = $times[count($times) - 1][0];

            if ($start <= $now && $now < $end) {
                return [RunningStatus::Running, $base];
            }
        }

        $times = $this->expectedTimes($train, $today);

        return $times[0][1] > $now
            ? [RunningStatus::Scheduled, $today]
            : [RunningStatus::Completed, $today];
    }

    /**
     * Expected (scheduled + delay) arrival/departure per stop for a journey starting on $base.
     *
     * @return list<array{0: ?CarbonImmutable, 1: ?CarbonImmutable}>
     */
    public function expectedTimes(Train $train, CarbonImmutable $base): array
    {
        return $train->stops->values()->map(function (TrainStop $stop) use ($train, $base) {
            $delay = $train->liveStatus?->delayAt($stop->sequence) ?? 0;

            return [
                $this->at($base, $stop->scheduled_arrival, $stop->day_offset, $delay),
                $this->at($base, $stop->scheduled_departure, $stop->day_offset, $delay, after: $stop->scheduled_arrival),
            ];
        })->all();
    }

    public function at(CarbonImmutable $base, ?string $time, int $dayOffset, int $delay = 0, ?string $after = null): ?CarbonImmutable
    {
        if (! $time) {
            return null;
        }

        [$h, $m] = array_map('intval', explode(':', $time));
        $moment = $base->addDays($dayOffset)->setTime($h, $m);

        // Departure past midnight after a late-evening arrival.
        if ($after && $time < $after) {
            $moment = $moment->addDay();
        }

        return $moment->addMinutes($delay);
    }

    private function atStation(Train $train, GpsStatus $gps, array $times, CarbonImmutable $base, CarbonImmutable $now, int $index): LiveStatus
    {
        $stops = $train->stops->values();
        $station = $stops[$index]->station;
        $states = [];

        foreach ($stops as $i => $_) {
            $states[] = match (true) {
                $i < $index => StopState::Departed,
                $i === $index => StopState::Current,
                $i === $index + 1 => StopState::Next,
                default => StopState::Upcoming,
            };
        }

        $next = $stops[$index + 1] ?? null;

        return $this->result(RunningStatus::Running, $gps, $train, $times, $base, $now,
            states: $states,
            delay: $train->liveStatus?->delayAt($stops[$index]->sequence) ?? 0,
            speed: $gps === GpsStatus::Active ? 0 : null,
            position: ['lat' => $station->lat, 'lng' => $station->lng],
            atStation: true,
            lastIndex: $index,
            nextIndex: $next ? $index + 1 : null,
            fromLast: 0.0,
            toNext: $next ? (float) ($next->distance_km - $stops[$index]->distance_km) : null,
        );
    }

    private function between(Train $train, GpsStatus $gps, array $times, CarbonImmutable $base, CarbonImmutable $now, int $index): LiveStatus
    {
        $stops = $train->stops->values();
        $from = $stops[$index];
        $to = $stops[$index + 1];

        $departed = $times[$index][1];
        $arrives = $times[$index + 1][0];
        $segmentMinutes = max(1, $departed->diffInMinutes($arrives));
        $fraction = min(1, max(0, $departed->diffInSeconds($now) / ($segmentMinutes * 60)));
        $segmentKm = $to->distance_km - $from->distance_km;

        // Average segment speed with a gentle, deterministic variation.
        $average = $segmentKm / ($segmentMinutes / 60);
        $speed = (int) round($average * (0.92 + 0.08 * sin($now->minute / 3 + $index)));

        $states = [];
        foreach ($stops as $i => $_) {
            $states[] = match (true) {
                $i <= $index => StopState::Departed,
                $i === $index + 1 => StopState::Next,
                default => StopState::Upcoming,
            };
        }

        return $this->result(RunningStatus::Running, $gps, $train, $times, $base, $now,
            states: $states,
            delay: $train->liveStatus?->delayAt($to->sequence) ?? 0,
            speed: $gps === GpsStatus::Active ? $speed : null,
            position: Geo::lerp($from->station->lat, $from->station->lng, $to->station->lat, $to->station->lng, $fraction),
            atStation: false,
            lastIndex: $index,
            nextIndex: $index + 1,
            fromLast: round($segmentKm * $fraction, 1),
            toNext: round($segmentKm * (1 - $fraction), 1),
        );
    }

    /**
     * @param  list<StopState>  $states
     */
    private function result(
        RunningStatus $status,
        GpsStatus $gps,
        Train $train,
        array $times,
        CarbonImmutable $base,
        CarbonImmutable $now,
        array $states,
        int $delay,
        ?int $speed = null,
        ?array $position = null,
        bool $atStation = false,
        ?int $lastIndex = null,
        ?int $nextIndex = null,
        ?float $fromLast = null,
        ?float $toNext = null,
    ): LiveStatus {
        $stops = $train->stops->values();

        $stopStatuses = $stops->map(fn (TrainStop $stop, int $i) => new StopStatus(
            sequence: $stop->sequence,
            station: new StationRef($stop->station->code, $stop->station->name),
            lat: $stop->station->lat,
            lng: $stop->station->lng,
            scheduledArrival: $this->hm($stop->scheduled_arrival),
            scheduledDeparture: $this->hm($stop->scheduled_departure),
            expectedArrival: $times[$i][0]?->format('H:i'),
            expectedDeparture: $times[$i][1]?->format('H:i'),
            platform: $stop->platform,
            distanceKm: $stop->distance_km,
            delayMinutes: $train->liveStatus?->delayAt($stop->sequence) ?? 0,
            state: $states[$i],
        ))->all();

        return new LiveStatus(
            status: $status,
            gps: $gps,
            delayMinutes: $delay,
            speedKmh: $speed,
            position: $position,
            atStation: $atStation,
            lastStopSequence: $lastIndex !== null ? $stops[$lastIndex]->sequence : null,
            nextStopSequence: $nextIndex !== null ? $stops[$nextIndex]->sequence : null,
            distanceFromLastKm: $fromLast,
            distanceToNextKm: $toNext,
            journeyDate: $base->toDateString(),
            updatedAt: $now->toIso8601String(),
            stops: $stopStatuses,
        );
    }

    private function hm(?string $time): ?string
    {
        return $time ? substr($time, 0, 5) : null;
    }
}
