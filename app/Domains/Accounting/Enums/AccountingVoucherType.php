<?php

namespace App\Domains\Accounting\Enums;

enum AccountingVoucherType: string
{
    case Contra = 'contra';
    case Payment = 'payment';
    case Receipt = 'receipt';
    case Journal = 'journal';
    case Sales = 'sales';
    case Purchase = 'purchase';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
    case Opening = 'opening';
}
