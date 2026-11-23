<?php

namespace App\Domains\OwnFleet\Enums;

enum TripStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    public function allowsSoftDelete(): bool
    {
        return $this === self::Pending;
    }

    public function allowsCancel(): bool
    {
        return $this === self::Pending || $this === self::InProgress;
    }
}
