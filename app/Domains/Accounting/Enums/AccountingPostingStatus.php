<?php

namespace App\Domains\Accounting\Enums;

enum AccountingPostingStatus: string
{
    case Pending = 'pending';
    case Posted = 'posted';
    case Failed = 'failed';
    case Reversed = 'reversed';
}
