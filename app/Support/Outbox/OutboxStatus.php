<?php

namespace App\Support\Outbox;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Published = 'published';
    case Failed = 'failed';
    case Dead = 'dead';

    public function isTerminal(): bool
    {
        return $this === self::Published || $this === self::Dead;
    }
}
