<?php

namespace App\Railway\Data;

use App\Railway\Enums\BoardPhase;
use App\Railway\Enums\BoardStatus;

final readonly class BoardEntry
{
    // expectedTime / delayMinutes are null when the provider does not report them.
    // isLive: true only when expectedTime/delay come from live data; false means
    // timetable-only (show as "Scheduled", never as "Expected").
    public function __construct(
        public string $trainNumber,
        public string $trainName,
        public string $trainType,
        public StationRef $from,
        public StationRef $to,
        public string $scheduledTime,
        public ?string $expectedTime,
        public ?string $platform,
        public ?int $delayMinutes,
        public BoardStatus $status,
        public bool $isLive = true,
        // Completed / running / upcoming at this station today (groups the full-day board).
        public BoardPhase $phase = BoardPhase::Upcoming,
    ) {}
}
