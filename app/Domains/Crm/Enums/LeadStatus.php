<?php

namespace App\Domains\Crm\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case OnboardingInviteSent = 'onboarding_invite_sent';
    case Onboarded = 'onboarded';
    case Trial = 'trial';
    case Converted = 'converted';
    case Expired = 'expired';
    case Disqualified = 'disqualified';
    case Lost = 'lost';

    /**
     * Manual CRM transitions (system lifecycle uses LeadLifecycleService).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Contacted, self::Disqualified, self::Lost],
            self::Contacted => [self::Qualified, self::Disqualified, self::Lost],
            self::Qualified => [self::OnboardingInviteSent, self::Disqualified, self::Lost],
            self::OnboardingInviteSent => [self::Onboarded, self::Qualified, self::Disqualified, self::Lost],
            self::Onboarded => [self::Lost],
            self::Trial => [self::Lost],
            self::Expired => [self::Lost],
            self::Converted, self::Disqualified, self::Lost => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Converted, self::Disqualified, self::Lost], true);
    }

    public function canReceiveOnboardingInvite(): bool
    {
        return in_array($this, [
            self::New,
            self::Contacted,
            self::Qualified,
            self::OnboardingInviteSent,
        ], true);
    }
}
