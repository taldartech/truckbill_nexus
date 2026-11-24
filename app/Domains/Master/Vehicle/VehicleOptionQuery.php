<?php

namespace App\Domains\Master\Vehicle;

use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Vehicle;
use App\Support\Database\CaseInsensitiveLike;

/**
 * Branch-visible Vehicle options for operational selectors.
 */
final class VehicleOptionQuery
{
    /**
     * @param  list<VehicleOwnership|string>|null  $ownerships
     * @return list<array{
     *     id: string,
     *     registration_number: string,
     *     ownership: string|null,
     *     ownership_label: string|null,
     *     label: string,
     *     owner_party_id: string|null,
     *     owner_name: string|null,
     *     owner_declaration_missing: bool,
     *     primary_driver_party_id: string|null,
     *     primary_driver_name: string|null
     * }>
     */
    public function forBranch(
        Company $company,
        Branch $branch,
        int $limit = 200,
        ?string $query = null,
        ?string $selectedId = null,
        ?array $ownerships = null,
        bool $activeOnly = true,
    ): array {
        $limit = max(1, min(300, $limit));

        $builder = Vehicle::query()
            ->visibleInBranch($company->id, $branch->id)
            ->whereNull('deleted_at')
            ->with([
                'ownerParty.roles' => fn ($roleQuery) => $roleQuery
                    ->where('role', PartyRoleType::Transporter->value)
                    ->whereNull('deleted_at'),
                'primaryDriver',
            ])
            ->orderBy('registration_number')
            ->limit($limit);

        if ($activeOnly) {
            $builder->where('is_active', true);
        }

        if ($ownerships !== null && $ownerships !== []) {
            $values = array_map(
                static fn (VehicleOwnership|string $ownership): string => $ownership instanceof VehicleOwnership
                    ? $ownership->value
                    : (string) $ownership,
                $ownerships,
            );
            $builder->whereIn('ownership', $values);
        }

        if ($query !== null && trim($query) !== '') {
            CaseInsensitiveLike::whereAnyContains($builder, [
                'registration_number',
                'make',
                'model',
            ], $query);
        }

        $vehicles = $builder->get();
        $options = [];
        $seen = [];
        foreach ($vehicles as $vehicle) {
            $options[] = $this->optionPayload($vehicle);
            $seen[$vehicle->id] = true;
        }

        if ($selectedId !== null && $selectedId !== '' && ! isset($seen[$selectedId])) {
            $selected = Vehicle::query()
                ->visibleInBranch($company->id, $branch->id)
                ->whereNull('deleted_at')
                ->whereKey($selectedId)
                ->with([
                    'ownerParty.roles' => fn ($roleQuery) => $roleQuery
                        ->where('role', PartyRoleType::Transporter->value)
                        ->whereNull('deleted_at'),
                    'primaryDriver',
                ])
                ->first();

            if ($selected !== null) {
                array_unshift($options, $this->optionPayload($selected));
            }
        }

        return $options;
    }

    /**
     * @return array{
     *     id: string,
     *     registration_number: string,
     *     ownership: string|null,
     *     ownership_label: string|null,
     *     label: string,
     *     owner_party_id: string|null,
     *     owner_name: string|null,
     *     owner_declaration_missing: bool,
     *     primary_driver_party_id: string|null,
     *     primary_driver_name: string|null
     * }
     */
    private function optionPayload(Vehicle $vehicle): array
    {
        $ownership = $vehicle->ownership;
        $owner = $vehicle->ownerParty;
        $declarationMissing = false;
        if ($owner !== null) {
            $transporterRole = $owner->roles->first();
            if ($transporterRole !== null) {
                $attrs = is_array($transporterRole->role_attributes)
                    ? $transporterRole->role_attributes
                    : [];
                $declarationMissing = ! filter_var($attrs['declaration'] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
        }

        return [
            'id' => $vehicle->id,
            'registration_number' => (string) $vehicle->registration_number,
            'ownership' => $ownership?->value,
            'ownership_label' => $ownership?->label(),
            'label' => trim($vehicle->registration_number.' · '.($ownership?->label() ?? 'Unclassified')),
            'owner_party_id' => $vehicle->owner_party_id,
            'owner_name' => $owner?->name,
            'owner_declaration_missing' => $declarationMissing,
            'primary_driver_party_id' => $vehicle->primary_driver_party_id,
            'primary_driver_name' => $vehicle->primaryDriver?->name,
        ];
    }
}
