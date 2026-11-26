<?php

use App\Domains\Accounting\Enums\AccountingVoucherType;
use App\Domains\Accounting\FundTransferService;
use App\Domains\Rbac\PermissionCodes;
use App\Models\AccountingPostingLink;
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

test('cash to bank contra transfer posts balanced voucher idempotently', function () {
    $ctx = tripWorkspace(['broker'], ['accounting']);
    tripGrant('customer.admin', PermissionCodes::BRANCH_ACCOUNTING_VOUCHER_CREATE);

    $service = app(FundTransferService::class);
    $voucher = $service->transfer($ctx['company'], $ctx['member'], [
        'from_ledger' => 'cash',
        'to_ledger' => 'bank',
        'amount' => '2500.00',
        'idempotency_key' => 'xfer-1',
    ], $ctx['branch']);

    expect($voucher->voucher_type)->toBe(AccountingVoucherType::Contra)
        ->and((string) $voucher->total_debit)->toBe('2500.00')
        ->and((string) $voucher->total_credit)->toBe('2500.00');

    $again = $service->transfer($ctx['company'], $ctx['member'], [
        'from_ledger' => 'cash',
        'to_ledger' => 'bank',
        'amount' => '2500.00',
        'idempotency_key' => 'xfer-1',
    ], $ctx['branch']);

    expect($again->id)->toBe($voucher->id)
        ->and(
            AccountingPostingLink::query()
                ->where('source_type', 'accounting.fund_transfer')
                ->where('posting_purpose', 'fund_transfer')
                ->count(),
        )->toBe(1);
});
