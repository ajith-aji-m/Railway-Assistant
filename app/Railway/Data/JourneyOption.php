<?php

namespace App\Railway\Data;

/**
 * A train that calls at the From station and, later on the same run, at the To
 * station (route order verified from timetable stop sequences).
 */
final readonly class JourneyOption
{
    public function __construct(
        // The train's call at the From station today: departure time, live status,
        // delay, platform and Completed / Running / Upcoming phase (as on the board).
        public BoardEntry $departure,
        public StationRef $boarding,
        public StationRef $alighting,
        // Scheduled arrival at the To station ("HH:MM"); null when not in the timetable.
        public ?string $arrives,
        // Expected arrival at the To station, only when backed by live data.
        public ?string $expectedArrival,
        // Days between departing From and arriving at To (0 = same day).
        public int $arrivalDayOffset = 0,
    ) {}
}
