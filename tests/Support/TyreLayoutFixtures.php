<?php

declare(strict_types=1);

use App\Domains\Master\TyreLayout\Enums\TyreAxleConfiguration;
use App\Domains\Master\TyreLayout\TyreLayoutPositionDeriver;
use App\Models\OwnerTyreLayout;
use App\Models\OwnerTyreLayoutAxle;
use App\Models\OwnerTyreLayoutPosition;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * @param  list<array{sequence: int, configuration: TyreAxleConfiguration|string}>  $axles
 */
function createOwnerTyreLayout(
    User $owner,
    string $name = 'Test Layout',
    array $axles = [
        ['sequence' => 1, 'configuration' => TyreAxleConfiguration::Single],
        ['sequence' => 2, 'configuration' => TyreAxleConfiguration::Dual],
    ],
    int $spareCount = 1,
): OwnerTyreLayout {
    $deriver = new TyreLayoutPositionDeriver;

    $normalizedAxles = [];
    foreach ($axles as $index => $axle) {
        $configuration = $axle['configuration'] instanceof TyreAxleConfiguration
            ? $axle['configuration']
            : TyreAxleConfiguration::from((string) $axle['configuration']);
        $normalizedAxles[] = [
            'sequence' => (int) ($axle['sequence'] ?? ($index + 1)),
            'configuration' => $configuration,
        ];
    }

    $derived = $deriver->deriveAll($normalizedAxles, $spareCount);

    $layout = OwnerTyreLayout::query()->create([
        'id' => (string) Str::uuid(),
        'owner_user_id' => $owner->id,
        'name' => $name,
        'spare_tyre_count' => $spareCount,
        'drive_tyre_count' => count($derived),
        'needs_axle_completion' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    foreach ($normalizedAxles as $axle) {
        OwnerTyreLayoutAxle::query()->create([
            'id' => (string) Str::uuid(),
            'tyre_layout_id' => $layout->id,
            'sequence' => $axle['sequence'],
            'configuration' => $axle['configuration'],
        ]);
    }

    foreach ($derived as $position) {
        OwnerTyreLayoutPosition::query()->create([
            'id' => (string) Str::uuid(),
            'tyre_layout_id' => $layout->id,
            ...$position,
            'is_active' => true,
        ]);
    }

    return $layout->fresh(['axles', 'positions']) ?? $layout;
}
