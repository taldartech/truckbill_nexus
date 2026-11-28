<?php

namespace App\Domains\Broker\Presentation;

use App\Domains\Broker\Enums\VoucherStatus;
use App\Models\LorryReceipt;
use App\Models\TransporterVoucher;
use App\Support\Presentation\FreightAmountPresentation;

/**
 * Voucher register row — legacy voucher_payments/index column contract.
 */
final class TransporterVoucherRegisterPresenter
{
    public function __construct(
        private readonly FreightAmountPresentation $freightPresentation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function row(TransporterVoucher $voucher, bool $maySeeFreight): array
    {
        $hire = $voucher->lorryHire;
        $receipt = $hire?->lorryReceipt;

        return [
            'id' => $voucher->id,
            'voucher_number' => $voucher->voucher_number,
            'voucher_date' => $voucher->voucher_date?->format('d-m-Y'),
            'status' => $voucher->status->value,
            'payment_mode' => $voucher->payment_mode?->value,
            'payment_type' => $voucher->payment_type->value,
            'amount' => $this->freightPresentation->presentAmount($voucher->amount, $maySeeFreight),
            'hire_number' => $hire?->hire_number,
            'lr_number' => $receipt?->lr_number,
            'billing_customer_name' => $receipt?->billingCustomer?->name,
            'transporter_name' => $hire?->transporter?->name,
            'vehicle_registration' => $hire?->vehicle?->registration_number,
            'source' => $receipt !== null ? $this->loadingStationLabel($receipt) : null,
            'destination' => $receipt !== null ? $this->unloadingStationLabel($receipt) : null,
            'created_at' => $voucher->created_at?->format('d-m-Y H:i'),
            'next_status' => $this->nextStatus($voucher->status),
            'urls' => [
                'show' => route('branch.broker.vouchers.show', [$voucher], absolute: false),
                'status' => route('branch.broker.vouchers.status', [$voucher], absolute: false),
            ],
        ];
    }

    private function nextStatus(VoucherStatus $current): ?string
    {
        return match ($current) {
            VoucherStatus::Pending => VoucherStatus::InProgress->value,
            VoucherStatus::InProgress => VoucherStatus::Paid->value,
            VoucherStatus::Paid => null,
        };
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
