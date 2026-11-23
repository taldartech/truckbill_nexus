<?php

namespace App\Domains\OwnFleet\Invoice\Enums;

enum TripInvoiceChargeType: string
{
    case Detention = 'detention';
    case Demurrage = 'demurrage';
    case Halting = 'halting';
    case LoadingSurcharge = 'loading_surcharge';
    case Other = 'other';
}
