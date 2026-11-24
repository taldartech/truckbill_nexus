<?php

namespace App\Domains\Master\Party;

use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;

/**
 * Branch-visible Party options filtered by active role(s) for form selectors.
 */
final class PartyOptionQuery
{
    /**
     * @param  PartyRoleType|list<PartyRoleType>  $roles
     * @return list<array{id: string, name: string}>
     */
    public function forRoles(
        Company $company,
        Branch $branch,
        PartyRoleType|array $roles,
        int $limit = 200,
    ): array {
        $roleValues = array_map(
            static fn (PartyRoleType $role): string => $role->value,
            is_array($roles) ? $roles : [$roles],
        );

        $parties = Party::query()
            ->visibleInBranch($company->id, $branch->id)
            ->whereNull('deleted_at')
            ->whereHas('roles', function ($query) use ($roleValues): void {
                $query->whereIn('role', $roleValues)
                    ->whereNull('deleted_at')
                    ->whereNull('deactivated_at');
            })
            ->orderBy('name')
            ->limit(max(1, min(500, $limit)))
            ->get(['id', 'name']);

        $options = [];
        foreach ($parties as $party) {
            $options[] = [
                'id' => $party->id,
                'name' => $party->name,
            ];
        }

        return $options;
    }
}
