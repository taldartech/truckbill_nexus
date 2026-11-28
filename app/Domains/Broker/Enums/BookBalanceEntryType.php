<?php

namespace App\Domains\Broker\Enums;

enum BookBalanceEntryType: string
{
    case AdvanceAdjustment = 'advance_adjustment';
    case ExcessPaymentAdjustment = 'excess_payment_adjustment';
    case LorryHireCancellationSettlement = 'hire_cancel_settlement';
    case AuthorizedBalanceTransfer = 'authorized_balance_transfer';
    case ReversalCompensation = 'reversal_compensation';
    case AdjustInAdvanceVoucher = 'adjust_in_advance_voucher';
    case DirectAdvance = 'direct_advance';
    case BalanceRecovery = 'balance_recovery';
    case BalanceAdjustment = 'balance_adjustment';
    case OpeningBalance = 'opening_balance';

    public function label(): string
    {
        return match ($this) {
            self::DirectAdvance => 'Advance Payment',
            self::BalanceRecovery => 'Refund / Recovery',
            self::BalanceAdjustment => 'Balance Adjustment',
            self::OpeningBalance => 'Opening Balance',
            self::AdjustInAdvanceVoucher => 'Adjust in Advance',
            self::LorryHireCancellationSettlement => 'Hire Cancellation Settlement',
            self::ExcessPaymentAdjustment => 'Excess Payment Adjustment',
            self::AdvanceAdjustment => 'Advance Adjustment',
            self::AuthorizedBalanceTransfer => 'Authorized Balance Transfer',
            self::ReversalCompensation => 'Reversal Compensation',
        };
    }

    public function isManualBookBalanceTransaction(): bool
    {
        return in_array($this, [
            self::DirectAdvance,
            self::BalanceRecovery,
            self::BalanceAdjustment,
            self::OpeningBalance,
        ], true);
    }
}
