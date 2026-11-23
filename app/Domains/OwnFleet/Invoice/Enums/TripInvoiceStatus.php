<?php

namespace App\Domains\OwnFleet\Invoice\Enums;

enum TripInvoiceStatus: string
{
    case Pending = 'pending';
    case Cancelled = 'cancelled';
}
