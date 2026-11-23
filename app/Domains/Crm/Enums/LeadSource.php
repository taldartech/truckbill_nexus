<?php

namespace App\Domains\Crm\Enums;

enum LeadSource: string
{
    case Website = 'website';
    case Referral = 'referral';
    case ColdCall = 'cold_call';
    case Event = 'event';
    case Partner = 'partner';
    case Other = 'other';
}
