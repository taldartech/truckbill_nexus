<?php

namespace App\Domains\Broker\Enums;

enum BookBalanceEntryType: string
{
    case VoucherPayment = 'voucher_payment';
    case ManualAdjustment = 'manual_adjustment';
    case OpeningBalance = 'opening_balance';
}
