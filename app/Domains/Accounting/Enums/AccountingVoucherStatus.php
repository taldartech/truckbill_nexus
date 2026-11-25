<?php

namespace App\Domains\Accounting\Enums;

enum AccountingVoucherStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';
}
