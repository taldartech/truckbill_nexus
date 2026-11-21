<?php

namespace App\Support\Audit\Harness;

use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use RuntimeException;

/**
 * Narrow foundation harness exercising create/update/delete/cancel + audit atomically.
 */
class AuditHarnessService
{
    public function __construct(
        private readonly AuditedTransaction $transaction = new AuditedTransaction,
        private readonly AuditWriter $auditWriter = new AuditWriter,
    ) {}

    /**
     * @param  array{name: string, notes?: ?string, secret_token?: ?string}  $attributes
     */
    public function create(array $attributes): AuditHarnessRecord
    {
        return $this->transaction->run(function () use ($attributes) {
            $record = AuditHarnessRecord::query()->create([
                'name' => $attributes['name'],
                'notes' => $attributes['notes'] ?? null,
                'secret_token' => $attributes['secret_token'] ?? null,
                'status' => 'active',
            ]);

            $this->auditWriter->write(
                action: AuditAction::Created,
                subjectType: AuditHarnessRecord::class,
                subjectId: $record->id,
                before: null,
                after: $record->attributesToArray(),
            );

            return $record;
        });
    }

    /**
     * @param  array{name?: string, notes?: ?string, secret_token?: ?string}  $attributes
     */
    public function update(AuditHarnessRecord $record, array $attributes, string $remark): AuditHarnessRecord
    {
        return $this->transaction->run(function () use ($record, $attributes, $remark) {
            $before = $record->attributesToArray();
            $record->fill($attributes);
            $record->save();

            $this->auditWriter->write(
                action: AuditAction::Updated,
                subjectType: AuditHarnessRecord::class,
                subjectId: $record->id,
                remark: $remark,
                before: $before,
                after: $record->attributesToArray(),
            );

            return $record->refresh();
        });
    }

    public function delete(AuditHarnessRecord $record, string $remark): void
    {
        $this->transaction->run(function () use ($record, $remark) {
            $before = $record->attributesToArray();
            $subjectId = $record->id;

            $this->auditWriter->write(
                action: AuditAction::Deleted,
                subjectType: AuditHarnessRecord::class,
                subjectId: $subjectId,
                remark: $remark,
                before: $before,
                after: null,
            );

            $record->delete();
        });
    }

    public function cancel(AuditHarnessRecord $record, string $remark, string $cancellationReason, mixed $cancellationAt): AuditHarnessRecord
    {
        return $this->transaction->run(function () use ($record, $remark, $cancellationReason, $cancellationAt) {
            $before = $record->attributesToArray();
            $record->status = 'cancelled';
            $record->save();

            $this->auditWriter->write(
                action: AuditAction::Cancelled,
                subjectType: AuditHarnessRecord::class,
                subjectId: $record->id,
                remark: $remark,
                before: $before,
                after: $record->attributesToArray(),
                cancellationReason: $cancellationReason,
                cancellationAt: $cancellationAt,
            );

            return $record->refresh();
        });
    }

    /**
     * Test helper: mutate then force audit writer failure inside the same transaction.
     */
    public function updateThenFailAudit(AuditHarnessRecord $record, string $remark): void
    {
        $this->transaction->run(function () use ($record, $remark) {
            $before = $record->attributesToArray();
            $record->name = $record->name.'-mutated';
            $record->save();

            $this->auditWriter->write(
                action: AuditAction::Updated,
                subjectType: AuditHarnessRecord::class,
                subjectId: $record->id,
                remark: $remark,
                before: $before,
                after: $record->attributesToArray(),
            );

            throw new RuntimeException('Forced audit-path failure after mutation.');
        });
    }
}
