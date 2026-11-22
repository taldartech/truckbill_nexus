<?php

namespace App\Domains\Numbering\Enums;

enum DocumentNumberScopeType: string
{
    case Company = 'company';
    case Branch = 'branch';
}
