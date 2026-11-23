<?php

namespace App\Domains\Company\Enums;

enum DataVisibility: string
{
    case SharedData = 'shared_data';
    case BranchWiseRestricted = 'branch_wise_restricted';
}
