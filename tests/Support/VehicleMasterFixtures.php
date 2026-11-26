<?php

declare(strict_types=1);

require_once __DIR__.'/TyreLayoutFixtures.php';

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
        'code' => null,
        'is_active' => true,
    ]);
    $tyreLayout = createOwnerTyreLayout($owner, 'Test Layout '.$suffix);

    return array_merge([
        'ownership' => VehicleOwnership::Own->value,
        'truck_type_id' => $truckType->id,
        'tyre_layout_id' => $tyreLayout->id,
    ], $overrides);
}
