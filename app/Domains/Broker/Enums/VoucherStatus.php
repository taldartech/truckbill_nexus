<?php

namespace App\Domains\Broker\Enums;

enum VoucherStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Paid = 'paid';
}
