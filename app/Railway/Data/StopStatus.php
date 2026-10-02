<?php

namespace App\Railway\Data;

use App\Railway\Enums\StopState;

final readonly class StopStatus
{
    public function __construct(
        public int $sequence,
        public StationRef $station,
        public float $lat,
        public float $lng,
        public ?string $scheduledArrival,
        public ?string $scheduledDeparture,
        public ?string $expectedArrival,
        public ?string $expectedDeparture,
        public ?string $platform,
        public int $distanceKm,
        public ?int $delayMinutes, // null when the provider does not report it
        public StopState $state,
    ) {}
}
