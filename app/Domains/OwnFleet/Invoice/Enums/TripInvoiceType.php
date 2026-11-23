<?php

namespace App\Domains\OwnFleet\Invoice\Enums;

enum TripInvoiceType: string
{
    case Individual = 'individual';
    case Consolidated = 'consolidated';
}
