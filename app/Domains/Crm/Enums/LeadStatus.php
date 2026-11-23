<?php

namespace App\Domains\Crm\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Converted = 'converted';
    case Disqualified = 'disqualified';
    case Lost = 'lost';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Contacted, self::Disqualified, self::Lost],
            self::Contacted => [self::Qualified, self::Disqualified, self::Lost],
            self::Qualified => [self::Converted, self::Disqualified, self::Lost],
            self::Converted, self::Disqualified, self::Lost => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Converted, self::Disqualified, self::Lost], true);
    }
}
