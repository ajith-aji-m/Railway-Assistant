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

    public function withLive(LiveStatus $live): self
    {
        return new self(
            number: $this->number,
            name: $this->name,
            type: $this->type,
            from: $this->from,
            to: $this->to,
            departs: $this->departs,
            arrives: $this->arrives,
            hasPantry: $this->hasPantry,
            zone: $this->zone,
            image: $this->image,
            wifiStations: $this->wifiStations,
            route: $this->route,
            live: $live,
        );
    }
}
