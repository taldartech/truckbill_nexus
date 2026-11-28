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
    public function __construct(
        private readonly PartySearch $search = new PartySearch,
    ) {}

    /**
     * @param  PartyRoleType|list<PartyRoleType>  $roles
     * @return list<array{id: string, name: string, short_name: string, city: string|null, state: string|null, city_id: string|null, state_id: string|null, contact_number: string|null, transporter_declaration_received?: bool|null}>
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
            ->with(['roles' => function ($roleQuery) use ($roleValues): void {
                $roleQuery->whereIn('role', $roleValues)
                    ->whereNull('deleted_at')
                    ->whereNull('deactivated_at');
            }])
            ->limit($limit);

        $parties = $this->search->apply($builder, $query ?? '')
            ->get(['id', 'name', 'short_name', 'city', 'state', 'city_id', 'state_id', 'contact_number']);

        $options = [];
        $seen = [];
        foreach ($parties as $party) {
            $options[] = $this->optionPayload($party, $roleValues);
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
                ->with(['roles' => function ($roleQuery) use ($roleValues): void {
                    $roleQuery->whereIn('role', $roleValues)
                        ->whereNull('deleted_at')
                        ->whereNull('deactivated_at');
                }])
                ->first(['id', 'name', 'short_name', 'city', 'state', 'city_id', 'state_id', 'contact_number']);

            if ($selected !== null) {
                array_unshift($options, $this->optionPayload($selected, $roleValues));
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
     * @param  list<string>  $roleValues
     * @return array{id: string, name: string, short_name: string, city: string|null, state: string|null, city_id: string|null, state_id: string|null, contact_number: string|null, transporter_declaration_received?: bool|null}
     */
    private function optionPayload(Party $party, array $roleValues): array
    {
        $payload = [
            'id' => $party->id,
            'name' => $party->name,
            'short_name' => (string) $party->short_name,
            'city' => $party->city,
            'state' => $party->state,
            'state_id' => $party->state_id,
            'city_id' => $party->city_id,
            'contact_number' => $party->contact_number,
        ];

        if (in_array(PartyRoleType::Transporter->value, $roleValues, true)) {
            $role = $party->roles->first();
            $attrs = is_array($role?->role_attributes) ? $role->role_attributes : [];
            $declaration = $attrs['declaration'] ?? null;
            $payload['transporter_declaration_received'] = $declaration === null
                ? null
                : filter_var($declaration, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $declaration;
        }

        return $payload;
    }
}
