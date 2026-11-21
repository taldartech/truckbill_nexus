<?php

namespace App\Support\Audit;

enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Cancelled = 'cancelled';

    public function requiresRemark(): bool
    {
        return match ($this) {
            self::Created => false,
            self::Updated, self::Deleted, self::Cancelled => true,
        };
    }

    public function requiresCancellationDetails(): bool
    {
        return $this === self::Cancelled;
    }
}
