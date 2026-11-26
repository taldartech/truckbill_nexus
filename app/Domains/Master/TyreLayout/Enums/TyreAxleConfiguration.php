<?php

namespace App\Domains\Master\TyreLayout\Enums;

enum TyreAxleConfiguration: string
{
    case Single = 'single';
    case Dual = 'dual';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Single',
            self::Dual => 'Dual',
        };
    }
}
