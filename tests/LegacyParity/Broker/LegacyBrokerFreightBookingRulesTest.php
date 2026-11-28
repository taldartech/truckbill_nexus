<?php

/**
 * Legacy reference: legacy/truckbill_broker/tests/Unit/Models/FreightBookingTest.php
 */

use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Broker\LorryHire\LorryHireRevisionService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Domains\Numbering\DocumentNumberAllocator;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
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
    brokerGrantLorryHirePermissions();
    brokerGrantVoucherPermissions();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    TenantContextHolder::clear();
});

test('LEG-BRO-00003 legacy allocate next voucher number starts at one when empty', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $allocator = app(DocumentNumberAllocator::class);
    $allocation = $allocator->allocateNext(
        $ctx['company']->id,
        DocumentNumberType::BrokerVoucher,
        DocumentNumberScopeType::Company,
    );

    expect($allocation['number'])->toBe('1');
});

test('LEG-BRO-00004 legacy allocate next voucher number includes soft deleted rows', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $vouchers = app(TransporterVoucherService::class);

    $first = $vouchers->createForLorryHire($hire, $ctx['member'], [
        'amount' => '100.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
    ]);
    $first->delete();

    $second = $vouchers->createForLorryHire($hire->fresh(), $ctx['member'], [
        'amount' => '100.00',
        'payment_type' => VoucherPaymentType::Partial->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
    ]);

    expect((int) $first->voucher_number)->toBeLessThan((int) $second->voucher_number);
});

test('LEG-BRO-00005 legacy allocate next voucher number uses current max', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $allocator = app(DocumentNumberAllocator::class);

    $ctx['company']->refresh();
    $first = $allocator->allocateNext(
        $ctx['company']->id,
        DocumentNumberType::BrokerVoucher,
        DocumentNumberScopeType::Company,
    );
    $second = $allocator->allocateNext(
        $ctx['company']->id,
        DocumentNumberType::BrokerVoucher,
        DocumentNumberScopeType::Company,
    );

    expect((int) $second['number'])->toBe((int) $first['number'] + 1);
});

test('LEG-BRO-00006 legacy can change tds returns false when payment complete', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $calc = app(LorryHireCalculationService::class);
    $revs = app(LorryHireRevisionService::class);

    $hire->forceFill(['outstanding_amount' => '0.00'])->save();

    expect($revs->tdsLockedReason($hire->fresh(), $ctx['company'], '0.00'))->toContain('fully settled');
});

test('LEG-BRO-00007 legacy can change tds returns false when balance near zero', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $revs = app(LorryHireRevisionService::class);

    expect($revs->tdsLockedReason($hire, $ctx['company'], '0.01'))->toContain('fully settled');
});

test('LEG-BRO-00008 legacy can change tds returns true before submission date', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-02-01 12:00:00', 'Asia/Kolkata'));

    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $hire->lorryReceipt?->forceFill(['lr_date' => '2025-01-15'])->save();
    $hire = $hire->fresh(['lorryReceipt']);
    $revs = app(LorryHireRevisionService::class);
    $summary = app(LorryHireCalculationService::class)->summarize($hire);

    expect($revs->tdsLockedReason($hire, $ctx['company'], $summary['outstanding_amount']))->toBeNull();
});

test('LEG-BRO-00009 legacy can change tds returns false after submission date', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-02-05 12:00:00', 'Asia/Kolkata'));

    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $hire->lorryReceipt?->forceFill(['lr_date' => '2025-01-15'])->save();
    $hire = $hire->fresh(['lorryReceipt']);
    $revs = app(LorryHireRevisionService::class);
    $summary = app(LorryHireCalculationService::class)->summarize($hire);

    expect($revs->tdsLockedReason($hire, $ctx['company'], $summary['outstanding_amount']))
        ->toContain('TDS submission date');
});

test('LEG-BRO-00010 legacy can change tds returns true on submission day', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-02-02 23:59:59', 'Asia/Kolkata'));

    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $hire->lorryReceipt?->forceFill(['lr_date' => '2025-01-15'])->save();
    $hire = $hire->fresh(['lorryReceipt']);
    $revs = app(LorryHireRevisionService::class);
    $summary = app(LorryHireCalculationService::class)->summarize($hire);

    expect($revs->tdsLockedReason($hire, $ctx['company'], $summary['outstanding_amount']))->toBeNull();
});

test('LEG-BRO-00011 legacy balance as on date excludes payments after cutoff', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $vouchers = app(TransporterVoucherService::class);
    $calc = app(LorryHireCalculationService::class);

    $first = $vouchers->createForLorryHire($hire, $ctx['member'], [
        'amount' => '300.00',
        'payment_type' => VoucherPaymentType::Partial->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
        'voucher_date' => '2025-01-20',
    ]);
    $vouchers->transitionStatus($first, $ctx['member'], VoucherStatus::Paid);

    $second = $vouchers->createForLorryHire($hire->fresh(), $ctx['member'], [
        'amount' => '200.00',
        'payment_type' => VoucherPaymentType::Partial->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
        'voucher_date' => '2025-02-10',
    ]);
    $vouchers->transitionStatus($second, $ctx['member'], VoucherStatus::Paid);

    $hire = $hire->fresh(['vouchers']);
    $summaryJan = $calc->dispatchPaymentSummary($hire, '2025-01-31');
    $summaryFeb = $calc->dispatchPaymentSummary($hire, '2025-02-28');

    expect($summaryJan['cash'])->toBe('300.00')
        ->and($summaryJan['total_paid'])->toBe('300.00')
        ->and($summaryJan['balance'])->toBe(bcsub($calc->summarize($hire)['net_payable'], '300.00', 2))
        ->and($summaryFeb['cash'])->toBe('500.00')
        ->and($summaryFeb['total_paid'])->toBe('500.00');
});
