<?php

namespace App\Domains\OwnFleet\Enums;

enum TripTimingPerformance: string
{
    case Early = 'early';
    case OnTime = 'on_time';
    case Delayed = 'delayed';
    case NotApplicable = 'not_applicable';
}
