<?php

namespace App\Domains\Consignment\Enums;

enum FreightRateType: string
{
    case FixedAmount = 'fixed_amount';
    case PerPackageUnit = 'per_package_unit';
    case PerKg = 'per_kg';
    case PerMt = 'per_mt';
    case PerActualWeight = 'per_actual_weight';
    case PerChargedWeight = 'per_charged_weight';
}
