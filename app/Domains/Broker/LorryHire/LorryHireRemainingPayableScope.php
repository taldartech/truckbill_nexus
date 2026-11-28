<?php

namespace App\Domains\Broker\LorryHire;

use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Models\LorryHire;
use Illuminate\Database\Eloquent\Builder;

/**
 * SQL scope: remaining payable = GREATEST(0, outstanding_amount − reserved vouchers).
 *
 * {@see LorryHireCalculationService::summarize()} is authoritative in PHP; this mirrors
 * persisted `outstanding_amount` (net − paid + book deltas) minus Pending/In Progress vouchers.
 */
final class LorryHireRemainingPayableScope
{
    /**
     * @param  Builder<LorryHire>  $query
     */
    public static function applyPositiveRemaining(Builder $query): void
    {
        $query->where('lorry_hires.status', '!=', LorryHireStatus::Cancelled->value)
            ->whereRaw(
                'GREATEST(0, CAST(lorry_hires.outstanding_amount AS DECIMAL(18,2)) - COALESCE((
                    SELECT SUM(CAST(tv.amount AS DECIMAL(18,2)))
                    FROM transporter_vouchers AS tv
                    WHERE tv.lorry_hire_id = lorry_hires.id
                    AND tv.deleted_at IS NULL
                    AND tv.status IN (?, ?)
                ), 0)) > 0',
                [
                    VoucherStatus::Pending->value,
                    VoucherStatus::InProgress->value,
                ],
            );
    }
}
