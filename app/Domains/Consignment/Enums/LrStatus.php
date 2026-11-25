<?php

namespace App\Domains\Consignment\Enums;

enum LrStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Delivered = 'delivered';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In Progress',
            self::Delivered => 'Delivered',
            self::Invoiced => 'Invoiced',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Statuses that still allow operational movement / hire / trip work.
     *
     * @return list<self>
     */
    public static function operationalOpen(): array
    {
        return [self::Pending, self::InProgress];
    }
}
