<?php

namespace App\Console\Commands;

use App\Support\Outbox\OutboxDispatcher;
use Illuminate\Console\Command;

class DispatchOutboxCommand extends Command
{
    protected $signature = 'outbox:dispatch {--limit= : Max messages to claim}';

    protected $description = 'Claim committed pending outbox messages and dispatch queue jobs';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $limit = $this->option('limit');
        $claimed = $dispatcher->dispatchBatch(
            limit: $limit !== null ? (int) $limit : null,
        );

        $this->info(sprintf('Claimed %d outbox message(s).', count($claimed)));

        return self::SUCCESS;
    }
}
