<?php

namespace App\Domains\Broker\Enums;

enum VoucherStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In Progress',
            self::Paid => 'Paid',
        };
    }
}
