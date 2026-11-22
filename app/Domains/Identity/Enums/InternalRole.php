<?php

namespace App\Domains\Identity\Enums;

/**
 * Minimal Internal Team role flags (PRD §6).
 *
 * Full Permission Master / RBAC evaluation belongs to P2-WS-3.
 * Company Owner is not an InternalRole and is not modeled here.
 */
enum InternalRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case SalesExecutive = 'sales_executive';
    case SupportExecutive = 'support_executive';

    public function isSuperAdmin(): bool
    {
        return $this === self::SuperAdmin;
    }
}
