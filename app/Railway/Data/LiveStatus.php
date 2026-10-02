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
    ) {}
}
