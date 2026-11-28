<?php

namespace App\Domains\Broker\Presentation;

use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Consignment\Enums\LrStatus;
use App\Models\LorryHire;
use App\Models\LorryReceipt;
use App\Support\Presentation\FreightAmountPresentation;

/**
 * Good Hires register row — legacy freight_bookings/index column contract.
 */
final class LorryHireRegisterPresenter
{
    public function __construct(
        private readonly FreightAmountPresentation $freightPresentation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function row(
        LorryHire $hire,
        bool $maySeeFreight,
        bool $canCreateVoucher,
        bool $canAssignMemo,
        bool $canEdit,
    ): array {
        $weightKg = $hire->goodsLines->sum(fn ($line): float => (float) ($line->loading_weight ?? 0));
        $primaryLine = $hire->goodsLines->first();
        $outstanding = number_format((float) ($hire->outstanding_amount ?? 0), 2, '.', '');
        $payable = $canCreateVoucher
            && $hire->status !== LorryHireStatus::Cancelled
            && bccomp($outstanding, '0.00', 2) === 1;
        $receipt = $hire->lorryReceipt;
        $memoNumber = $receipt?->delivery?->memo_number;
        $memoPending = $canAssignMemo
            && $hire->status !== LorryHireStatus::Cancelled
            && $receipt?->status === LrStatus::InProgress
            && $memoNumber === null;

        $rowTone = match (true) {
            $hire->status === LorryHireStatus::Cancelled => 'cancelled',
            $memoNumber !== null => 'delivered',
            default => 'pending',
        };

        return [
            'id' => $hire->id,
            'row_tone' => $rowTone,
            'hire_number' => $hire->hire_number,
            'status' => $hire->status->value,
            'hire_date' => $hire->hire_date?->format('d-m-Y'),
            'lr_date' => $receipt?->lr_date?->format('d-m-Y'),
            'lr_number' => $receipt?->lr_number,
            'billing_customer_name' => $receipt?->billingCustomer?->name,
            'vehicle_registration' => $hire->vehicle?->registration_number,
            'transporter_name' => $hire->transporter?->name,
            'transporter_party_id' => $hire->transporter_party_id,
            'vehicle_id' => $hire->vehicle_id,
            'from_station_name' => $receipt !== null ? $this->loadingStationLabel($receipt) : null,
            'to_station_name' => $receipt !== null ? $this->unloadingStationLabel($receipt) : null,
            'weight_mt' => $weightKg > 0
                ? number_format($weightKg / 1000, 3, '.', '')
                : null,
            'rate' => $primaryLine !== null
                ? $this->freightPresentation->presentAmount($primaryLine->rate, $maySeeFreight)
                : null,
            'freight_total' => $this->freightPresentation->presentAmount($hire->freight_total, $maySeeFreight),
            'outstanding_amount' => $this->freightPresentation->presentAmount($hire->outstanding_amount, $maySeeFreight),
            'created_at' => $hire->created_at?->format('d-m-Y'),
            'payable' => $payable,
            'lorry_receipt_id' => $hire->lorry_receipt_id,
            'billing_customer_party_id' => $receipt?->billing_customer_party_id,
            'memo_number' => $memoNumber,
            'memo_pending' => $memoPending,
            'urls' => [
                'show' => route('branch.broker.lorry-hires.show', [$hire], absolute: false),
                'edit' => $canEdit && $hire->status !== LorryHireStatus::Cancelled
                    ? route('branch.broker.lorry-hires.edit', [$hire], absolute: false)
                    : null,
            ],
        ];
    }

    private function loadingStationLabel(LorryReceipt $lorryReceipt): ?string
    {
        return $lorryReceipt->fromStation !== null
            ? $lorryReceipt->fromStation->name
            : $lorryReceipt->from_text;
    }

    private function unloadingStationLabel(LorryReceipt $lorryReceipt): ?string
    {
        return $lorryReceipt->toStation !== null
            ? $lorryReceipt->toStation->name
            : $lorryReceipt->to_text;
    }
}
