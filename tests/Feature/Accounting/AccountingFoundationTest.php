<?php

use App\Domains\Accounting\AccountingPeriodService;
use App\Domains\Accounting\ChartOfAccountsBootstrapService;
use App\Domains\Accounting\Enums\AccountingVoucherStatus;
use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Accounting\Exceptions\AccountingDomainException;
use App\Domains\Accounting\VoucherPostingService;
use App\Models\GlLedger;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('chart of accounts bootstrap creates transport default ledgers idempotently', function () {
    $ctx = tripWorkspace(['broker'], ['accounting']);
    $bootstrap = app(ChartOfAccountsBootstrapService::class);

    $first = $bootstrap->ensureDefaults($ctx['company']);
    $second = $bootstrap->ensureDefaults($ctx['company']);

    expect($first['groups'])->toBeGreaterThan(0)
        ->and($first['ledgers'])->toBeGreaterThan(0)
        ->and($second['groups'])->toBe(0)
        ->and($second['ledgers'])->toBe(0)
        ->and(GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Freight Income')->exists())->toBeTrue()
        ->and(GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Transporter Payables')->exists())->toBeTrue();
});

test('posted voucher must balance and is immutable via reversal only', function () {
    $ctx = tripWorkspace(['broker'], ['accounting']);
    app(ChartOfAccountsBootstrapService::class)->ensureDefaults($ctx['company']);
    app(AccountingPeriodService::class)->ensureCurrentFinancialYear($ctx['company']);

    $cash = GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Cash')->firstOrFail();
    $capital = GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Capital')->firstOrFail();
    $posting = app(VoucherPostingService::class);

    expect(fn () => $posting->postManual($ctx['company'], $ctx['member'], [
        'voucher_type' => AccountingVoucherType::Journal,
        'voucher_date' => now()->toDateString(),
        'narration' => 'Unbalanced',
    ], [
        ['gl_ledger_id' => $cash->id, 'debit_amount' => '100.00', 'credit_amount' => 0],
        ['gl_ledger_id' => $capital->id, 'debit_amount' => 0, 'credit_amount' => '90.00'],
    ], $ctx['branch']))->toThrow(ValidationException::class);

    $voucher = $posting->postManual($ctx['company'], $ctx['member'], [
        'voucher_type' => AccountingVoucherType::Journal,
        'voucher_date' => now()->toDateString(),
        'narration' => 'Opening capital',
        'source_type' => 'test.opening',
        'source_id' => 'opening-1',
        'posting_purpose' => 'opening',
    ], [
        ['gl_ledger_id' => $cash->id, 'debit_amount' => '1000.00', 'credit_amount' => 0],
        ['gl_ledger_id' => $capital->id, 'debit_amount' => 0, 'credit_amount' => '1000.00'],
    ], $ctx['branch']);

    expect($voucher->status)->toBe(AccountingVoucherStatus::Posted)
        ->and($voucher->total_debit)->toBe('1000.00')
        ->and($voucher->total_credit)->toBe('1000.00')
        ->and($voucher->lines)->toHaveCount(2);

    $again = $posting->postManual($ctx['company'], $ctx['member'], [
        'voucher_type' => AccountingVoucherType::Journal,
        'voucher_date' => now()->toDateString(),
        'source_type' => 'test.opening',
        'source_id' => 'opening-1',
        'posting_purpose' => 'opening',
    ], [
        ['gl_ledger_id' => $cash->id, 'debit_amount' => '1000.00', 'credit_amount' => 0],
        ['gl_ledger_id' => $capital->id, 'debit_amount' => 0, 'credit_amount' => '1000.00'],
    ], $ctx['branch']);

    expect($again->id)->toBe($voucher->id);

    $reversal = $posting->reverse($voucher, $ctx['member'], 'Correct opening entry');
    expect($reversal->reversal_of_voucher_id)->toBe($voucher->id)
        ->and($voucher->fresh()->status)->toBe(AccountingVoucherStatus::Reversed);
});

test('closed period rejects posting', function () {
    $ctx = tripWorkspace(['broker'], ['accounting']);
    app(ChartOfAccountsBootstrapService::class)->ensureDefaults($ctx['company']);
    $period = app(AccountingPeriodService::class)->ensureCurrentFinancialYear($ctx['company']);

    app(AccountingPeriodService::class)->close($period, $ctx['member'], 'Year closed for test');

    $cash = GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Cash')->firstOrFail();
    $capital = GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Capital')->firstOrFail();

    expect(fn () => app(VoucherPostingService::class)->postManual($ctx['company'], $ctx['member'], [
        'voucher_type' => AccountingVoucherType::Journal,
        'voucher_date' => now()->toDateString(),
    ], [
        ['gl_ledger_id' => $cash->id, 'debit_amount' => '10.00', 'credit_amount' => 0],
        ['gl_ledger_id' => $capital->id, 'debit_amount' => 0, 'credit_amount' => '10.00'],
    ], $ctx['branch']))->toThrow(AccountingDomainException::class);

    app(AccountingPeriodService::class)->reopen($period->fresh(), $ctx['member'], 'Reopen for correction');

    $voucher = app(VoucherPostingService::class)->postManual($ctx['company'], $ctx['member'], [
        'voucher_type' => AccountingVoucherType::Journal,
        'voucher_date' => now()->toDateString(),
        'source_type' => 'test.reopen',
        'source_id' => 'reopen-1',
        'posting_purpose' => 'after_reopen',
    ], [
        ['gl_ledger_id' => $cash->id, 'debit_amount' => '10.00', 'credit_amount' => 0],
        ['gl_ledger_id' => $capital->id, 'debit_amount' => 0, 'credit_amount' => '10.00'],
    ], $ctx['branch']);

    expect((string) $voucher->total_debit)->toBe('10.00');
});
