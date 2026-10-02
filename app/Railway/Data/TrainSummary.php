<?php

namespace App\Railway\Data;

use App\Railway\Enums\RunningStatus;

final readonly class TrainSummary
{
    /**
     * Null = unknown (e.g. a lookup result without live status). `delayIsLive` is false
     * when the delay is only the timetable (not tracked yet): never shown as "On time".
     */
    public function __construct(
        public string $number,
        public string $name,
        public string $type,
        public StationRef $from,
        public StationRef $to,
        public ?string $departs,
        public ?string $arrives,
        public ?string $originPlatform,
        public ?RunningStatus $status,
        public ?int $delayMinutes,
        public bool $delayIsLive = true,
    ) {}
}
