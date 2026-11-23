<?php

namespace App\Domains\Numbering\Enums;

enum DocumentNumberType: string
{
    case Enquiry = 'enquiry';
    case LorryReceipt = 'lorry_receipt';
    case TripInvoice = 'trip_invoice';
    case LorryHire = 'lorry_hire';
    case BrokerVoucher = 'broker_voucher';
    case BrokerInvoice = 'broker_invoice';
    case LinkedBiltyGroup = 'linked_bilty_group';
}
