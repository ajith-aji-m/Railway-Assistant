<?php

namespace App\Railway\Enums;

enum BoardStatus: string
{
    case Expected = 'expected';
    case Approaching = 'approaching';
    case AtStation = 'at_station';
    case Arrived = 'arrived';
    case Departed = 'departed';
    case Cancelled = 'cancelled';
}
