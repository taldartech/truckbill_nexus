<?php

namespace App\Domains\Broker\Invoice\Enums;

enum BrokerInvoicePaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
}
