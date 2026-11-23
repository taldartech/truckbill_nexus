<?php

namespace App\Domains\Master\Vehicle\Enums;

enum VehicleOwnership: string
{
    case Own = 'own';
    case Market = 'market';

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Own',
            self::Market => 'Market',
        };
    }
}
