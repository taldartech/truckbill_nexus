<?php

namespace App\Domains\Master\Party\Enums;

enum PartyRoleType: string
{
    case Customer = 'customer';
    case Consignor = 'consignor';
    case Consignee = 'consignee';
    case Transporter = 'transporter';
    case Driver = 'driver';
    case GeneralVendor = 'general_vendor';
    case PumpVendor = 'pump_vendor';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Consignor => 'Consignor',
            self::Consignee => 'Consignee',
            self::Transporter => 'Transporter / Truck owner',
            self::Driver => 'Driver',
            self::GeneralVendor => 'General vendor',
            self::PumpVendor => 'Pump vendor',
        };
    }
}
