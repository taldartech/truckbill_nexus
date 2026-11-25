<?php

namespace App\Domains\Partload\Enums;

enum PartloadMainChallanStatus: string
{
    case Draft = 'draft';
    case Dispatched = 'dispatched';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Dispatched => 'Dispatched',
            self::Cancelled => 'Cancelled',
        };
    }
}
