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

test('workspace day book ledger statement and trial balance reconcile after manual voucher', function () {
    $ctx = tripWorkspace(['broker']);
    foreach ([
        PermissionCodes::BRANCH_ACCOUNTING_VIEW,
        PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE,
        PermissionCodes::BRANCH_ACCOUNTING_REPORT_VIEW,
    ] as $code) {
        tripGrant('customer.admin', $code);
    }

    $workspace = app(AccountingWorkspaceService::class);
    $bootstrap = $workspace->bootstrap($ctx['company']);
    expect($bootstrap['period_label'])->not->toBeEmpty();

    $cash = GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Cash')->firstOrFail();
    $capital = GlLedger::query()->where('company_id', $ctx['company']->id)->where('name', 'Capital')->firstOrFail();

    app(VoucherPostingService::class)->postManual($ctx['company'], $ctx['member'], [
        'voucher_type' => AccountingVoucherType::Journal,
        'voucher_date' => now()->toDateString(),
        'narration' => 'Workspace opening',
        'source_type' => 'test.workspace',
        'source_id' => 'ws-1',
        'posting_purpose' => 'opening',
        'branch_id' => $ctx['branch']->id,
    ], [
        ['gl_ledger_id' => $cash->id, 'debit_amount' => '2500.00', 'credit_amount' => 0],
        ['gl_ledger_id' => $capital->id, 'debit_amount' => 0, 'credit_amount' => '2500.00'],
    ], $ctx['branch']);

    $dayBook = $workspace->dayBook($ctx['company'], $ctx['branch']);
    expect($dayBook->total())->toBe(1);

    $cashStatement = $workspace->ledgerStatement($ctx['company'], $cash, null, null, $ctx['branch']);
    expect($cashStatement['closing_debit'])->toBe('2500.00')
        ->and($cashStatement['rows'])->toHaveCount(1);

    $trial = $workspace->trialBalance($ctx['company'], null, null, $ctx['branch']);
    $closingDebit = '0.00';
    $closingCredit = '0.00';
    foreach ($trial as $row) {
        $closingDebit = bcadd($closingDebit, $row['closing_debit'], 2);
        $closingCredit = bcadd($closingCredit, $row['closing_credit'], 2);
    }

    expect($closingDebit)->toBe($closingCredit)
        ->and($closingDebit)->toBe('2500.00');
});
