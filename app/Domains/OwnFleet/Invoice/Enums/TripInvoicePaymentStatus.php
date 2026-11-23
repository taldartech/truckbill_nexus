<?php

namespace App\Domains\OwnFleet\Invoice\Enums;

enum TripInvoicePaymentStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Paid = 'paid';
}
