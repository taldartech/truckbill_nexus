<?php

namespace App\Support\Idempotency;

enum IdempotencyStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
