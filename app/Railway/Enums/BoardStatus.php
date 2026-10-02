<?php

namespace App\Railway\Enums;

enum BoardStatus: string
{
    case Scheduled = 'scheduled'; // no live data yet (e.g. journey not started)
    case Expected = 'expected';
    case Approaching = 'approaching';
    case AtStation = 'at_station';
    case Arrived = 'arrived';
    case Departed = 'departed';
    case Cancelled = 'cancelled';
}
