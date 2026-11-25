<?php

use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Accounting\OperationalAccountingPoster;
use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\Invoice\BrokerInvoiceService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Models\AccountingPostingLink;
use App\Models\AccountingVoucher;
use App\Models\BrokerInvoice;
use App\Models\LorryHire;
use App\Models\TransporterVoucher;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../../Support/BrokerLorryHireHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('lorry hire recognition posts transporter expense and payable', function () {
    $ctx = brokerWorkspace();
    $hire = brokerCreateLorryHire($ctx);

    $link = AccountingPostingLink::query()
        ->where('company_id', $ctx['company']->id)
        ->where('source_type', LorryHire::class)
        ->where('source_id', $hire->id)
        ->where('posting_purpose', 'hire_recognized')
        ->first();

    expect($link)->not->toBeNull();

    $voucher = AccountingVoucher::query()->findOrFail($link->accounting_voucher_id);
    expect($voucher->voucher_type)->toBe(AccountingVoucherType::Purchase)
        ->and((string) $voucher->total_debit)->toBe((string) $hire->freight_total)
        ->and((string) $voucher->total_credit)->toBe((string) $hire->freight_total);
});

test('broker invoice issue and payment post sales and receipt vouchers', function () {
    $ctx = brokerWorkspace();
    brokerGrantInvoicePermissions();
    brokerGrantDeliveryAdjustmentPermissions();

    $hire = brokerCreateLorryHire($ctx);
    $lr = brokerDeliverLr($hire->lorryReceipt()->firstOrFail(), $ctx, 'MEMO-ACC-1');

    $invoice = app(BrokerInvoiceService::class)->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$lr->id],
        'billing_customer_party_id' => $lr->billing_customer_party_id,
        'invoice_date' => now()->toDateString(),
    ]);

    $invoiceLink = AccountingPostingLink::query()
        ->where('source_type', BrokerInvoice::class)
        ->where('source_id', $invoice->id)
        ->where('posting_purpose', 'invoice_issued')
        ->first();

    expect($invoiceLink)->not->toBeNull();
    $sales = AccountingVoucher::query()->findOrFail($invoiceLink->accounting_voucher_id);
    expect($sales->voucher_type)->toBe(AccountingVoucherType::Sales)
        ->and((string) $sales->total_debit)->toBe((string) $invoice->invoice_total);

    $payment = app(BrokerInvoiceService::class)->recordPayment($invoice, $ctx['member'], [
        'amount_received' => $invoice->invoice_total,
        'payment_mode' => 'cash',
        'payment_date' => now()->toDateString(),
    ]);

    $paymentLink = AccountingPostingLink::query()
        ->where('source_type', $payment::class)
        ->where('source_id', $payment->id)
        ->where('posting_purpose', 'invoice_payment')
        ->first();

    expect($paymentLink)->not->toBeNull();
    $receipt = AccountingVoucher::query()->findOrFail($paymentLink->accounting_voucher_id);
    expect($receipt->voucher_type)->toBe(AccountingVoucherType::Receipt)
        ->and((string) $receipt->total_debit)->toBe((string) $invoice->invoice_total);

    app(OperationalAccountingPoster::class)->postBrokerInvoiceIssued($invoice->fresh(), $ctx['member']);
    expect(
        AccountingPostingLink::query()
            ->where('source_type', BrokerInvoice::class)
            ->where('source_id', $invoice->id)
            ->where('posting_purpose', 'invoice_issued')
            ->count(),
    )->toBe(1);
});

test('paid transporter voucher posts payment against transporter payable', function () {
    $ctx = brokerWorkspace();
    brokerGrantVoucherPermissions();
    $hire = brokerCreateLorryHire($ctx);

    $voucher = app(TransporterVoucherService::class)->createForLorryHire($hire, $ctx['member'], [
        'amount' => '1000.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
        'voucher_date' => now()->toDateString(),
    ]);

    expect($voucher->status)->toBe(VoucherStatus::InProgress);

    $paid = app(TransporterVoucherService::class)->transitionStatus(
        $voucher,
        $ctx['member'],
        VoucherStatus::Paid,
    );

    expect($paid->status)->toBe(VoucherStatus::Paid);

    $link = AccountingPostingLink::query()
        ->where('source_type', TransporterVoucher::class)
        ->where('source_id', $paid->id)
        ->where('posting_purpose', 'transporter_voucher_paid')
        ->first();

    expect($link)->not->toBeNull();
    $payment = AccountingVoucher::query()->findOrFail($link->accounting_voucher_id);
    expect($payment->voucher_type)->toBe(AccountingVoucherType::Payment)
        ->and((string) $payment->total_debit)->toBe('1000.00')
        ->and((string) $payment->total_credit)->toBe('1000.00');

    app(OperationalAccountingPoster::class)->postTransporterVoucherPaid($paid->fresh(), $ctx['member']);
    expect(
        AccountingPostingLink::query()
            ->where('source_type', TransporterVoucher::class)
            ->where('source_id', $paid->id)
            ->where('posting_purpose', 'transporter_voucher_paid')
            ->count(),
    )->toBe(1);
});
