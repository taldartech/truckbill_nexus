<?php

namespace App\Domains\Consignment;

use App\Domains\Broker\Enums\BrokerInvoiceStatus;
use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceStatus;
use App\Models\BrokerInvoiceLine;
use App\Models\LorryHire;
use App\Models\LorryReceipt;
use App\Models\Trip;
use App\Models\TripInvoiceLine;
use Illuminate\Validation\ValidationException;

/**
 * Freezes LR financial fields when downstream Trip / Hire / Invoice depends on them.
 *
 * Does not invent amendment workflows — rejects mutating financial snapshots.
 * Trip completion does not require LR Delivered (Rules §9); Pending LR may still
 * be locked financially once a Trip exists.
 */
final class LrFinancialImmutabilityService
{
    /**
     * @param  array<string, mixed>  $normalized
     */
    public function assertFinancialMutationAllowed(LorryReceipt $lorryReceipt, array $normalized): void
    {
        $reasons = $this->dependencyReasons($lorryReceipt);
        if ($reasons === []) {
            return;
        }

        if (! $this->financialFieldsChanged($lorryReceipt, $normalized)) {
            return;
        }

        throw ValidationException::withMessages([
            'freight_total' => 'Financial fields are locked because '.$reasons[0]
                .' Edit route, stations, parties, or e-way bills only — or cancel the downstream record first.',
        ]);
    }

    /**
     * @return list<string>
     */
    public function dependencyReasons(LorryReceipt $lorryReceipt): array
    {
        $reasons = [];

        $tripActive = Trip::query()
            ->where('company_id', $lorryReceipt->company_id)
            ->where('lorry_receipt_id', $lorryReceipt->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', TripStatus::Cancelled->value)
            ->exists();

        if ($tripActive) {
            $reasons[] = 'an OwnFleet Trip exists.';
        }

        $hireActive = LorryHire::query()
            ->where('company_id', $lorryReceipt->company_id)
            ->where('lorry_receipt_id', $lorryReceipt->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', LorryHireStatus::Cancelled->value)
            ->exists();

        if ($hireActive) {
            $reasons[] = 'a Broker Lorry Hire exists.';
        }

        $tripInvoiced = TripInvoiceLine::query()
            ->where('lorry_receipt_id', $lorryReceipt->id)
            ->whereHas('invoice', function ($query) use ($lorryReceipt): void {
                $query->where('company_id', $lorryReceipt->company_id)
                    ->whereNull('deleted_at')
                    ->where('status', '!=', TripInvoiceStatus::Cancelled->value);
            })
            ->exists();

        if ($tripInvoiced) {
            $reasons[] = 'an OwnFleet customer invoice includes this LR.';
        }

        $brokerInvoiced = BrokerInvoiceLine::query()
            ->where('lorry_receipt_id', $lorryReceipt->id)
            ->whereHas('brokerInvoice', function ($query) use ($lorryReceipt): void {
                $query->where('company_id', $lorryReceipt->company_id)
                    ->whereNull('deleted_at')
                    ->where('status', '!=', BrokerInvoiceStatus::Cancelled->value);
            })
            ->exists();

        if ($brokerInvoiced) {
            $reasons[] = 'a Broker customer invoice includes this LR.';
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function financialFieldsChanged(LorryReceipt $lorryReceipt, array $normalized): bool
    {
        if ((string) ($normalized['billing_customer_party_id'] ?? '') !== (string) ($lorryReceipt->billing_customer_party_id ?? '')) {
            return true;
        }

        if ($this->decimalChanged((string) $normalized['goods_value'], (string) $lorryReceipt->goods_value)) {
            return true;
        }

        if ($this->decimalChanged((string) $normalized['freight_total'], (string) $lorryReceipt->freight_total)) {
            return true;
        }

        if ($this->decimalChanged((string) $normalized['additional_charges_total'], (string) $lorryReceipt->additional_charges_total)) {
            return true;
        }

        $existingGoods = $lorryReceipt->relationLoaded('goodsRows')
            ? $lorryReceipt->goodsRows
            : $lorryReceipt->goodsRows()->orderBy('position')->get();

        $incomingGoods = $normalized['goods'] ?? [];
        if (count($incomingGoods) !== $existingGoods->count()) {
            return true;
        }

        foreach ($existingGoods->values() as $index => $row) {
            $incoming = $incomingGoods[$index] ?? null;
            if (! is_array($incoming)) {
                return true;
            }

            $incomingRateType = $incoming['freight_rate_type'] instanceof \BackedEnum
                ? $incoming['freight_rate_type']->value
                : (string) ($incoming['freight_rate_type'] ?? '');
            $existingRateType = $row->freight_rate_type->value;

            if (
                (string) ($incoming['goods_description'] ?? '') !== (string) $row->goods_description
                || $this->decimalChanged((string) ($incoming['freight_amount'] ?? '0'), (string) $row->freight_amount)
                || (string) ($incoming['charged_weight'] ?? '') !== (string) ($row->charged_weight ?? '')
                || (string) ($incoming['actual_weight'] ?? '') !== (string) ($row->actual_weight ?? '')
                || (string) ($incoming['package_count'] ?? '') !== (string) ($row->package_count ?? '')
                || (string) ($incoming['freight_rate'] ?? '') !== (string) ($row->freight_rate ?? '')
                || $incomingRateType !== $existingRateType
            ) {
                return true;
            }
        }

        return false;
    }

    private function decimalChanged(string $left, string $right): bool
    {
        if (! is_numeric($left) || ! is_numeric($right)) {
            return $left !== $right;
        }

        return bccomp($left, $right, 2) !== 0;
    }
}
