<?php

namespace App\Railway\Data;

use App\Railway\Enums\GpsStatus;
use App\Railway\Enums\RunningStatus;

final readonly class LiveStatus
{
    /**
     * @param  array{lat: float, lng: float}|null  $position  Reported (GPS active) or estimated (GPS lost) position.
     * @param  list<StopStatus>  $stops
     * @param  CurrentLocation|null  $currentLocation  Provider-reported location (null when not supplied, e.g. mock data).
     * @param  bool  $delayIsLive  False when delays/expected times are only the timetable
     *                             (e.g. journey not started) — never shown as "on time".
     * @param  PositionSnapshot|null  $snapshot  Real position fixes (RailRadar); null for mock data.
     */
    public function __construct(
        public RunningStatus $status,
        public GpsStatus $gps,
        public int $delayMinutes,
        public ?int $speedKmh,
        public ?array $position,
        public bool $atStation,
        public ?int $lastStopSequence,
        public ?int $nextStopSequence,
        public ?float $distanceFromLastKm,
        public ?float $distanceToNextKm,
        public string $journeyDate,
        public string $updatedAt,
        public array $stops,
        public ?CurrentLocation $currentLocation = null,
        public bool $delayIsLive = true,
        public ?PositionSnapshot $snapshot = null,
    ) {}

    /** Copy with a position snapshot (and optionally a different GPS status). */
    public function withSnapshot(PositionSnapshot $snapshot, ?GpsStatus $gps = null): self
    {
        return new self(
            status: $this->status,
            gps: $gps ?? $this->gps,
            delayMinutes: $this->delayMinutes,
            speedKmh: $this->speedKmh,
            position: $this->position,
            atStation: $this->atStation,
            lastStopSequence: $this->lastStopSequence,
            nextStopSequence: $this->nextStopSequence,
            distanceFromLastKm: $this->distanceFromLastKm,
            distanceToNextKm: $this->distanceToNextKm,
            journeyDate: $this->journeyDate,
            updatedAt: $this->updatedAt,
            stops: $this->stops,
            currentLocation: $this->currentLocation,
            delayIsLive: $this->delayIsLive,
            snapshot: $snapshot,
        );
    }
}
