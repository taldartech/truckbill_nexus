<?php

namespace App\Domains\Consignment\Dependency;

use App\Models\LorryReceipt;

/**
 * Aggregates all registered dependency guards. Empty registry returns no blockers
 * from future modules, but never bypasses whatever is registered.
 */
final class CompositeLrDependencyGuard implements LrDependencyGuard
{
    /**
     * @param  list<LrDependencyGuard>  $guards
     */
    public function __construct(
        private readonly array $guards = [],
    ) {}

    public function blockersForCancel(LorryReceipt $lorryReceipt): array
    {
        return $this->collect(fn (LrDependencyGuard $guard): array => $guard->blockersForCancel($lorryReceipt));
    }

    public function blockersForSoftDelete(LorryReceipt $lorryReceipt): array
    {
        return $this->collect(fn (LrDependencyGuard $guard): array => $guard->blockersForSoftDelete($lorryReceipt));
    }

    /**
     * @param  callable(LrDependencyGuard): list<LrDependencyBlocker>  $callback
     * @return list<LrDependencyBlocker>
     */
    private function collect(callable $callback): array
    {
        $blockers = [];

        foreach ($this->guards as $guard) {
            foreach ($callback($guard) as $blocker) {
                $blockers[] = $blocker;
            }
        }

        return $blockers;
    }
}
