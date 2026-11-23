<?php

namespace App\Domains\Broker\Enums;

enum LorryHireStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Cancelled = 'cancelled';
}
