<?php

namespace App\Domains\Identity\Enums;

enum IdentityType: string
{
    case Internal = 'internal';
    case Customer = 'customer';

    public function isInternal(): bool
    {
        return $this === self::Internal;
    }

    public function isCustomer(): bool
    {
        return $this === self::Customer;
    }
}
