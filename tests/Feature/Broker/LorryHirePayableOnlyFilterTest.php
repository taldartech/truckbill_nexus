<?php

use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Models\BankCashAccount;
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
    brokerGrantVoucherPermissions();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('payable only excludes hires whose outstanding is fully reserved by pending vouchers', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $hire = $hire->fresh(['vouchers']);

    $summary = app(LorryHireCalculationService::class)->summarize($hire);
    expect($summary['remaining_payable'])->not->toBe('0.00');

    $account = BankCashAccount::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'account_kind' => 'bank',
        'name' => 'Reserve Bank',
        'bank_name' => 'HDFC',
        'account_number' => '123456789099',
        'ifsc' => 'HDFC0001299',
        'is_active' => true,
    ]);

    app(TransporterVoucherService::class)->createForLorryHire($hire, $ctx['member'], [
        'amount' => $summary['remaining_payable'],
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::Bank->value,
        'bank_cash_account_id' => $account->id,
    ]);

    $hire = $hire->fresh(['vouchers']);
    $afterReserve = app(LorryHireCalculationService::class)->summarize($hire);
    expect($afterReserve['remaining_payable'])->toBe('0.00');

    $service = app(LorryHireService::class);
    $payablePage = $service->search(
        company: $ctx['company'],
        branch: $ctx['branch'],
        payableOnly: true,
        perPage: 50,
    );

    $ids = collect($payablePage->items())->pluck('id');
    expect($ids)->not->toContain($hire->id);
});

test('payable only includes hires with remaining payable after partial voucher reservation', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);

    $summary = app(LorryHireCalculationService::class)->summarize($hire->fresh(['vouchers']));
    $remaining = (float) $summary['remaining_payable'];
    expect($remaining)->toBeGreaterThan(100);

    $account = BankCashAccount::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'account_kind' => 'cash',
        'name' => 'Cash desk',
        'is_active' => true,
    ]);

    app(TransporterVoucherService::class)->createForLorryHire($hire, $ctx['member'], [
        'amount' => '100.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
        'bank_cash_account_id' => $account->id,
    ]);

    expect($hire->fresh(['vouchers'])->vouchers)->not->toBeEmpty();

    $service = app(LorryHireService::class);
    $payablePage = $service->search(
        company: $ctx['company'],
        branch: $ctx['branch'],
        payableOnly: true,
        perPage: 50,
    );

    expect(collect($payablePage->items())->pluck('id'))->toContain($hire->id);
});
