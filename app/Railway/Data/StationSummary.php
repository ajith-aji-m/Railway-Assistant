<?php

namespace App\Railway\Data;

final readonly class StationSummary
{
    // city / state / lat / lng are null when the provider does not supply them.
    public function __construct(
        public string $code,
        public string $name,
        public ?string $city,
        public ?string $state,
        public ?float $lat,
        public ?float $lng,
        public ?float $distanceKm = null,
    ) {}
}
