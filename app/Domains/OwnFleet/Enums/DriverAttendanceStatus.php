<?php

namespace App\Domains\OwnFleet\Enums;

enum DriverAttendanceStatus: string
{
    case Present = 'PRESENT';
    case Absent = 'ABSENT';
    case NoWorkDay = 'NO_WORK_DAY';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Absent => 'Absent',
            self::NoWorkDay => 'No Work Day',
        };
    }
}
