<?php

namespace App\Domains\Consignment\Dependency;

use App\Models\LorryReceipt;

/**
 * Extensible dependency checks for LR cancel / soft-delete.
 *
 * Absence of future module guards must never skip registered checks.
 * Later Trip / Lorry Hire / Partload modules register additional guards.
 */
interface LrDependencyGuard
{
    /**
     * @return list<LrDependencyBlocker>
     */
    public function blockersForCancel(LorryReceipt $lorryReceipt): array;

    /**
     * @return list<LrDependencyBlocker>
     */
    public function blockersForSoftDelete(LorryReceipt $lorryReceipt): array;
}
