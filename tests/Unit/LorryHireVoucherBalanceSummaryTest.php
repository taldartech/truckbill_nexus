<?php

use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Models\TransporterVoucher;
use Illuminate\Support\Collection;

test('in progress and paid voucher amounts sum independently', function () {
    $calc = app(LorryHireCalculationService::class);

    /** @var Collection<int, TransporterVoucher> $vouchers */
    $vouchers = new Collection([
        new TransporterVoucher(['amount' => '20000.00', 'status' => VoucherStatus::Paid]),
        new TransporterVoucher(['amount' => '10000.00', 'status' => VoucherStatus::InProgress]),
        new TransporterVoucher(['amount' => '5000.00', 'status' => VoucherStatus::Pending]),
    ]);

    expect($calc->paidVoucherAmount($vouchers))->toBe('20000.00')
        ->and($calc->inProgressVoucherAmount($vouchers))->toBe('10000.00')
        ->and($calc->pendingVoucherAmount($vouchers))->toBe('5000.00')
        ->and($calc->reservedVoucherAmount($vouchers))->toBe('15000.00');
});
