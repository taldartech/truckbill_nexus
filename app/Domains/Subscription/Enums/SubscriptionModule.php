<?php

namespace App\Domains\Subscription\Enums;

enum SubscriptionModule: string
{
    case Broker = 'broker';
    case OwnFleet = 'ownfleet';
    case Partload = 'partload';
}
