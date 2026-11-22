<?php

namespace App\Domains\Numbering;

use App\Domains\Numbering\Enums\DocumentNumberAllocationMode;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Domains\Numbering\Exceptions\DocumentNumberConflictException;
use App\Domains\Numbering\Exceptions\DocumentNumberValidationException;
use App\Models\DocumentNumberAdjustment;
use App\Models\DocumentNumberAllocation;
use App\Models\DocumentNumberSequence;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Concurrency-safe document number allocation primitives (Batch A / Batch E workflows).
 */
final class DocumentNumberAllocator
{
    /**
     * Allocate the next auto number for the given scope.
     *
     * @return array{number: string, allocation: DocumentNumberAllocation, sequence: DocumentNumberSequence}
     */
    public function allocateNext(
        string $companyId,
        DocumentNumberType $documentType,
        DocumentNumberScopeType $scopeType,
        ?string $branchId = null,
        ?string $allocatedToId = null,
        ?string $allocatedToType = null,
    ): array {
        $branchId = $this->normalizeBranchId($scopeType, $branchId);

        return DB::transaction(function () use ($companyId, $documentType, $scopeType, $branchId, $allocatedToId, $allocatedToType): array {
            $sequence = $this->lockSequence($companyId, $documentType, $scopeType, $branchId);
            $number = (string) $sequence->next_value;
            $sequence->forceFill(['next_value' => $sequence->next_value + 1])->save();

            $allocation = $this->persistAllocation(
                companyId: $companyId,
                documentType: $documentType,
                scopeType: $scopeType,
                branchId: $branchId,
                number: $number,
                mode: DocumentNumberAllocationMode::Auto,
                allocatedToId: $allocatedToId,
                allocatedToType: $allocatedToType,
            );

            return [
                'number' => $number,
                'allocation' => $allocation,
                'sequence' => $sequence->fresh(),
            ];
        });
    }

    /**
     * Reserve a manual alphanumeric number under active uniqueness rules.
     */
    public function allocateManual(
        string $companyId,
        DocumentNumberType $documentType,
        DocumentNumberScopeType $scopeType,
        string $number,
        ?string $branchId = null,
        ?string $allocatedToId = null,
        ?string $allocatedToType = null,
    ): DocumentNumberAllocation {
        $number = trim($number);
        if ($number === '') {
            throw new DocumentNumberValidationException('Manual number is required.');
        }

        $branchId = $this->normalizeBranchId($scopeType, $branchId);

        return DB::transaction(function () use ($companyId, $documentType, $scopeType, $branchId, $number, $allocatedToId, $allocatedToType): DocumentNumberAllocation {
            return $this->persistAllocation(
                companyId: $companyId,
                documentType: $documentType,
                scopeType: $scopeType,
                branchId: $branchId,
                number: $number,
                mode: DocumentNumberAllocationMode::Manual,
                allocatedToId: $allocatedToId,
                allocatedToType: $allocatedToType,
            );
        });
    }

    public function adjustSequence(
        DocumentNumberSequence $sequence,
        int $newNextValue,
        User $actor,
        string $remark,
    ): DocumentNumberAdjustment {
        if ($newNextValue < 1) {
            throw new DocumentNumberValidationException('Sequence next value must be >= 1.');
        }

        $remark = trim($remark);
        if ($remark === '') {
            throw new DocumentNumberValidationException('Sequence adjustment requires a remark.');
        }

        return DB::transaction(function () use ($sequence, $newNextValue, $actor, $remark): DocumentNumberAdjustment {
            /** @var DocumentNumberSequence $locked */
            $locked = DocumentNumberSequence::query()
                ->whereKey($sequence->id)
                ->lockForUpdate()
                ->firstOrFail();

            $previous = (int) $locked->next_value;
            $locked->forceFill(['next_value' => $newNextValue])->save();

            $adjustment = new DocumentNumberAdjustment;
            $adjustment->forceFill([
                'id' => (string) Str::uuid(),
                'company_id' => $locked->company_id,
                'sequence_id' => $locked->id,
                'previous_next_value' => $previous,
                'new_next_value' => $newNextValue,
                'actor_user_id' => $actor->id,
                'remark' => $remark,
                'adjusted_at' => now(),
            ]);
            $adjustment->save();

            return $adjustment;
        });
    }

    private function normalizeBranchId(DocumentNumberScopeType $scopeType, ?string $branchId): ?string
    {
        if ($scopeType === DocumentNumberScopeType::Branch && $branchId === null) {
            throw new DocumentNumberValidationException('Branch scope requires branch_id.');
        }

        if ($scopeType === DocumentNumberScopeType::Company) {
            return null;
        }

        return $branchId;
    }

    private function lockSequence(
        string $companyId,
        DocumentNumberType $documentType,
        DocumentNumberScopeType $scopeType,
        ?string $branchId,
    ): DocumentNumberSequence {
        $query = DocumentNumberSequence::query()
            ->where('company_id', $companyId)
            ->where('document_type', $documentType->value)
            ->where('scope_type', $scopeType->value);

        if ($branchId === null) {
            $query->whereNull('branch_id');
        } else {
            $query->where('branch_id', $branchId);
        }

        /** @var DocumentNumberSequence|null $existing */
        $existing = $query->lockForUpdate()->first();
        if ($existing !== null) {
            return $existing;
        }

        $sequence = new DocumentNumberSequence;
        $sequence->forceFill([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'document_type' => $documentType,
            'scope_type' => $scopeType,
            'branch_id' => $branchId,
            'next_value' => 1,
        ]);

        try {
            $sequence->save();
        } catch (UniqueConstraintViolationException) {
            /** @var DocumentNumberSequence $raced */
            $raced = $query->lockForUpdate()->firstOrFail();

            return $raced;
        }

        /** @var DocumentNumberSequence $locked */
        $locked = DocumentNumberSequence::query()
            ->whereKey($sequence->id)
            ->lockForUpdate()
            ->firstOrFail();

        return $locked;
    }

    private function persistAllocation(
        string $companyId,
        DocumentNumberType $documentType,
        DocumentNumberScopeType $scopeType,
        ?string $branchId,
        string $number,
        DocumentNumberAllocationMode $mode,
        ?string $allocatedToId,
        ?string $allocatedToType,
    ): DocumentNumberAllocation {
        $allocation = new DocumentNumberAllocation;
        $allocation->forceFill([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'document_type' => $documentType,
            'scope_type' => $scopeType,
            'branch_id' => $branchId,
            'number' => $number,
            'allocation_mode' => $mode,
            'allocated_to_id' => $allocatedToId,
            'allocated_to_type' => $allocatedToType,
        ]);

        try {
            $allocation->save();
        } catch (UniqueConstraintViolationException $e) {
            throw new DocumentNumberConflictException(
                'Document number is already allocated in this scope.',
                previous: $e,
            );
        }

        return $allocation;
    }
}
