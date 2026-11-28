<?php

namespace App\Domains\Broker\LorryHire;

use App\Models\LorryHire;
use App\Models\LorryHireCustomAmount;
use Illuminate\Validation\ValidationException;

/**
 * Legacy Freight Booking Custom Amount rows — signed payable / recoverable adjustments at hire level.
 */
final class LorryHireCustomAmountService
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return numeric-string Signed total of synced rows.
     */
    public function sync(LorryHire $hire, array $rows): string
    {
        $companyId = (string) $hire->company_id;
        $existingIds = $hire->customAmounts()->pluck('id')->all();
        $keptIds = [];

        foreach ($rows as $index => $row) {
            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '') {
                throw ValidationException::withMessages([
                    "custom_amounts.{$index}.description" => 'Enter particulars for this Custom Amount.',
                ]);
            }

            $amountRaw = $row['amount'] ?? null;
            if ($amountRaw === null || $amountRaw === '' || ! is_numeric($amountRaw)) {
                throw ValidationException::withMessages([
                    "custom_amounts.{$index}.amount" => 'Enter the Custom Amount (+ additional payable, − recoverable).',
                ]);
            }

            $amount = number_format((float) $amountRaw, 2, '.', '');
            if (bccomp($amount, '0.00', 2) === 0) {
                throw ValidationException::withMessages([
                    "custom_amounts.{$index}.amount" => 'Custom Amount cannot be zero.',
                ]);
            }

            $payload = [
                'description' => $description,
                'amount' => $amount,
                'company_id' => $companyId,
            ];

            $rowId = filled($row['id'] ?? null) ? (string) $row['id'] : null;
            if ($rowId !== null) {
                $customAmount = $hire->customAmounts()->whereKey($rowId)->firstOrFail();
                $customAmount->update($payload);
                $keptIds[] = $customAmount->id;
            } else {
                $created = $hire->customAmounts()->create($payload);
                $keptIds[] = $created->id;
            }
        }

        $idsToDelete = array_diff($existingIds, $keptIds);
        if ($idsToDelete !== []) {
            LorryHireCustomAmount::query()
                ->where('company_id', $companyId)
                ->where('lorry_hire_id', $hire->id)
                ->whereIn('id', $idsToDelete)
                ->delete();
        }

        $hire->unsetRelation('customAmounts');

        return $this->total($hire);
    }

    /**
     * @return numeric-string
     */
    public function total(LorryHire $hire): string
    {
        if ($hire->relationLoaded('customAmounts')) {
            $sum = '0.00';
            foreach ($hire->customAmounts as $row) {
                $sum = bcadd($sum, $this->money($row->amount), 2);
            }

            return $sum;
        }

        $aggregate = LorryHireCustomAmount::query()
            ->where('company_id', $hire->company_id)
            ->where('lorry_hire_id', $hire->id)
            ->sum('amount');

        return $this->money($aggregate);
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
