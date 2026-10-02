<?php

namespace App\Railway\Enums;

enum GpsStatus: string
{
    case Active = 'active';
    case Lost = 'lost';
}
