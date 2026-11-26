<?php

use App\Domains\Accounting\AccountingWorkspaceService;
use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Accounting\VoucherPostingService;
use App\Domains\Rbac\PermissionCodes;
use App\Models\GlLedger;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Cache;

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

test('profit and loss and balance sheet reconcile from posted vouchers', function () {
    $ctx = tripWorkspace(['broker'], ['accounting']);
    foreach ([
        PermissionCodes::BRANCH_ACCOUNTING_VIEW,
        PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE,
        PermissionCodes::BRANCH_ACCOUNTING_REPORT_VIEW,
    ] as $code) {
        tripGrant('customer.admin', $code);
    }

    $workspace = app(AccountingWorkspaceService::class);
    $workspace->bootstrap($ctx['company']);

    $cash = GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Cash')->firstOrFail();
    $capital = GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Capital')->firstOrFail();
    $freight = GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Freight Income')->firstOrFail();
    $diesel = GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Diesel Expenses')->firstOrFail();

    $posting = app(VoucherPostingService::class);

    $posting->postManual($ctx['company'], $ctx['member'], [
        'voucher_type' => AccountingVoucherType::Journal,
        'voucher_date' => now()->toDateString(),
        'narration' => 'Capital introduced',
        'source_type' => 'test.statements',
        'source_id' => 'cap-1',
        'posting_purpose' => 'capital',
        'branch_id' => $ctx['branch']->id,
    ], [
        ['gl_ledger_id' => $cash->id, 'debit_amount' => '10000.00', 'credit_amount' => 0],
        ['gl_ledger_id' => $capital->id, 'debit_amount' => 0, 'credit_amount' => '10000.00'],
    ], $ctx['branch']);

    $posting->postManual($ctx['company'], $ctx['member'], [
        'voucher_type' => AccountingVoucherType::Sales,
        'voucher_date' => now()->toDateString(),
        'narration' => 'Freight billed',
        'source_type' => 'test.statements',
        'source_id' => 'sales-1',
        'posting_purpose' => 'freight',
        'branch_id' => $ctx['branch']->id,
    ], [
        ['gl_ledger_id' => $cash->id, 'debit_amount' => '5000.00', 'credit_amount' => 0],
        ['gl_ledger_id' => $freight->id, 'debit_amount' => 0, 'credit_amount' => '5000.00'],
    ], $ctx['branch']);

    $posting->postManual($ctx['company'], $ctx['member'], [
        'voucher_type' => AccountingVoucherType::Payment,
        'voucher_date' => now()->toDateString(),
        'narration' => 'Diesel paid',
        'source_type' => 'test.statements',
        'source_id' => 'exp-1',
        'posting_purpose' => 'diesel',
        'branch_id' => $ctx['branch']->id,
    ], [
        ['gl_ledger_id' => $diesel->id, 'debit_amount' => '1200.00', 'credit_amount' => 0],
        ['gl_ledger_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => '1200.00'],
    ], $ctx['branch']);

    $pnl = $workspace->profitAndLoss($ctx['company'], null, null, $ctx['branch']);
    expect($pnl['total_income'])->toBe('5000.00')
        ->and($pnl['total_expenses'])->toBe('1200.00')
        ->and($pnl['net_profit'])->toBe('3800.00');

    $sheet = $workspace->balanceSheet($ctx['company'], null, $ctx['branch']);
    expect($sheet['balanced'])->toBeTrue()
        ->and($sheet['total_assets'])->toBe('13800.00')
        ->and($sheet['net_profit'])->toBe('3800.00');

    $outstanding = $workspace->outstandingControlAccounts($ctx['company'], null, $ctx['branch']);
    expect($outstanding)->toBeArray();
});
