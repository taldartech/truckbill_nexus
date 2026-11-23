<?php

namespace App\Domains\OwnFleet\Trip;

use App\Domains\Consignment\Dependency\LrDependencyBlocker;
use App\Domains\Consignment\Dependency\LrDependencyGuard;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Models\LorryReceipt;
use App\Models\Trip;

/**
 * Blocks LR cancel/soft-delete when an OwnFleet Trip dependency exists (Rules §8–§9).
 */
final class TripLrDependencyGuard implements LrDependencyGuard
{
    public function blockersForCancel(LorryReceipt $lorryReceipt): array
    {
        $active = Trip::query()
            ->where('company_id', $lorryReceipt->company_id)
            ->where('lorry_receipt_id', $lorryReceipt->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', TripStatus::Cancelled->value)
            ->exists();

        if (! $active) {
            return [];
        }

        return [
            new LrDependencyBlocker(
                code: 'trip.active',
                message: 'Cannot cancel LR while an active OwnFleet Trip exists.',
                source: 'ownfleet.trip',
            ),
        ];
    }

    public function blockersForSoftDelete(LorryReceipt $lorryReceipt): array
    {
        $exists = Trip::query()
            ->where('company_id', $lorryReceipt->company_id)
            ->where('lorry_receipt_id', $lorryReceipt->id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            return [];
        }

        return [
            new LrDependencyBlocker(
                code: 'trip.exists',
                message: 'Cannot soft-delete LR while an OwnFleet Trip exists.',
                source: 'ownfleet.trip',
            ),
        ];
    }
}
