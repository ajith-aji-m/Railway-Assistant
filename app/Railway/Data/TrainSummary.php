<?php

namespace App\Railway\Data;

use App\Railway\Enums\RunningStatus;

final readonly class TrainSummary
{
    public function __construct(
        public string $number,
        public string $name,
        public string $type,
        public StationRef $from,
        public StationRef $to,
        public string $departs,
        public string $arrives,
        public ?string $originPlatform,
        public RunningStatus $status,
        public int $delayMinutes,
    ) {}
}
