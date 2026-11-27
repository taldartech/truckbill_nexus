<?php

namespace App\Domains\Enquiry\Support;

use App\Domains\Geography\GeographyLookupService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Models\City;
use App\Models\Company;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\PartyRole;
use App\Support\Database\CaseInsensitiveLike;
use App\Support\Weight\OperationalWeight;
use Illuminate\Validation\ValidationException;

/**
 * Shared manual + website enquiry payload normalization (route, masters, parties, goods).
 */
final class EnquiryIntakeSupport
{
    public function __construct(
        private readonly GeographyLookupService $geography = new GeographyLookupService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     party_name: ?string,
     *     customer_party_id: ?string,
     *     consignor_party_id: ?string,
     *     consignee_party_id: ?string,
     *     from_state_id: ?string,
     *     from_city_id: ?string,
     *     to_state_id: ?string,
     *     to_city_id: ?string,
     *     pickup_location: ?string,
     *     delivery_location: ?string,
     * }
     */
    public function resolveManualRouteAndParties(Company $company, array $payload): array
    {
        $partyName = $this->optionalString($payload['party_name'] ?? null);
        $customerPartyId = $this->optionalUuid($payload['customer_party_id'] ?? null);
        $consignorPartyId = $this->optionalUuid($payload['consignor_party_id'] ?? null);
        $consigneePartyId = $this->optionalUuid($payload['consignee_party_id'] ?? null);

        if ($customerPartyId !== null) {
            $this->assertPartyRole($company, $customerPartyId, PartyRoleType::Customer, 'customer_party_id');
        }

        if ($consignorPartyId !== null) {
            $this->assertPartyRole($company, $consignorPartyId, PartyRoleType::Consignor, 'consignor_party_id');
        }

        if ($consigneePartyId !== null) {
            $this->assertPartyRole($company, $consigneePartyId, PartyRoleType::Consignee, 'consignee_party_id');
        }

        if ($customerPartyId === null && ($partyName === null || $partyName === '')
            && $consignorPartyId === null && $consigneePartyId === null) {
            throw ValidationException::withMessages([
                'party_name' => 'Provide party name, customer, consignor, or consignee.',
            ]);
        }

        $consignorLocation = $this->partyMasterLocation($company, $consignorPartyId);
        $consigneeLocation = $this->partyMasterLocation($company, $consigneePartyId);

        $origin = $this->resolveRouteEndpoint(
            $consignorLocation['state_id'] ?? $this->optionalUuid($payload['from_state_id'] ?? null),
            $consignorLocation['city_id'] ?? $this->optionalUuid($payload['from_city_id'] ?? null),
            'from_state_id',
            'from_city_id',
        );
        $destination = $this->resolveRouteEndpoint(
            $consigneeLocation['state_id'] ?? $this->optionalUuid($payload['to_state_id'] ?? null),
            $consigneeLocation['city_id'] ?? $this->optionalUuid($payload['to_city_id'] ?? null),
            'to_state_id',
            'to_city_id',
        );

        return [
            'party_name' => $partyName,
            'customer_party_id' => $customerPartyId,
            'consignor_party_id' => $consignorPartyId,
            'consignee_party_id' => $consigneePartyId,
            'from_state_id' => $origin['state_id'],
            'from_city_id' => $origin['city_id'],
            'to_state_id' => $destination['state_id'],
            'to_city_id' => $destination['city_id'],
            'pickup_location' => $origin['label'],
            'delivery_location' => $destination['label'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function normalizeManualGoods(Company $company, array $payload): array
    {
        $goodsTypeId = $this->optionalUuid($payload['goods_type_id'] ?? null);
        $weightMt = $payload['weight_mt'] ?? null;

        if (is_array($payload['goods'] ?? null) && $payload['goods'] !== []) {
            $row = $payload['goods'][0];
            if (! is_array($row)) {
                throw ValidationException::withMessages(['goods' => 'Goods row must be an object.']);
            }

            $goodsTypeId = $goodsTypeId ?? $this->optionalUuid($row['goods_type_id'] ?? null);
            if ($weightMt === null && isset($row['weight_mt'])) {
                $weightMt = $row['weight_mt'];
            }
            if ($weightMt === null && isset($row['actual_weight'])) {
                $weightMt = OperationalWeight::kgToMt((string) $row['actual_weight']);
            }
        }

        if ($goodsTypeId === null) {
            throw ValidationException::withMessages(['goods_type_id' => 'Goods type is required.']);
        }

        $goodsType = $this->assertCatalogueItem(
            $company,
            $goodsTypeId,
            OwnerCatalogueType::GoodsType,
            'goods_type_id',
        );

        if ($weightMt === null || trim((string) $weightMt) === '') {
            throw ValidationException::withMessages(['weight_mt' => 'Weight (MT) is required.']);
        }

        $weightKg = OperationalWeight::mtToKg((string) $weightMt);
        if (trim($weightKg) === '' || (float) $weightKg <= 0) {
            throw ValidationException::withMessages(['weight_mt' => 'Weight must be greater than zero.']);
        }

        return [[
            'goods_type_id' => $goodsType->id,
            'package_type_id' => null,
            'goods_type_code' => null,
            'package_type_code' => null,
            'goods_description' => null,
            'package_count' => null,
            'actual_weight' => $weightKg,
            'charged_weight' => $weightKg,
        ]];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{vehicle_type_id: ?string, pending: array<string, mixed>}
     */
    public function resolveVehicleType(Company $company, array $payload, bool $required): array
    {
        $pending = [];
        $id = $this->optionalUuid($payload['vehicle_type_id'] ?? null);
        if ($id !== null) {
            $item = $this->assertCatalogueItem($company, $id, OwnerCatalogueType::TruckType, 'vehicle_type_id');

            return ['vehicle_type_id' => $item->id, 'pending' => $pending];
        }

        $text = $this->optionalString(
            $payload['vehicle_type'] ?? $payload['vehicle_type_code'] ?? null,
        );

        if ($text === null) {
            if ($required) {
                throw ValidationException::withMessages(['vehicle_type_id' => 'Vehicle type is required.']);
            }

            return ['vehicle_type_id' => null, 'pending' => ['vehicle_type' => $payload['vehicle_type'] ?? null]];
        }

        $resolved = $this->resolveCatalogueByText($company, OwnerCatalogueType::TruckType, $text);
        if ($resolved['id'] !== null) {
            return ['vehicle_type_id' => $resolved['id'], 'pending' => $pending];
        }

        $pending['vehicle_type'] = $text;
        if ($required) {
            throw ValidationException::withMessages(['vehicle_type' => 'Vehicle type could not be matched unambiguously.']);
        }

        return ['vehicle_type_id' => null, 'pending' => $pending];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{goods: list<array<string, mixed>>, pending: array<string, mixed>}
     */
    public function resolveWebsiteGoods(Company $company, array $payload): array
    {
        $pending = [];

        if (is_array($payload['goods'] ?? null) && $payload['goods'] !== []) {
            /** @var list<mixed> $legacyGoods */
            $legacyGoods = array_values($payload['goods']);

            return [
                'goods' => $this->normalizeLegacyGoodsRows($company, $legacyGoods),
                'pending' => $pending,
            ];
        }

        $goodsInput = $payload['goods_type'] ?? $payload['goods_type_id'] ?? null;
        $weightMt = $payload['weight_mt'] ?? null;

        if ($goodsInput === null && $weightMt === null) {
            throw ValidationException::withMessages(['goods' => 'Goods type and weight are required.']);
        }

        $goodsTypeId = null;
        if ($this->looksLikeUuid((string) $goodsInput)) {
            $goodsTypeId = (string) $goodsInput;
        } elseif ($goodsInput !== null) {
            $resolved = $this->resolveCatalogueByText($company, OwnerCatalogueType::GoodsType, (string) $goodsInput);
            $goodsTypeId = $resolved['id'];
            if ($goodsTypeId === null) {
                $pending['goods_type'] = (string) $goodsInput;
            }
        }

        $weightKg = null;
        if ($weightMt !== null && trim((string) $weightMt) !== '') {
            $weightKg = OperationalWeight::mtToKg((string) $weightMt);
        }

        if ($weightKg === null || trim($weightKg) === '' || (float) $weightKg <= 0) {
            throw ValidationException::withMessages(['weight_mt' => 'Weight (MT) is required and must be positive.']);
        }

        if ($goodsTypeId !== null) {
            $this->assertCatalogueItem($company, $goodsTypeId, OwnerCatalogueType::GoodsType, 'goods_type_id');
        }

        return [
            'goods' => [[
                'goods_type_id' => $goodsTypeId,
                'package_type_id' => null,
                'goods_type_code' => null,
                'package_type_code' => null,
                'goods_description' => $goodsTypeId === null ? (string) ($goodsInput ?? 'Goods') : null,
                'package_count' => null,
                'actual_weight' => $weightKg,
                'charged_weight' => $weightKg,
            ]],
            'pending' => $pending,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     from_state_id: ?string,
     *     from_city_id: ?string,
     *     to_state_id: ?string,
     *     to_city_id: ?string,
     *     pickup_location: ?string,
     *     delivery_location: ?string,
     *     pending: array<string, mixed>
     * }
     */
    public function resolveWebsiteRoute(Company $company, array $payload): array
    {
        $pending = [];

        $from = $this->resolveWebsiteEndpoint(
            stateId: $this->optionalUuid($payload['from_state_id'] ?? null),
            cityId: $this->optionalUuid($payload['from_city_id'] ?? null),
            stateText: $this->optionalString($payload['from_state'] ?? null),
            cityText: $this->optionalString($payload['from_city'] ?? null),
            legacyLocation: $this->optionalString($payload['pickup_location'] ?? null),
            stateField: 'from_state',
            cityField: 'from_city',
        );

        $to = $this->resolveWebsiteEndpoint(
            stateId: $this->optionalUuid($payload['to_state_id'] ?? null),
            cityId: $this->optionalUuid($payload['to_city_id'] ?? null),
            stateText: $this->optionalString($payload['to_state'] ?? null),
            cityText: $this->optionalString($payload['to_city'] ?? null),
            legacyLocation: $this->optionalString($payload['delivery_location'] ?? null),
            stateField: 'to_state',
            cityField: 'to_city',
        );

        foreach (['from' => $from, 'to' => $to] as $key => $endpoint) {
            if ($endpoint['pending'] !== []) {
                $pending[$key] = $endpoint['pending'];
            }
        }

        return [
            'from_state_id' => $from['state_id'],
            'from_city_id' => $from['city_id'],
            'to_state_id' => $to['state_id'],
            'to_city_id' => $to['city_id'],
            'pickup_location' => $from['label'],
            'delivery_location' => $to['label'],
            'pending' => $pending,
        ];
    }

    /**
     * @return array{id: ?string, ambiguous: bool}
     */
    public function resolveCatalogueByText(Company $company, OwnerCatalogueType $type, string $text): array
    {
        $needle = trim($text);
        if ($needle === '') {
            return ['id' => null, 'ambiguous' => false];
        }

        if ($this->looksLikeUuid($needle)) {
            $item = OwnerCatalogueItem::query()
                ->whereKey($needle)
                ->where('owner_user_id', $company->owner_user_id)
                ->where('catalogue_type', $type->value)
                ->where('is_active', true)
                ->first();

            return ['id' => $item?->id, 'ambiguous' => false];
        }

        $query = OwnerCatalogueItem::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->where('catalogue_type', $type->value)
            ->where('is_active', true)
            ->whereNull('deleted_at');

        CaseInsensitiveLike::whereAnyContains($query, ['name', 'code'], $needle);

        $matches = $query->limit(3)->get(['id', 'name', 'code']);

        if ($matches->count() === 1) {
            return ['id' => $matches->first()->id, 'ambiguous' => false];
        }

        if ($matches->count() > 1) {
            return ['id' => null, 'ambiguous' => true];
        }

        return ['id' => null, 'ambiguous' => false];
    }

    /**
     * @param  list<mixed>  $goods
     * @return list<array<string, mixed>>
     */
    private function normalizeLegacyGoodsRows(Company $company, array $goods): array
    {
        $normalized = [];
        foreach ($goods as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(["goods.{$index}" => 'Goods row must be an object.']);
            }

            $goodsTypeId = null;
            if (! empty($row['goods_type_id'])) {
                $item = $this->assertCatalogueItem(
                    $company,
                    (string) $row['goods_type_id'],
                    OwnerCatalogueType::GoodsType,
                    "goods.{$index}.goods_type_id",
                );
                $goodsTypeId = $item->id;
            } elseif (! empty($row['goods_type_code'])) {
                $resolved = $this->resolveCatalogueByText($company, OwnerCatalogueType::GoodsType, (string) $row['goods_type_code']);
                $goodsTypeId = $resolved['id'];
            }

            $description = $this->optionalString($row['goods_description'] ?? null);
            if ($goodsTypeId === null && ($description === null || $description === '')) {
                throw ValidationException::withMessages([
                    "goods.{$index}" => 'Each goods row requires a goods type or description.',
                ]);
            }

            $normalized[] = [
                'goods_type_id' => $goodsTypeId,
                'package_type_id' => null,
                'goods_type_code' => null,
                'package_type_code' => null,
                'goods_description' => $description,
                'package_count' => null,
                'actual_weight' => $row['actual_weight'] ?? null,
                'charged_weight' => $row['charged_weight'] ?? $row['actual_weight'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * @return array{state_id: string, city_id: string, label: string}
     */
    private function resolveRouteEndpoint(
        ?string $stateId,
        ?string $cityId,
        string $stateField,
        string $cityField,
    ): array {
        if ($stateId === null || $cityId === null) {
            throw ValidationException::withMessages([
                $stateId === null ? $stateField : $cityField => 'Select the State and City from the master list.',
            ]);
        }

        try {
            $resolved = $this->geography->resolveAddressFields($stateId, $cityId);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            throw ValidationException::withMessages(array_filter([
                $stateField => $errors['state_id'] ?? null,
                $cityField => $errors['city_id'] ?? null,
            ]));
        }

        return [
            'state_id' => (string) $resolved['state_id'],
            'city_id' => (string) $resolved['city_id'],
            'label' => $this->locationLabel($resolved['city'], $resolved['state']),
        ];
    }

    /**
     * @return array{
     *     state_id: ?string,
     *     city_id: ?string,
     *     label: ?string,
     *     pending: array<string, mixed>
     * }
     */
    private function resolveWebsiteEndpoint(
        ?string $stateId,
        ?string $cityId,
        ?string $stateText,
        ?string $cityText,
        ?string $legacyLocation,
        string $stateField,
        string $cityField,
    ): array {
        $pending = [];

        if ($stateId !== null && $cityId !== null) {
            $resolved = $this->geography->resolveAddressFields($stateId, $cityId);

            return [
                'state_id' => $resolved['state_id'],
                'city_id' => $resolved['city_id'],
                'label' => $this->locationLabel($resolved['city'], $resolved['state']),
                'pending' => [],
            ];
        }

        if ($stateText !== null) {
            $resolvedStateId = $this->geography->findActiveStateIdByName($stateText);
            if ($resolvedStateId === null) {
                $pending[$stateField] = $stateText;
            } else {
                $stateId = $resolvedStateId;
                if ($cityText !== null) {
                    $resolvedCityId = $this->geography->findActiveCityIdByName($resolvedStateId, $cityText);
                    if ($resolvedCityId === null) {
                        $pending[$cityField] = $cityText;
                    } else {
                        $cityId = $resolvedCityId;
                    }
                }
            }
        }

        if ($stateId !== null && $cityId !== null) {
            $resolved = $this->geography->resolveAddressFields($stateId, $cityId);

            return [
                'state_id' => $resolved['state_id'],
                'city_id' => $resolved['city_id'],
                'label' => $this->locationLabel($resolved['city'], $resolved['state']),
                'pending' => $pending,
            ];
        }

        if ($legacyLocation !== null && $legacyLocation !== '') {
            return [
                'state_id' => $stateId,
                'city_id' => $cityId,
                'label' => $legacyLocation,
                'pending' => $pending,
            ];
        }

        if ($cityText !== null || $stateText !== null) {
            $label = trim(implode(', ', array_filter([$cityText, $stateText], fn (?string $v): bool => filled($v))));
            if ($label !== '') {
                return [
                    'state_id' => $stateId,
                    'city_id' => $cityId,
                    'label' => $label,
                    'pending' => $pending,
                ];
            }
        }

        throw ValidationException::withMessages([
            $stateField => 'Origin and destination route details are required.',
        ]);
    }

    private function locationLabel(?string $city, ?string $state): string
    {
        $parts = array_values(array_filter([$city, $state], fn (?string $value): bool => filled($value)));

        return implode(', ', $parts);
    }

    private function assertPartyRole(Company $company, string $partyId, PartyRoleType $role, string $field): Party
    {
        /** @var Party|null $party */
        $party = Party::query()
            ->where('company_id', $company->id)
            ->whereKey($partyId)
            ->first();

        if ($party === null) {
            throw ValidationException::withMessages([$field => 'Party was not found in this company.']);
        }

        $hasRole = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('party_id', $party->id)
            ->where('role', $role->value)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasRole) {
            throw ValidationException::withMessages([$field => "Selected party must have an active {$role->value} role."]);
        }

        return $party;
    }

    private function assertCatalogueItem(
        Company $company,
        string $itemId,
        OwnerCatalogueType $type,
        string $field,
    ): OwnerCatalogueItem {
        /** @var OwnerCatalogueItem|null $item */
        $item = OwnerCatalogueItem::query()
            ->whereKey($itemId)
            ->where('owner_user_id', $company->owner_user_id)
            ->where('catalogue_type', $type->value)
            ->where('is_active', true)
            ->first();

        if ($item === null) {
            throw ValidationException::withMessages([$field => 'Catalogue reference is invalid for this company.']);
        }

        return $item;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * The party's active master State/City pair, or null when the party has no usable location.
     *
     * @return array{state_id: string, city_id: string}|null
     */
    private function partyMasterLocation(Company $company, ?string $partyId): ?array
    {
        if ($partyId === null) {
            return null;
        }

        $party = Party::query()->where('company_id', $company->id)->whereKey($partyId)->first(['state_id', 'city_id']);
        if ($party?->state_id === null || $party->city_id === null) {
            return null;
        }

        $isActiveMasterPair = City::query()
            ->whereKey($party->city_id)
            ->where('state_id', $party->state_id)
            ->where('is_active', true)
            ->whereHas('state', fn ($state) => $state->where('is_active', true))
            ->exists();

        return $isActiveMasterPair
            ? ['state_id' => (string) $party->state_id, 'city_id' => (string) $party->city_id]
            : null;
    }

    private function optionalUuid(mixed $value): ?string
    {
        $text = $this->optionalString($value);
        if ($text === null) {
            return null;
        }

        return $this->looksLikeUuid($text) ? $text : null;
    }

    private function looksLikeUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value,
        );
    }
}
