<?php

namespace App\Domains\Enquiry\Enums;

enum QuotationSelectionStatus: string
{
    case Pending = 'pending';
    case Preferred = 'preferred';
    case NotSelected = 'not_selected';
}
