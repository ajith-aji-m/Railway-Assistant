<?php

namespace App\Railway\Data;

/**
 * Where the provider reports the train to be: the last station it passed or is at
 * (which may be a non-halting station) and how far it has travelled beyond it.
 */
final readonly class CurrentLocation
{
    public function __construct(
        public StationRef $station,
        public bool $isHalt,
        public ?float $distanceFromStationKm,
    ) {}
}
