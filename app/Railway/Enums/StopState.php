<?php

namespace App\Railway\Enums;

enum StopState: string
{
    case Departed = 'departed';
    case Current = 'current';
    case Next = 'next';
    case Upcoming = 'upcoming';
}
