<?php

namespace App\Domains\Broker\LorryHire;

use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Consignment\Dependency\LrDependencyBlocker;
use App\Domains\Consignment\Dependency\LrDependencyGuard;
use App\Models\LorryHire;
use App\Models\LorryReceipt;

/**
 * Blocks LR cancel/soft-delete when an active Broker Lorry Hire exists.
 */
final class LorryHireLrDependencyGuard implements LrDependencyGuard
{
    public function blockersForCancel(LorryReceipt $lorryReceipt): array
    {
        $active = LorryHire::query()
            ->where('company_id', $lorryReceipt->company_id)
            ->where('lorry_receipt_id', $lorryReceipt->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', LorryHireStatus::Cancelled->value)
            ->exists();

        if (! $active) {
            return [];
        }

        return [
            new LrDependencyBlocker(
                code: 'lorry_hire.active',
                message: 'Cannot cancel LR while an active Broker Lorry Hire exists.',
                source: 'broker.lorry_hire',
            ),
        ];
    }

    public function blockersForSoftDelete(LorryReceipt $lorryReceipt): array
    {
        // Active hire blocks. Cancelled hire is cascade soft-deleted with the LR (Rules §11).
        $active = LorryHire::query()
            ->where('company_id', $lorryReceipt->company_id)
            ->where('lorry_receipt_id', $lorryReceipt->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', LorryHireStatus::Cancelled->value)
            ->exists();

        if (! $active) {
            return [];
        }

        return [
            new LrDependencyBlocker(
                code: 'lorry_hire.active',
                message: 'Cannot soft-delete LR while an active Broker Lorry Hire exists.',
                source: 'broker.lorry_hire',
            ),
        ];
    }
}
