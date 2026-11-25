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

    public function label(): string
    {
        return match ($this) {
            self::Contra => 'Contra',
            self::Payment => 'Payment',
            self::Receipt => 'Receipt',
            self::Journal => 'Journal',
            self::Sales => 'Sales',
            self::Purchase => 'Purchase',
            self::CreditNote => 'Credit Note',
            self::DebitNote => 'Debit Note',
            self::Opening => 'Opening Balance',
        };
    }
}
