<?php

use App\Domains\Accounting\ChartOfAccountsBootstrapService;
use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Accounting\OpeningBalanceService;
use App\Domains\Rbac\PermissionCodes;
use App\Models\AccountingPostingLink;
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

test('opening balance dry-run and post are balanced and idempotent', function () {
    $ctx = tripWorkspace(['broker']);
    tripGrant('customer.admin', PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE);

    app(ChartOfAccountsBootstrapService::class)->ensureDefaults($ctx['company']);
    $cash = GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Cash')->firstOrFail();
    $capital = GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Capital')->firstOrFail();

    $service = app(OpeningBalanceService::class);

    $preview = $service->dryRun($ctx['company'], [
        ['gl_ledger_id' => $cash->id, 'debit_amount' => '7500.00'],
        ['gl_ledger_id' => $capital->id, 'credit_amount' => '7500.00'],
    ]);

    expect($preview['balanced'])->toBeTrue()
        ->and($preview['total_debit'])->toBe('7500.00');

    expect($service->dryRun($ctx['company'], [
        ['gl_ledger_id' => $cash->id, 'debit_amount' => '100.00'],
        ['gl_ledger_id' => $capital->id, 'credit_amount' => '50.00'],
    ])['balanced'])->toBeFalse();

    $voucher = $service->post($ctx['company'], $ctx['member'], [
        ['gl_ledger_id' => $cash->id, 'debit_amount' => '7500.00'],
        ['gl_ledger_id' => $capital->id, 'credit_amount' => '7500.00'],
    ], $ctx['branch'], cutoverKey: 'fy-cutover');

    expect($voucher->voucher_type)->toBe(AccountingVoucherType::Opening)
        ->and((string) $voucher->total_debit)->toBe('7500.00');

    $again = $service->post($ctx['company'], $ctx['member'], [
        ['gl_ledger_id' => $cash->id, 'debit_amount' => '7500.00'],
        ['gl_ledger_id' => $capital->id, 'credit_amount' => '7500.00'],
    ], $ctx['branch'], cutoverKey: 'fy-cutover');

    expect($again->id)->toBe($voucher->id)
        ->and(
            AccountingPostingLink::query()
                ->where('source_type', 'accounting.opening_balance')
                ->where('posting_purpose', 'opening_balance')
                ->count(),
        )->toBe(1);

    expect(fn () => $service->post($ctx['company'], $ctx['member'], [
        ['gl_ledger_id' => $cash->id, 'debit_amount' => '100.00'],
        ['gl_ledger_id' => $capital->id, 'credit_amount' => '50.00'],
    ], $ctx['branch'], cutoverKey: 'bad'))->toThrow(ValidationException::class);
});
