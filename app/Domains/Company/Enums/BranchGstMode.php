<?php

namespace App\Domains\Company\Enums;

enum BranchGstMode: string
{
    case InheritCompany = 'inherit_company';
    case Independent = 'independent';
}
