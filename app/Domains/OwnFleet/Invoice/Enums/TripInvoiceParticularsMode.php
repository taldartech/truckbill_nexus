<?php

namespace App\Domains\OwnFleet\Invoice\Enums;

enum TripInvoiceParticularsMode: string
{
    case SeparateLines = 'separate_lines';
    case CombinedFreight = 'combined_freight';
}
