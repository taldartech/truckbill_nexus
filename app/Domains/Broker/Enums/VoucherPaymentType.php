<?php

namespace App\Domains\Broker\Enums;

enum VoucherPaymentType: string
{
    case Advance = 'advance';
    case Final = 'final';
    case Partial = 'partial';
}
