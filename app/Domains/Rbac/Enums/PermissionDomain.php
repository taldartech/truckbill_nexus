<?php

namespace App\Domains\Rbac\Enums;

enum PermissionDomain: string
{
    case Internal = 'internal';
    case Customer = 'customer';
}
