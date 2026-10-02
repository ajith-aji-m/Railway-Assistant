<?php

namespace App\Railway\Data;

use App\Railway\Enums\BoardStatus;

final readonly class BoardEntry
{
    // expectedTime / delayMinutes are null when the provider does not report them.
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
    ) {}
}
