<?php

namespace App\Domains\CustomerInvoice\Enums;

enum CustomerInvoiceSource: string
{
    case OwnFleet = 'own_fleet';
    case Broker = 'broker';
    case Partload = 'partload';

    public function label(): string
    {
        return match ($this) {
            self::OwnFleet => 'Own Fleet',
            self::Broker => 'Broker',
            self::Partload => 'Partload',
        };
    }
}
