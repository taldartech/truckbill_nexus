<?php

namespace App\Domains\Consignment\Enums;

enum LrWorkflowContext: string
{
    case Broker = 'broker';
    case OwnFleet = 'own_fleet';
    case Partload = 'partload';
}
