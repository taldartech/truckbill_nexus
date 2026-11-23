<?php

namespace App\Domains\Crm\Enums;

enum InterestedProduct: string
{
    case Broker = 'broker';
    case OwnFleet = 'ownfleet';
    case Partload = 'partload';
    case Combined = 'combined';
    case Undecided = 'undecided';
}
