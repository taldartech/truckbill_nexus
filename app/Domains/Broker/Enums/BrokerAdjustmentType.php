<?php

namespace App\Domains\Broker\Enums;

enum BrokerAdjustmentType: string
{
    case Detention = 'detention';
    case Deviation = 'deviation';
    case MiscExpense = 'misc_expense';
    case UnloadingWeight = 'unloading_weight';
    case Shortage = 'shortage';
    case Damage = 'damage';
}
