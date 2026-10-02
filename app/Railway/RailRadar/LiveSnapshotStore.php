<?php

namespace App\Railway\RailRadar;

use App\Railway\Data\LiveStatus;
use App\Railway\Data\PositionSnapshot;
use App\Railway\Enums\GpsStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Remembers the latest two *real* RailRadar position fixes per train in the
 * application cache (no database table, no per-frame writes). It is only
 * updated when RailRadar reports a newer `lastUpdatedAt`, so cached re-reads of
 * the same response leave it unchanged. Missing coordinates never erase a fix.
 */
class LiveSnapshotStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $ttlSeconds,
        private readonly int $staleAfterSeconds,
    ) {}

    public static function fromConfig(Repository $cache): self
    {
        return new self(
            $cache,
            (int) config('services.railradar.snapshot_ttl_seconds', 21600),
            (int) config('services.railradar.stale_after_seconds', 600),
        );
    }

    public function apply(string $trainNumber, LiveStatus $live, ?string $trackingMode, CarbonImmutable $now): LiveStatus
    {
        $key = "railradar:snapshot:{$trainNumber}";
        $stored = $this->cache->get($key);
        if (! is_array($stored) || ($stored['journeyDate'] ?? null) !== $live->journeyDate) {
            $stored = null; // different run → start fresh
        }

        // A real fix: GPS active (real-time tracking) with coordinates and a timestamp.
        $fix = $live->gps === GpsStatus::Active && $live->position !== null && $live->updatedAt !== ''
            ? ['lat' => (float) $live->position['lat'], 'lng' => (float) $live->position['lng'], 'at' => $live->updatedAt]
            : null;

        $current = $stored['current'] ?? null;
        $previous = $stored['previous'] ?? null;

        if ($fix !== null) {
            $isNewer = $current === null || $this->time($fix['at']) > $this->time($current['at']);
            $isSame = $current !== null && $fix['at'] === $current['at'];

            if ($isNewer) {
                $previous = $current; // the old real fix becomes the interpolation start
                $current = $fix;
                $this->cache->put($key, ['journeyDate' => $live->journeyDate, 'current' => $current, 'previous' => $previous], $this->ttlSeconds);
            } elseif (! $isSame) {
                // Out-of-order/older response: show it, but don't animate or overwrite.
                return $live->withSnapshot(new PositionSnapshot(true, $trackingMode, null, $fix, $this->isStale($fix, $now)));
            }
        }

        $authoritative = $fix !== null;
        $stale = $current !== null && $this->isStale($current, $now);

        $snapshot = new PositionSnapshot(
            authoritative: $authoritative,
            trackingMode: $trackingMode,
            previous: $authoritative ? $previous : null,
            lastKnown: $current,
            stale: $stale,
        );

        // An old fix is not a live GPS position: use the existing GPS-lost treatment.
        return $live->withSnapshot($snapshot, $stale ? GpsStatus::Lost : null);
    }

    private function isStale(array $fix, CarbonImmutable $now): bool
    {
        return $this->staleAfterSeconds > 0 && $now->getTimestamp() - $this->time($fix['at']) > $this->staleAfterSeconds;
    }

    private function time(string $iso): int
    {
        return CarbonImmutable::parse($iso)->getTimestamp();
    }
}
