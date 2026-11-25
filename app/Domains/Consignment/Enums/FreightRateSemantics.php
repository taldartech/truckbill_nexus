<?php

namespace App\Domains\Consignment\Enums;

/**
 * How weight-based rates interpret the rate number relative to stored KG weights.
 *
 * weight_kg — legacy Nexus: Per KG / Per Actual Weight / Per Charged Weight multiply rate × KG.
 * weight_mt — PO Nov 2025: Per Actual Weight / Per Charged Weight multiply rate × (KG/1000) MT.
 */
enum FreightRateSemantics: string
{
    case WeightKg = 'weight_kg';
    case WeightMt = 'weight_mt';

    public static function forNewRows(): self
    {
        return self::WeightMt;
    }
}
