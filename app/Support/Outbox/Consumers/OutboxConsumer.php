<?php

namespace App\Support\Outbox\Consumers;

use App\Support\Outbox\OutboxMessage;

interface OutboxConsumer
{
    public function handle(OutboxMessage $message): void;
}
