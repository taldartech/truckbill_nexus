<?php

namespace App\Domains\Master\Party;

use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Support\Database\CaseInsensitiveLike;

/**
 * Branch-visible Party options filtered by active role(s) for form selectors.
 */
final class PartyOptionQuery
{
    /**
     * @param  PartyRoleType|list<PartyRoleType>  $roles
     * @return list<array{id: string, name: string, city: string|null, state: string|null, contact_number: string|null}>
     */
    public function forRoles(
        Company $company,
        Branch $branch,
        PartyRoleType|array $roles,
        int $limit = 200,
        ?string $query = null,
        ?string $selectedId = null,
    ): array {
        $roleValues = $this->roleValues($roles);
        // Initial SSR hydrate may request up to 300; remote search endpoints cap lower.
        $limit = max(1, min(300, $limit));

        $builder = Party::query()
            ->visibleInBranch($company->id, $branch->id)
            ->whereNull('deleted_at')
            ->whereHas('roles', function ($roleQuery) use ($roleValues): void {
                $roleQuery->whereIn('role', $roleValues)
                    ->whereNull('deleted_at')
                    ->whereNull('deactivated_at');
            })
            ->orderBy('name')
            ->limit($limit);

        if ($query !== null && trim($query) !== '') {
            CaseInsensitiveLike::whereAnyContains($builder, [
                'name',
                'contact_number',
                'city',
                'email',
                'pan',
            ], $query);
        }

        $parties = $builder->get(['id', 'name', 'city', 'state', 'contact_number']);

        $options = [];
        $seen = [];
        foreach ($parties as $party) {
            $options[] = $this->optionPayload($party);
            $seen[$party->id] = true;
        }

        if ($selectedId !== null && $selectedId !== '' && ! isset($seen[$selectedId])) {
            $selected = Party::query()
                ->visibleInBranch($company->id, $branch->id)
                ->whereNull('deleted_at')
                ->whereKey($selectedId)
                ->whereHas('roles', function ($roleQuery) use ($roleValues): void {
                    $roleQuery->whereIn('role', $roleValues)
                        ->whereNull('deleted_at')
                        ->whereNull('deactivated_at');
                })
                ->first(['id', 'name', 'city', 'state', 'contact_number']);

            if ($selected !== null) {
                array_unshift($options, $this->optionPayload($selected));
            }
        }

        return $options;
    }

    /**
     * @param  PartyRoleType|list<PartyRoleType>  $roles
     * @return list<string>
     */
    private function roleValues(PartyRoleType|array $roles): array
    {
        return array_map(
            static fn (PartyRoleType $role): string => $role->value,
            is_array($roles) ? $roles : [$roles],
        );
    }

    /**
     * @return array{id: string, name: string, city: string|null, state: string|null, contact_number: string|null}
     */
    private function optionPayload(Party $party): array
    {
        return [
            'id' => $party->id,
            'name' => $party->name,
            'city' => $party->city,
            'state' => $party->state,
            'contact_number' => $party->contact_number,
        ];
    }
}
