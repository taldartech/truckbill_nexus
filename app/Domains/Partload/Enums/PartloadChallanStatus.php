<?php

namespace App\Domains\Partload\Enums;

enum PartloadChallanStatus: string
{
    case Draft = 'draft';
    case Dispatched = 'dispatched';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Dispatched => 'Dispatched',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }
}
