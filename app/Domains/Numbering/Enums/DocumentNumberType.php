<?php

namespace App\Domains\Numbering\Enums;

enum DocumentNumberType: string
{
    case Enquiry = 'enquiry';
    case LorryReceipt = 'lorry_receipt';
    case TripInvoice = 'trip_invoice';
}
