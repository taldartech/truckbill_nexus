<?php

namespace App\Domains\Partload\Enums;

enum PartloadDeliveryPaymentMode: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Due = 'due';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Bank => 'Bank',
            self::Due => 'Due',
        };
    }
}
