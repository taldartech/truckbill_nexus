<?php

namespace App\Domains\Broker\Enums;

enum BookBalanceEntryType: string
{
    case AdvanceAdjustment = 'advance_adjustment';
    case ExcessPaymentAdjustment = 'excess_payment_adjustment';
    case LorryHireCancellationSettlement = 'lorry_hire_cancellation_settlement';
    case AuthorizedBalanceTransfer = 'authorized_balance_transfer';
    case ReversalCompensation = 'reversal_compensation';
    case AdjustInAdvanceVoucher = 'adjust_in_advance_voucher';
}
