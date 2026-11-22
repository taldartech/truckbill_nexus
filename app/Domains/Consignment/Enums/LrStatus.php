<?php

namespace App\Domains\Consignment\Enums;

enum LrStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
}
