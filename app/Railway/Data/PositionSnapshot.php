<?php

namespace App\Railway\Data;

/**
 * Real provider position fixes for one journey (RailRadar coordinates are authoritative).
 *
 * - `authoritative`: the response's `position` is a real fix (not estimated/missing).
 * - `previous`: the real fix before the current one — lets the client animate between
 *   two real points. Null after the first fix of a journey.
 * - `lastKnown`: the most recent real fix (equals the current one when authoritative;
 *   otherwise the last one seen, so a response without coordinates never erases it).
 * - `stale`: the latest real fix is older than the configured threshold.
 *
 * Each fix is `{lat, lng, at}` where `at` is the provider's own lastUpdatedAt.
 */
final readonly class PositionSnapshot
{
    /**
     * @param  array{lat: float, lng: float, at: string}|null  $previous
     * @param  array{lat: float, lng: float, at: string}|null  $lastKnown
     */
    public function __construct(
        public bool $authoritative,
        public ?string $trackingMode,
        public ?array $previous,
        public ?array $lastKnown,
        public bool $stale,
    ) {}
}
