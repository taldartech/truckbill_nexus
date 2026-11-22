<?php

namespace App\Policies;

use App\Models\User;

/**
 * Minimal identity-type boundary foundations (P2-WS-1).
 *
 * Full RBAC / membership authorization is P2-WS-2 / P2-WS-3.
 * Identity type alone does not grant Company Owner or Super Admin operational privileges beyond type checks.
 */
class UserPolicy
{
    /**
     * Internal administration surfaces may only target Internal identities, and only by Internal actors.
     */
    public function administerInternal(User $actor, User $subject): bool
    {
        return $actor->isInternal() && $actor->isActive() && $subject->isInternal();
    }

    /**
     * Customer-domain operations may only target Customer identities.
     * Internal actors may administer Customer records; Customer actors may not administer Internal identities.
     */
    public function administerCustomer(User $actor, User $subject): bool
    {
        if (! $subject->isCustomer()) {
            return false;
        }

        if ($actor->isInternal()) {
            return $actor->isActive();
        }

        return $actor->isCustomer() && $actor->isActive() && $actor->is($subject);
    }

    /**
     * Identity type alone never implies Company Owner privileges (Owner is a company designation).
     */
    public function actAsCompanyOwner(User $actor, User $subject): bool
    {
        return false;
    }
}
