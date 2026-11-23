<?php

declare(strict_types=1);

use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Models\OwnerCatalogueItem;
use App\Models\User;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ownVehicleMasterPayload(User $owner, array $overrides = []): array
{
    $suffix = substr((string) abs(crc32($owner->id.microtime())), 0, 6);

    $truckType = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::TruckType->value,
        'name' => 'Test Truck '.$suffix,
        'code' => 'TT'.$suffix,
        'is_active' => true,
    ]);
    $tyreLayout = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $owner->id,
        'catalogue_type' => OwnerCatalogueType::TyreLayout->value,
        'name' => 'Test Layout '.$suffix,
        'code' => 'TL'.$suffix,
        'is_active' => true,
    ]);

    return array_merge([
        'ownership' => VehicleOwnership::Own->value,
        'truck_type_id' => $truckType->id,
        'tyre_layout_id' => $tyreLayout->id,
    ], $overrides);
}
