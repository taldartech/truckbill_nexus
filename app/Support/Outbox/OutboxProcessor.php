<?php

namespace App\Support\Outbox;

use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Outbox\Consumers\OutboxConsumerRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Processes a claimed outbox message with tenant-aware idempotent consumption.
 */
class OutboxProcessor
{
    public function __construct(
        private readonly IdempotencyGuard $idempotencyGuard = new IdempotencyGuard,
        private readonly OutboxConsumerRegistry $consumers = new OutboxConsumerRegistry,
    ) {}

    public function process(OutboxMessage $message): void
    {
        if ($message->statusEnum()->isTerminal()) {
            return;
        }

        $fingerprint = hash('sha256', json_encode([
            'event_id' => $message->event_id,
            'event_type' => $message->event_type,
            'payload' => $message->payload,
        ], JSON_THROW_ON_ERROR));

        try {
            $this->idempotencyGuard->run(
                scope: 'outbox.consumer:'.$message->event_type,
                idempotencyKey: $message->event_id,
                requestHash: $fingerprint,
                callback: function () use ($message) {
                    $consumer = $this->consumers->for($message->event_type);
                    $consumer->handle($message);

                    return ['applied' => true, 'event_id' => $message->event_id];
                },
            );

            $message->status = OutboxStatus::Published->value;
            $message->published_at = now();
            $message->locked_at = null;
            $message->locked_by = null;
            $message->last_error = null;
            $message->save();
        } catch (Throwable $e) {
            $this->markFailure($message, $e);

            throw $e;
        }
    }

    private function markFailure(OutboxMessage $message, Throwable $e): void
    {
        DB::transaction(function () use ($message, $e): void {
            /** @var OutboxMessage $locked */
            $locked = OutboxMessage::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();

            if ($locked->statusEnum()->isTerminal()) {
                return;
            }

            $locked->attempts = $locked->attempts + 1;
            $locked->last_error = $this->maskError($e);
            $locked->locked_at = null;
            $locked->locked_by = null;

            if ($locked->attempts >= $locked->max_attempts) {
                $locked->status = OutboxStatus::Dead->value;
                $locked->failed_at = now();
            } else {
                $locked->status = OutboxStatus::Pending->value;
                $locked->available_at = now()->addSeconds($this->backoffSeconds($locked->attempts));
            }

            $locked->save();
        });
    }

    private function backoffSeconds(int $attempt): int
    {
        $base = (int) config('outbox.retry_base_seconds', 5);
        $max = (int) config('outbox.retry_max_seconds', 900);

        return min($max, $base * (2 ** max(0, $attempt - 1)));
    }

    private function maskError(Throwable $e): string
    {
        $raw = $e::class.': '.$e->getMessage();
        $sanitized = preg_replace('/[^\p{L}\p{N}\s._:-]/u', '', $raw) ?? $e::class;

        return Str::limit(trim($sanitized), 500, '…');
    }
}
