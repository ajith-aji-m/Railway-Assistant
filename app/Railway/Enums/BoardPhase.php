<?php

namespace App\Railway\Enums;

/** Where a train is in today's activity at the station shown on the board. */
enum BoardPhase: string
{
    case Completed = 'completed'; // already arrived at / departed from this station
    case Running = 'running';     // live-tracked and on its way to, or at, this station
    case Upcoming = 'upcoming';   // not started yet (timetable only)
}
