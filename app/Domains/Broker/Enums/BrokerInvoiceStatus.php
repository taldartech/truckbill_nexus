<?php

namespace App\Domains\Broker\Enums;

enum BrokerInvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Cancelled = 'cancelled';
    case Paid = 'paid';
}
