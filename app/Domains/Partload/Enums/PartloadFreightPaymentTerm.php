<?php

namespace App\Domains\Partload\Enums;

enum PartloadFreightPaymentTerm: string
{
    case Paid = 'paid';
    case ToPay = 'to_pay';
    case ToBeBilled = 'tbb';
    case Foc = 'foc';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::ToPay => 'To Pay',
            self::ToBeBilled => 'To Be Billed',
            self::Foc => 'FOC',
        };
    }
}
