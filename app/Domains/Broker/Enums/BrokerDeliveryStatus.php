<?php

namespace App\Domains\Broker\Enums;

enum BrokerDeliveryStatus: string
{
    case Pending = 'pending';
    case MemoAssigned = 'memo_assigned';
    case Delivered = 'delivered';
}
