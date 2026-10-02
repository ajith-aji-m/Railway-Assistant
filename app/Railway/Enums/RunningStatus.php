<?php

namespace App\Railway\Enums;

enum RunningStatus: string
{
    case Scheduled = 'scheduled';
    case Running = 'running';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
