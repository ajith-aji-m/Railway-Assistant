<?php

namespace App\Railway\Mock;

use Carbon\CarbonImmutable;

/**
 * Simulated clock for the mock live feed.
 *
 * With a configured anchor (e.g. "08:00") the time is today at the anchor plus
 * the minutes/seconds elapsed in the current real hour, so the demo always
 * shows trains in motion and loops every hour.
 */
final class MockClock
{
    public function __construct(private readonly ?string $anchor) {}

    public function now(): CarbonImmutable
    {
        $real = CarbonImmutable::now();

        if (! $this->anchor || ! preg_match('/^(\d{1,2}):(\d{2})$/', $this->anchor, $m)) {
            return $real;
        }

        return $real->setTime((int) $m[1], (int) $m[2])
            ->addSeconds($real->minute * 60 + $real->second);
    }
}
