<?php

namespace App\Support\Outbox\Harness;

use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Audit\Harness\AuditHarnessRecord;
use App\Support\Outbox\OutboxWriter;
use RuntimeException;

/**
 * Foundation harness: business mutation + audit + outbox in one AuditedTransaction.
 */
class OutboxHarnessService
{
    public function __construct(
        private readonly AuditedTransaction $transaction = new AuditedTransaction,
        private readonly AuditWriter $auditWriter = new AuditWriter,
        private readonly OutboxWriter $outboxWriter = new OutboxWriter,
    ) {}

    /**
     * @param  array{name: string, notes?: ?string, effect_key?: string}  $attributes
     * @return array{record: AuditHarnessRecord, event_id: string}
     */
    public function createWithOutbox(array $attributes, ?string $correlationId = null): array
    {
        return $this->transaction->run(function () use ($attributes, $correlationId) {
            $record = AuditHarnessRecord::query()->create([
                'name' => $attributes['name'],
                'notes' => $attributes['notes'] ?? null,
                'status' => 'active',
            ]);

            $this->auditWriter->write(
                action: AuditAction::Created,
                subjectType: AuditHarnessRecord::class,
                subjectId: $record->id,
                after: $record->attributesToArray(),
                correlationId: $correlationId,
            );

            $message = $this->outboxWriter->enqueue(
                eventType: 'outbox.harness.effect',
                payload: [
                    'subject_id' => $record->id,
                    'effect_key' => $attributes['effect_key'] ?? 'created',
                ],
                correlationId: $correlationId,
            );

            return [
                'record' => $record,
                'event_id' => $message->event_id,
            ];
        });
    }

    /**
     * Mutate + audit + outbox then force failure (full rollback).
     *
     * @param  array{name: string}  $attributes
     */
    public function createThenFailAfterOutbox(array $attributes): void
    {
        $this->transaction->run(function () use ($attributes): void {
            $record = AuditHarnessRecord::query()->create([
                'name' => $attributes['name'],
                'status' => 'active',
            ]);

            $this->auditWriter->write(
                action: AuditAction::Created,
                subjectType: AuditHarnessRecord::class,
                subjectId: $record->id,
                after: $record->attributesToArray(),
            );

            $this->outboxWriter->enqueue(
                eventType: 'outbox.harness.effect',
                payload: ['subject_id' => $record->id, 'effect_key' => 'fail-path'],
            );

            throw new RuntimeException('Forced failure after outbox insert.');
        });
    }

    /**
     * Mutate + audit then fail before outbox.
     *
     * @param  array{name: string}  $attributes
     */
    public function createThenFailBeforeOutbox(array $attributes): void
    {
        $this->transaction->run(function () use ($attributes): void {
            $record = AuditHarnessRecord::query()->create([
                'name' => $attributes['name'],
                'status' => 'active',
            ]);

            $this->auditWriter->write(
                action: AuditAction::Created,
                subjectType: AuditHarnessRecord::class,
                subjectId: $record->id,
                after: $record->attributesToArray(),
            );

            throw new RuntimeException('Forced failure before outbox insert.');
        });
    }
}
