<?php

namespace App\Support\Outbox\Jobs;

use App\Support\Observability\CorrelationContext;
use App\Support\Observability\CorrelationId;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxProcessor;
use App\Support\Outbox\OutboxStatus;
use App\Support\Tenant\TenantAware;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProcessOutboxMessageJob implements ShouldQueue
{
    use Queueable;
    use TenantAware;

    /**
     * OutboxProcessor owns retry/backoff via outbox status; the job itself is single-attempt.
     */
    public int $tries = 1;

    public ?string $correlationId = null;

    public function __construct(
        public string $outboxMessageId,
    ) {}

    public function handle(OutboxProcessor $processor): void
    {
        $correlationId = $this->correlationId ?? CorrelationId::generate();
        CorrelationContext::set($correlationId);
        Log::withContext([
            'correlation_id' => $correlationId,
            'job' => 'ProcessOutboxMessageJob',
            'outbox_message_id' => $this->outboxMessageId,
        ]);

        try {
            $message = OutboxMessage::query()->find($this->outboxMessageId);

            if ($message === null) {
                return;
            }

            if ($message->status !== OutboxStatus::Processing->value) {
                return;
            }

            $holder = TenantContextHolder::require();

            if ($holder->companyId !== $message->company_id) {
                throw new RuntimeException('Outbox job tenant mismatch; cross-tenant processing denied.');
            }

            $processor->process($message);
        } finally {
            CorrelationContext::clear();
            Log::flushSharedContext();
        }
    }
}
