<?php

namespace App\Domains\Partload\Enums;

enum PartloadInwardDisposition: string
{
    case Delivery = 'delivery';
    case Forward = 'forward';

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'Delivery',
            self::Forward => 'Forward',
        };
    }
}
