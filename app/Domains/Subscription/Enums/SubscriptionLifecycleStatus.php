<?php

namespace App\Domains\Subscription\Enums;

/**
 * Effective subscription lifecycle status derived from persisted facts.
 * Distinct from company administrative Active/Deactivated.
 * Action-level enforcement cells remain Pending (AUD-CON-006).
 */
enum SubscriptionLifecycleStatus: string
{
    case None = 'none';
    case Draft = 'draft';
    case Trial = 'trial';
    case Active = 'active';
    case PaidGrace = 'paid_grace';
    case Restricted = 'restricted';
    case Cancelled = 'cancelled';
    case PendingPayment = 'pending_payment';
}
