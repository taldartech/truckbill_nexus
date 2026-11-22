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
}
