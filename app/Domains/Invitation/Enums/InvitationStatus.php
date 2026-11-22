<?php

namespace App\Domains\Invitation\Enums;

enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function isAcceptable(): bool
    {
        return $this === self::Pending;
    }
}
