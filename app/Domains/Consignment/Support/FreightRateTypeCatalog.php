<?php

declare(strict_types=1);

namespace App\Domains\Consignment\Support;

use App\Domains\Consignment\Enums\FreightRateType;

/**
 * Authoritative selectable freight rate types and display order (Rules §27).
 *
 * Legacy stored values outside this set remain valid for historical rows.
 */
final class FreightRateTypeCatalog
{
    /** @var list<FreightRateType> */
    public const SELECTABLE_TYPES = [
        FreightRateType::PerChargedWeight,
        FreightRateType::PerActualWeight,
        FreightRateType::FixedAmount,
        FreightRateType::PerPackageUnit,
    ];

    public static function default(): FreightRateType
    {
        return FreightRateType::PerChargedWeight;
    }

    public static function label(FreightRateType $type): string
    {
        return match ($type) {
            FreightRateType::PerChargedWeight => 'Per Charged Weight',
            FreightRateType::PerActualWeight => 'Per Actual Weight',
            FreightRateType::FixedAmount => 'Fixed',
            FreightRateType::PerPackageUnit => 'Per Unit',
            FreightRateType::PerKg => 'Per KG',
            FreightRateType::PerMt => 'Per MT',
        };
    }

    /**
     * @return list<string>
     */
    public static function selectableValues(): array
    {
        return array_map(
            static fn (FreightRateType $type): string => $type->value,
            self::SELECTABLE_TYPES,
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function inertiaOptions(): array
    {
        $options = [];
        foreach (self::SELECTABLE_TYPES as $type) {
            $options[] = [
                'value' => $type->value,
                'label' => self::label($type),
            ];
        }

        return $options;
    }
}
