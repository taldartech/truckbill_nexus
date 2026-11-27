<?php

namespace App\Domains\Broker\Enums;

enum VoucherPaymentMode: string
{
    case Bank = 'bank';
    case Cash = 'cash';
    case PaidToPump = 'paid_to_pump';
    case AdjustInAdvance = 'adjust_in_advance';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Bank',
            self::Cash => 'Cash',
            self::PaidToPump => 'Paid to Pump',
            self::AdjustInAdvance => 'Adjust in Advance',
        };
    }
}
