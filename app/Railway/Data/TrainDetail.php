<?php

namespace App\Railway\Data;

final readonly class TrainDetail
{
    /**
     * Nullable fields are unknown when the provider does not supply them.
     *
     * @param  list<StationRef>|null  $wifiStations
     * @param  list<array{0: float, 1: float}>  $route  Polyline [lat, lng] points.
     */
    public function __construct(
        public string $number,
        public string $name,
        public string $type,
        public StationRef $from,
        public StationRef $to,
        public string $departs,
        public string $arrives,
        public ?bool $hasPantry,
        public ?string $zone,
        public ?string $image,
        public ?array $wifiStations,
        public array $route,
        public LiveStatus $live,
    ) {}
}
