<?php

namespace App\Domains\Broker\Enums;

enum VoucherPaymentType: string
{
    case Advance = 'advance';
    case Final = 'final';
    case Partial = 'partial';

    public function label(): string
    {
        return match ($this) {
            self::Advance => 'Advance',
            self::Final => 'Final',
            self::Partial => 'Partial',
        };
    }
}
