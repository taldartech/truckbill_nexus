<?php

namespace App\Support\Outbox;

use App\Support\Outbox\Jobs\ProcessOutboxMessageJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Claims committed pending outbox rows and dispatches queue jobs (publish-after-commit).
 *
 * Uses PostgreSQL `FOR UPDATE SKIP LOCKED` so concurrent workers cannot claim the same row.
 */
class OutboxDispatcher
{
    /**
     * @return list<OutboxMessage>
     */
    public function dispatchBatch(?int $limit = null, ?string $workerId = null): array
    {
        $limit ??= (int) config('outbox.batch_size', 50);
        $workerId ??= 'worker-'.Str::uuid();

        $claimed = DB::transaction(function () use ($limit, $workerId) {
            /** @var list<OutboxMessage> $messages */
            $messages = OutboxMessage::query()
                ->where('status', OutboxStatus::Pending->value)
                ->where('available_at', '<=', now())
                ->orderBy('created_at')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get()
                ->all();

            foreach ($messages as $message) {
                $message->status = OutboxStatus::Processing->value;
                $message->locked_at = now();
                $message->locked_by = $workerId;
                $message->save();
            }

            return $messages;
        });

        foreach ($claimed as $message) {
            $job = (new ProcessOutboxMessageJob($message->id))
                ->withTenantContext($message->tenantContext());
            $job->correlationId = $message->correlation_id;

            dispatch($job)->onQueue('outbox');
        }

        return $claimed;
    }
}
