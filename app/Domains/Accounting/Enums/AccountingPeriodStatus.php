<?php

namespace App\Domains\Accounting\Enums;

enum AccountingPeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
